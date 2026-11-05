<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

class CalendarConflictService
{
    public function __construct(private GoogleCalendar $google, private CalendarAvailability $availability, private NotificationService $notifications) {}

    public function key(Reservation $reservation, array $event): string
    {
        $signature = hash('sha256', json_encode([$event['id'], $event['start'], $event['end'], $reservation->starts_at->timestamp, $reservation->ends_at->timestamp]));
        return "family-overlap:{$reservation->id}:{$reservation->user_id}:$signature";
    }

    public function run(?array $events = null): int
    {
        if (!$this->google->configured()) return 0;
        $start = CarbonImmutable::now()->startOfMinute();
        $end = $start->addDays(91);
        $events ??= $this->google->events($start, $end, true);
        $count = 0;
        foreach (Reservation::with('user')->whereIn('status', ['booked', 'running'])->where('ends_at', '>', now())->where('starts_at', '<', $end)->get() as $reservation) {
            foreach ($this->availability->conflicts($events, $reservation->starts_at, $reservation->ends_at) as $event) {
                $timezone = \App\Models\HouseholdSetting::current()->timezone;
                $title = mb_substr(strip_tags($event['summary'] ?? 'Family event'), 0, 120);
                $this->notifications->send($reservation->user, 'family_overlap', 'A family event overlaps your shower',
                    $reservation->starts_at->setTimezone($timezone)->format('D, M j · g:i A T')." — $title. Open Calendar to review or move your shower.", $this->key($reservation, $event));
                $count++;
            }
        }
        return $count;
    }

    public function stillOverlaps(int $userId, string $key): bool
    {
        if (!$this->google->configured() || !preg_match('/^family-overlap:(\d+):(\d+):[a-f0-9]{64}$/', $key, $match) || (int) $match[2] !== $userId) return false;
        $reservation = Reservation::whereKey($match[1])->where('user_id', $userId)->whereIn('status', ['booked', 'running'])->where('ends_at', '>', now())->first();
        if (!$reservation) return false;
        $events = $this->availability->forDay($reservation->day, true);
        foreach ($this->availability->conflicts($events, $reservation->starts_at, $reservation->ends_at) as $event) {
            if ($this->key($reservation, $event) === $key) return true;
        }
        return false;
    }
}
