<?php

namespace Tests\Feature;

use App\Models\HouseholdSetting;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\BookingService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceAndTimeTest extends TestCase
{
    use RefreshDatabase;
    public function test_alternating_weekends_work_in_both_directions_across_dst(): void
    {
        HouseholdSetting::current()->update(['weekend_reference' => '2026-11-06']);
        $child = User::factory()->create(); $service = app(AttendanceService::class);
        foreach (['2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-20', '2026-10-23', '2026-10-25'] as $day) $this->assertTrue($service->isHome($child, $day), $day);
        foreach (['2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-10-30', '2026-11-01'] as $day) $this->assertFalse($service->isHome($child, $day), $day);
    }
    public function test_adults_are_home_daily_unless_a_parent_sets_an_exception(): void
    {
        $parent = User::factory()->create(['is_admin' => true]); $service = app(AttendanceService::class);
        $this->assertTrue($service->isHome($parent, '2026-11-11'));
        DB::table('attendance_overrides')->insert(['user_id' => $parent->id, 'day' => '2026-11-11', 'is_home' => false]);
        $this->assertFalse($service->isHome($parent, '2026-11-11'));
    }
    public function test_absence_is_informational_until_restricted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:00:00Z')); $child = User::factory()->create();
        $service = app(BookingService::class); $r = $service->book($child, $child->id, '2026-11-11T11:00:00Z');
        $service->cancel($child, $r->id); HouseholdSetting::current()->update(['restrict_absent' => true]);
        $this->expectException(ValidationException::class); $service->book($child, $child->id, '2026-11-11T11:20:00Z');
    }
    public function test_spring_forward_never_offers_nonexistent_times(): void
    {
        $s = HouseholdSetting::current(); $s->update(['opens_at' => '00:00', 'closes_at' => '04:00', 'slot_minutes' => 20]);
        $slots = app(SlotService::class)->slots('2027-03-14', $s);
        $this->assertCount(9, $slots); $this->assertFalse(collect($slots)->contains(fn ($slot) => $slot['local']->format('H') === '02'));
    }
    public function test_fall_back_hours_have_distinct_utc_identifiers(): void
    {
        $s = HouseholdSetting::current(); $s->update(['opens_at' => '00:00', 'closes_at' => '04:00', 'slot_minutes' => 20]);
        $slots = app(SlotService::class)->slots('2027-11-07', $s);
        $this->assertCount(15, $slots); $this->assertCount(15, array_unique(array_map(fn ($slot) => $slot['start']->timestamp, $slots)));
        $this->assertCount(6, array_filter($slots, fn ($slot) => $slot['local']->format('H') === '01'));
    }
    public function test_daily_limit_uses_local_day_instead_of_utc_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $s = HouseholdSetting::current(); $s->update(['opens_at' => '00:00', 'closes_at' => '23:59']);
        $user = User::factory()->create();
        $r = app(BookingService::class)->book($user, $user->id, '2026-11-10T03:00:00Z'); // Monday, 10 PM Toronto
        $this->assertSame('2026-11-09', $r->day);
        $this->expectException(ValidationException::class); app(BookingService::class)->book($user, $user->id, '2026-11-09T11:00:00Z');
    }
    public function test_next_local_day_in_same_utc_day_is_allowed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z')); HouseholdSetting::current()->update(['opens_at' => '00:00', 'closes_at' => '23:59']);
        $u = User::factory()->create(); $service = app(BookingService::class);
        $a = $service->book($u, $u->id, '2026-11-10T03:00:00Z'); $b = $service->book($u, $u->id, '2026-11-10T11:00:00Z');
        $this->assertNotSame($a->day, $b->day);
    }
}
