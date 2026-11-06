<?php

namespace App\Livewire;

use App\Exceptions\CalendarException;
use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Services\CalendarAvailability;
use App\Services\CalendarSyncService;
use App\Services\FamilyCalendar;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Calendar extends Component
{
    #[Url] public string $day = '';
    #[Locked] public ?string $eventId = null;
    #[Locked] public string $etag = '';
    #[Locked] public string $creationId = '';
    #[Locked] public bool $recurring = false;
    public bool $formOpen = false;
    public bool $allDay = false;
    public bool $busy = true;
    public string $title = '';
    public string $location = '';
    public string $description = '';
    public string $startsAt = '';
    public string $endsAt = '';
    public string $notice = '';

    public function mount(): void { $this->normalizeDay(); }

    private function normalizeDay(): void
    {
        $today = CarbonImmutable::now(HouseholdSetting::current()->timezone);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->day, $today->timezone);
            if (!$date || $date->toDateString() !== $this->day || $date->lt($today->subYear()->startOfYear()) || $date->gt($today->addYears(2)->endOfYear())) throw new \RuntimeException;
        } catch (\Throwable) { $this->day = $today->toDateString(); }
    }

    public function selectDay(string $day): void { $this->day = $day; $this->normalizeDay(); $this->dispatch('calendar-day-selected'); }
    public function today(): void { $this->day = CarbonImmutable::now(HouseholdSetting::current()->timezone)->toDateString(); }
    public function navigate(int $months): void
    {
        $this->normalizeDay();
        $this->day = CarbonImmutable::parse($this->day)->startOfMonth()->addMonths(max(-1, min(1, $months)))->toDateString();
        $this->normalizeDay();
    }

    public function refreshCalendar(): void
    {
        app(GoogleCalendar::class)->invalidate();
    }

    public function newEvent(): void
    {
        Gate::authorize('admin');
        $this->normalizeDay();
        $this->reset('eventId', 'etag', 'recurring', 'title', 'location', 'description', 'allDay', 'busy', 'notice');
        $this->creationId = str_replace('-', '', (string) Str::uuid());
        $this->startsAt = $this->day.'T17:00'; $this->endsAt = $this->day.'T18:00';
        $this->resetErrorBag(); $this->formOpen = true;
        $this->dispatch('calendar-editor-opened');
    }

    public function updatedAllDay(): void
    {
        if ($this->allDay) {
            $this->startsAt = substr($this->startsAt, 0, 10); $this->endsAt = substr($this->endsAt, 0, 10);
        } else {
            $this->startsAt = substr($this->startsAt, 0, 10).'T17:00'; $this->endsAt = substr($this->endsAt, 0, 10).'T18:00';
        }
    }

    public function editEvent(string $id): void
    {
        Gate::authorize('admin');
        $this->attempt(function () use ($id) {
            $event = app(FamilyCalendar::class)->editable(auth()->user(), $id);
            $interval = app(GoogleCalendar::class)->interval($event);
            if (!$interval) throw new CalendarException('This event does not have editable start and end times.');
            [$start, $end, $allDay] = $interval;
            $timezone = HouseholdSetting::current()->timezone;
            $this->eventId = $id; $this->etag = $event['etag'];
            $this->allDay = $allDay; $this->recurring = !empty($event['recurringEventId']);
            $this->startsAt = $start->setTimezone($timezone)->format($allDay ? 'Y-m-d' : 'Y-m-d\TH:i');
            $this->endsAt = ($allDay ? $end->subDay() : $end)->setTimezone($timezone)->format($allDay ? 'Y-m-d' : 'Y-m-d\TH:i');
            $this->title = $event['summary'] ?? '';
            $this->location = $event['location'] ?? '';
            $this->description = strip_tags($event['description'] ?? '');
            $this->busy = ($event['transparency'] ?? 'opaque') !== 'transparent';
            $this->formOpen = true;
            $this->dispatch('calendar-editor-opened');
        });
    }

    public function saveEvent(): void
    {
        Gate::authorize('admin');
        $this->attempt(function () {
            app(FamilyCalendar::class)->save(auth()->user(), $this->only(['title', 'location', 'description', 'allDay', 'busy', 'startsAt', 'endsAt']), $this->creationId, $this->eventId, $this->etag);
            $this->day = substr($this->startsAt, 0, 10);
            $this->formOpen = false;
            $this->notice = 'Family event saved to Google Calendar. Household notifications and overlap alerts will be checked within about a minute.';
        });
    }

    public function deleteEvent(): void
    {
        Gate::authorize('admin');
        $this->attempt(function () {
            if (!$this->eventId) throw new CalendarException('Open an existing family event first.');
            app(FamilyCalendar::class)->delete(auth()->user(), $this->eventId, $this->etag);
            $this->formOpen = false; $this->notice = 'Family event removed from Google Calendar.';
        });
    }

    public function syncNow(): void
    {
        Gate::authorize('admin');
        $this->attempt(function () {
            if (!RateLimiter::attempt('google-calendar:manual-sync', 1, function () {
                if (!app(GoogleCalendar::class)->configured()) throw new CalendarException('Connect Google Calendar before syncing.');
                app(CalendarSyncService::class)->run();
            }, 60)) throw new CalendarException('The calendar was just checked. Try again in a minute.');
            $this->notice = 'Calendar synced. New family events announced and overlaps checked.';
            $this->dispatch('notifications-updated');
        });
    }

    private function attempt(callable $callback): void
    {
        $this->notice = ''; $this->resetErrorBag();
        try { $callback(); }
        catch (CalendarException $e) { $this->addError('calendar', $e->getMessage()); }
        catch (ValidationException $e) { $this->setErrorBag($e->errors()); }
    }

    public function render()
    {
        $this->normalizeDay();
        $settings = HouseholdSetting::current();
        $google = app(GoogleCalendar::class);
        $selected = CarbonImmutable::parse($this->day, $settings->timezone);
        $first = $selected->startOfMonth()->startOfWeek();
        $end = $selected->endOfMonth()->endOfWeek()->addDay()->startOfDay();
        $events = []; $calendarError = '';
        try { $events = $google->events($first, $end); }
        catch (CalendarException $e) { $calendarError = $e->getMessage(); }
        $showers = Reservation::active()->with('user')->where('starts_at', '<', $end->utc())->where('ends_at', '>', $first->utc())->orderBy('starts_at')->get();
        $items = [];
        foreach ($events as $event) {
            if ($google->isShower($event) && data_get($event, 'extendedProperties.private.showerqueue') === $google->instance()) continue;
            $interval = $google->interval($event);
            if (!$interval) continue;
            [$start, $finish, $allDay] = $interval;
            $items[] = ['key' => 'family-'.$event['id'], 'kind' => 'family', 'id' => $event['id'],
                'title' => strip_tags($event['summary'] ?? '(Untitled event)'), 'start' => $start->setTimezone($settings->timezone), 'end' => $finish->setTimezone($settings->timezone),
                'allDay' => $allDay, 'location' => $event['location'] ?? '', 'description' => strip_tags($event['description'] ?? ''),
                'blocks' => !$allDay && ($event['transparency'] ?? 'opaque') !== 'transparent' && config('google-calendar.block_busy'),
                'overlaps' => [], 'editable' => !$google->isShower($event) && ($event['eventType'] ?? 'default') === 'default' && empty($event['locked']),
            ];
        }
        foreach ($showers as $reservation) {
            $conflicts = in_array($reservation->status, ['booked', 'running']) ? app(CalendarAvailability::class)->conflicts($events, $reservation->starts_at, $reservation->ends_at) : [];
            $items[] = ['key' => 'shower-'.$reservation->id, 'kind' => 'shower', 'id' => $reservation->id,
                'title' => $reservation->user->display_name.' · Shower', 'start' => $reservation->starts_at->setTimezone($settings->timezone), 'end' => $reservation->ends_at->setTimezone($settings->timezone),
                'allDay' => false, 'location' => '', 'description' => '', 'blocks' => false, 'editable' => false,
                'overlaps' => array_map(fn ($event) => strip_tags($event['summary'] ?? 'Family event'), $conflicts),
            ];
        }
        $items = collect($items)->sortBy(fn ($event) => ($event['allDay'] ? '0' : '1').$event['start']->toIso8601String());
        $days = [];
        for ($date = $first; $date->lt($end); $date = $date->addDay()) {
            $dayItems = $items->filter(fn ($event) => $event['start']->lt($date->addDay()) && $event['end']->gt($date));
            $days[] = ['date' => $date, 'items' => $dayItems, 'familyCount' => $dayItems->where('kind', 'family')->count(), 'showerCount' => $dayItems->where('kind', 'shower')->count()];
        }
        $agenda = $items->filter(fn ($event) => $event['start']->lt($selected->addDay()) && $event['end']->gt($selected));
        $lastSync = Cache::get('google-calendar:last-sync:'.$google->calendarKey());
        return view('livewire.calendar', compact('settings', 'google', 'selected', 'days', 'agenda', 'calendarError', 'lastSync'))->layout('layouts.app', ['title' => 'Calendar']);
    }
}
