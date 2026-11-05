<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use Carbon\CarbonImmutable;

class CalendarAvailability
{
    public function __construct(private GoogleCalendar $google) {}

    public function forDay(string $day, bool $fresh = false): array
    {
        $start = CarbonImmutable::parse($day, HouseholdSetting::current()->timezone)->startOfDay();
        return $this->google->events($start, $start->addDay(), $fresh);
    }

    public function conflicts(array $events, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return array_values(array_filter($events, function ($event) use ($start, $end) {
            if ($this->google->isShower($event) || ($event['transparency'] ?? 'opaque') === 'transparent' || ($event['status'] ?? '') === 'cancelled') return false;
            $interval = $this->google->interval($event);
            return $interval && !$interval[2] && $interval[0]->lt($end) && $interval[1]->gt($start);
        }));
    }
}
