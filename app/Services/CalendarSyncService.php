<?php

namespace App\Services;

use App\Exceptions\CalendarException;
use App\Models\CalendarExport;
use App\Models\HouseholdSetting;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CalendarSyncService
{
    public function __construct(private GoogleCalendar $google) {}

    public function run(): int
    {
        if (!$this->google->configured()) return 0;
        return Cache::lock('google-calendar:sync:'.$this->google->calendarKey(), 600)->get(function () {
            $count = 0;
            // Include old exports so cancellations and changes cannot be stranded by the rolling window.
            $exportedIds = CalendarExport::where('calendar_key', $this->google->calendarKey())->where('fingerprint', '<>', 'deleted')->pluck('reservation_id');
            $reservations = Reservation::with('user')->where(function ($query) use ($exportedIds) {
                $query->whereBetween('ends_at', [now()->subDay(), now()->addDays(92)])->orWhereIn('id', $exportedIds);
            })->orderBy('id')->get();
            foreach ($reservations as $reservation) {
                // Completed historical exports need no ongoing reconciliation.
                if ($reservation->ends_at->lt(now()->subDay()) && $reservation->status !== 'cancelled') continue;
                $this->sync($reservation); $count++;
            }
            $start = CarbonImmutable::now()->startOfMinute();
            $events = $this->google->events($start, $start->addDays(91), true);
            app(CalendarEventNotificationService::class)->announce($events);
            app(CalendarConflictService::class)->run($events);
            Cache::put('google-calendar:last-sync:'.$this->google->calendarKey(), now()->toIso8601String(), now()->addDays(7));
            return $count;
        }) ?? 0;
    }

    private function sync(Reservation $reservation): void
    {
        $export = CalendarExport::firstOrCreate(['reservation_id' => $reservation->id, 'calendar_key' => $this->google->calendarKey()], ['event_id' => $this->newId()]);
        if ($reservation->status === 'cancelled') {
            if ($export->fingerprint !== 'deleted') {
                $this->google->delete($export->event_id);
                $export->update(['fingerprint' => 'deleted', 'synced_at' => now()]);
                $this->google->invalidate();
            }
            return;
        }
        $timezone = HouseholdSetting::current()->timezone;
        $payload = [
            'summary' => $reservation->user->name.' · Shower',
            'description' => "Managed by ShowerQueue. Change, cancel, or swap this shower in the app so household booking rules stay enforced.\n".rtrim(config('app.url'), '/').'/?day='.$reservation->day,
            'start' => ['dateTime' => $reservation->starts_at->toRfc3339String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $reservation->ends_at->toRfc3339String(), 'timeZone' => $timezone],
            'colorId' => '7', 'transparency' => 'opaque',
            'reminders' => ['useDefault' => false],
            'extendedProperties' => ['private' => ['showerqueue' => $this->google->instance(), 'reservation' => (string) $reservation->id]],
        ];
        $fingerprint = hash('sha256', json_encode($payload));
        if ($export->fingerprint === $fingerprint && $export->synced_at?->gt(now()->subHours(6))) return;

        $event = $export->fingerprint ? $this->google->get($export->event_id) : null;
        if (!$event) {
            // Deleted Google IDs can remain tombstoned; replace those with a new UUID.
            if ($export->fingerprint) $export->update(['event_id' => $this->newId(), 'fingerprint' => null]);
            $event = $this->google->insert($payload + ['id' => $export->event_id]);
        }
        if (data_get($event, 'extendedProperties.private.showerqueue') !== $this->google->instance() || data_get($event, 'extendedProperties.private.reservation') !== (string) $reservation->id) {
            throw new CalendarException('A shower export has an unexpected event owner. Check the Google Calendar connection.');
        }
        // Patch preserves Google metadata, and If-Match prevents overwriting a concurrent edit.
        $this->google->update($export->event_id, $payload, $event['etag']);
        $export->update(['fingerprint' => $fingerprint, 'synced_at' => now()]);
        $this->google->invalidate();
    }

    private function newId(): string
    {
        return str_replace('-', '', (string) Str::uuid());
    }
}
