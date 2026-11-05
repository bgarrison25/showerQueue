<?php

namespace Tests\Feature;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;
    protected User $parent;
    protected User $liam;
    protected User $elliana;
    protected BookingService $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC'));
        $this->parent = User::factory()->create(['name' => 'Bill', 'is_admin' => true]);
        $this->liam = User::factory()->create(['name' => 'Liam']);
        $this->elliana = User::factory()->create(['name' => 'Elliana']);
        $this->bookings = app(BookingService::class);
    }
    private function reserve(User $user, string $time = '11:00', bool $override = false): Reservation { return $this->bookings->book($override ? $this->parent : $user, $user->id, "2026-11-09T$time:00Z", $override); }

    public function test_each_person_can_only_reserve_once_per_household_day(): void
    {
        $this->reserve($this->liam);
        $this->expectException(ValidationException::class);
        $this->reserve($this->liam, '11:20');
    }
    public function test_overlapping_bookings_are_rejected(): void
    {
        $this->reserve($this->liam);
        $this->expectException(ValidationException::class);
        $this->reserve($this->elliana);
    }
    public function test_partial_overlap_after_slot_configuration_changes_is_rejected(): void
    {
        $this->reserve($this->liam);
        HouseholdSetting::current()->update(['slot_minutes' => 15]);
        $this->expectException(ValidationException::class);
        $this->reserve($this->elliana, '11:15');
    }
    public function test_parent_can_override_daily_limit_but_never_overlap(): void
    {
        $this->reserve($this->liam);
        $extra = $this->reserve($this->liam, '11:20', true);
        $this->assertTrue($extra->is_override);
        $this->assertNull($extra->fresh()->daily_key);
        $this->expectException(ValidationException::class);
        $this->reserve($this->elliana, '11:20', true);
    }
    public function test_child_cannot_use_a_parent_override(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->bookings->book($this->liam, $this->liam->id, '2026-11-09T11:00:00Z', true);
    }
    public function test_child_cannot_book_for_another_member(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->bookings->book($this->liam, $this->elliana->id, '2026-11-09T11:00:00Z');
    }
    public function test_cancellation_frees_time_and_daily_limit(): void
    {
        $r = $this->reserve($this->liam); $this->bookings->cancel($this->liam, $r->id);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertDatabaseMissing('reservation_minutes', ['reservation_id' => $r->id]);
        $this->reserve($this->liam, '11:20'); $this->reserve($this->elliana);
        $this->assertSame(2, Reservation::active()->count());
    }
    public function test_child_cannot_cancel_another_members_reservation(): void
    {
        $r = $this->reserve($this->liam);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->bookings->cancel($this->elliana, $r->id);
    }
    public function test_failed_move_preserves_the_original_reservation_and_claims(): void
    {
        $a = $this->reserve($this->liam); $this->reserve($this->elliana, '11:20');
        try { $this->bookings->book($this->liam, $this->liam->id, '2026-11-09T11:20:00Z', replaceId: $a->id); $this->fail('Conflict expected'); }
        catch (ValidationException) { $this->assertSame('booked', $a->fresh()->status); $this->assertSame(20, DB::table('reservation_minutes')->where('reservation_id', $a->id)->count()); }
    }
    public function test_successful_move_is_atomic(): void
    {
        $r = $this->reserve($this->liam);
        $new = $this->bookings->book($this->liam, $this->liam->id, '2026-11-09T11:40:00Z', replaceId: $r->id);
        $this->assertSame('cancelled', $r->fresh()->status); $this->assertSame(1, Reservation::active()->count());
        $this->assertSame(20, DB::table('reservation_minutes')->where('reservation_id', $new->id)->count());
    }
    public function test_stale_move_after_parent_cancellation_gives_a_conflict_message(): void
    {
        $r = $this->reserve($this->liam); $this->bookings->cancel($this->parent, $r->id);
        $this->expectException(ValidationException::class);
        $this->bookings->book($this->liam, $this->liam->id, '2026-11-09T11:20:00Z', replaceId: $r->id);
    }
    public function test_database_generated_daily_key_prevents_duplicate_direct_inserts(): void
    {
        $this->reserve($this->liam);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Reservation::create(['user_id' => $this->liam->id, 'created_by' => $this->liam->id, 'day' => '2026-11-09', 'starts_at' => '2026-11-09 12:00:00', 'ends_at' => '2026-11-09 12:20:00']);
    }
    public function test_unaligned_or_past_times_are_rejected(): void
    {
        $this->expectException(ValidationException::class); $this->reserve($this->liam, '11:05');
    }
    public function test_paused_account_cannot_make_bookings(): void
    {
        $this->liam->forceFill(['is_active' => false])->save();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); $this->reserve($this->liam);
    }
    public function test_morning_limit_is_optional_and_parent_can_override_it(): void
    {
        HouseholdSetting::current()->update(['morning_limit' => 1]);
        $this->reserve($this->liam);
        try { $this->bookings->book($this->liam, $this->liam->id, '2026-11-10T11:00:00Z'); $this->fail(); } catch (ValidationException) {}
        $r = $this->bookings->book($this->parent, $this->liam->id, '2026-11-10T11:00:00Z', true);
        $this->assertTrue($r->is_override);
    }
    public function test_a_late_start_never_extends_the_next_reservation(): void
    {
        $r = $this->reserve($this->liam); $next = $this->reserve($this->elliana, '11:20');
        $this->travelTo(CarbonImmutable::parse('2026-11-09T11:15:00Z'));
        $this->bookings->start($this->liam, $r->id);
        $this->assertSame('2026-11-09 11:20:00', $r->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->bookings->finish($this->liam, $r->id);
        $this->assertSame('booked', $next->fresh()->status); $this->assertSame('finished', $r->fresh()->status);
    }
    public function test_timer_cannot_start_before_reserved_time(): void
    {
        $r = $this->reserve($this->liam); $this->expectException(ValidationException::class); $this->bookings->start($this->liam, $r->id);
    }
    public function test_parent_can_end_another_members_running_timer(): void
    {
        $r = $this->reserve($this->liam); $this->travelTo(CarbonImmutable::parse('2026-11-09T11:01:00Z'));
        $this->bookings->start($this->liam, $r->id); $this->bookings->finish($this->parent, $r->id);
        $this->assertSame('finished', $r->fresh()->status);
    }
}
