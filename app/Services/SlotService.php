<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SlotService
{
    public function today(): string { return CarbonImmutable::now(HouseholdSetting::current()->timezone)->toDateString(); }

    public function slots(string $day, ?HouseholdSetting $settings = null): array
    {
        $settings ??= HouseholdSetting::current();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return [];
        try {
            $open = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$day {$settings->opens_at}", $settings->timezone);
            $close = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$day {$settings->closes_at}", $settings->timezone);
        } catch (\Throwable) { return []; }
        if ($open->toDateString() !== $day) return [];
        $slots = [];
        // Step on the UTC timeline. Repeated fall-back hours have distinct IDs;
        // nonexistent spring-forward times never appear.
        for ($start = $open->utc(); $start->addMinutes($settings->slot_minutes)->lte($close->utc()); $start = $start->addMinutes($settings->slot_minutes)) {
            $slots[] = ['start' => $start, 'end' => $start->addMinutes($settings->slot_minutes), 'local' => $start->setTimezone($settings->timezone)];
        }
        return $slots;
    }

    public function resolve(string $iso, HouseholdSetting $settings): array
    {
        try { $start = CarbonImmutable::parse($iso)->utc(); }
        catch (\Throwable) { throw ValidationException::withMessages(['booking' => 'Choose a valid shower time.']); }
        $day = $start->setTimezone($settings->timezone)->toDateString();
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        if ($start->isPast() || $start->setTimezone($settings->timezone)->startOfDay()->gt($today->addDays(90))) {
            throw ValidationException::withMessages(['booking' => 'Choose an upcoming time within the next 90 days.']);
        }
        foreach ($this->slots($day, $settings) as $slot) {
            if ($slot['start']->equalTo($start)) return [$slot['start'], $slot['end'], $day];
        }
        throw ValidationException::withMessages(['booking' => 'The schedule changed. Choose an available time again.']);
    }
}
