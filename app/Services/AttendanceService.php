<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function isHome(User $user, string $day, ?HouseholdSetting $settings = null): bool
    {
        $override = DB::table('attendance_overrides')->where('user_id', $user->id)->where('day', $day)->first();
        if ($override) return (bool) $override->is_home;
        if ($user->is_admin) return true;
        $settings ??= HouseholdSetting::current();
        // Calendar arithmetic in UTC avoids 23/25-hour daylight-saving days.
        $date = CarbonImmutable::parse($day, 'UTC')->startOfDay();
        if (in_array($date->dayOfWeekIso, [1, 2])) return true;
        if (!in_array($date->dayOfWeekIso, [5, 6, 7])) return false;
        $reference = CarbonImmutable::parse($settings->weekend_reference, 'UTC')->startOfWeek()->addDays(4);
        $friday = $date->startOfWeek()->addDays(4);
        return ((int) round($reference->diffInDays($friday, false)) % 14) === 0;
    }
}
