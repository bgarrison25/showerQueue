<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CalendarEventNotificationService
{
    public function __construct(private GoogleCalendar $google, private NotificationService $notifications) {}

    public function announce(array $events): int
    {
        $timezone = HouseholdSetting::current()->timezone;
        $calendarKey = $this->google->calendarKey();
        $announced = 0;
        // Google returns expanded recurring instances; announce a series once,
        // describing its next occurrence rather than every date in the window.
        $events = collect($events)->sortBy(function ($event) {
            $interval = $this->google->interval($event);
            return $interval ? $interval[0]->timestamp : PHP_INT_MAX;
        });
        foreach ($events as $event) {
            if (empty($event['id']) || $this->google->isShower($event) || ($event['status'] ?? '') === 'cancelled') continue;
            $interval = $this->google->interval($event);
            if (!$interval || !$interval[1]->isFuture()) continue;
            [$start, $end, $allDay] = $interval;
            $start = $start->setTimezone($timezone); $end = $end->setTimezone($timezone);
            $eventKey = hash('sha256', $event['recurringEventId'] ?? $event['id']);
            $title = mb_substr(trim(strip_tags($event['summary'] ?? '')) ?: 'Family event', 0, 120);
            $when = $allDay ? $this->allDayLabel($start, $end) : $start->format('D, M j · g:i A').' – '.$end->format($end->isSameDay($start) ? 'g:i A T' : 'D, M j · g:i A T');
            $body = (!empty($event['recurringEventId']) ? 'Repeats · next: ' : '').$when;
            if (!empty($event['location'])) $body .= ' · '.mb_substr(strip_tags($event['location']), 0, 150);
            $inserted = DB::transaction(function () use ($calendarKey, $eventKey, $title, $body) {
                $inserted = DB::table('calendar_announcements')->insertOrIgnore([
                    'calendar_key' => $calendarKey, 'event_key' => $eventKey, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (!$inserted) return false;
                $this->notifications->household('family_event', 'New family event: '.$title, $body, "family-event:$calendarKey:$eventKey");
                return true;
            }, 5);
            if ($inserted) $announced++;
        }
        return $announced;
    }

    private function allDayLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $lastDay = $end->subDay();
        return $start->format('D, M j').($lastDay->isSameDay($start) ? '' : ' – '.$lastDay->format('D, M j')).' · All day';
    }
}
