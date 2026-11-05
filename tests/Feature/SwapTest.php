<?php

namespace Tests\Feature;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\SwapRequest;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SwapTest extends TestCase
{
    use RefreshDatabase;
    private User $a; private User $b; private User $parent;
    private Reservation $ra; private Reservation $rb;
    private BookingService $bookings;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $this->a = User::factory()->create(); $this->b = User::factory()->create(); $this->parent = User::factory()->create(['is_admin' => true]);
        $this->bookings = app(BookingService::class);
        $this->ra = $this->bookings->book($this->a, $this->a->id, '2026-11-09T11:00:00Z');
        $this->rb = $this->bookings->book($this->b, $this->b->id, '2026-11-09T11:20:00Z');
    }
    private function request(): SwapRequest { return $this->bookings->requestSwap($this->a, $this->ra->id, $this->rb->id); }
    public function test_acceptance_exchanges_owners_without_changing_time_claims(): void
    {
        $s = $this->request(); $claims = DB::table('reservation_minutes')->orderBy('minute')->get()->toArray();
        $this->bookings->respondSwap($this->b, $s->id, true);
        $this->assertSame($this->b->id, $this->ra->fresh()->user_id); $this->assertSame($this->a->id, $this->rb->fresh()->user_id);
        $this->assertEquals($claims, DB::table('reservation_minutes')->orderBy('minute')->get()->toArray()); $this->assertSame('accepted', $s->fresh()->status);
    }
    public function test_rejection_keeps_both_original_reservations(): void
    {
        $s = $this->request(); $this->bookings->respondSwap($this->b, $s->id, false);
        $this->assertSame($this->a->id, $this->ra->fresh()->user_id); $this->assertSame('declined', $s->fresh()->status);
    }
    public function test_requester_cannot_accept_their_own_request(): void
    {
        $s = $this->request(); $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); $this->bookings->respondSwap($this->a, $s->id, true);
    }
    public function test_parent_can_accept_without_the_recipient(): void
    {
        $s = $this->request(); $this->bookings->respondSwap($this->parent, $s->id, true); $this->assertSame('accepted', $s->fresh()->status);
    }
    public function test_changed_booking_invalidates_pending_swap(): void
    {
        $s = $this->request(); $this->bookings->cancel($this->a, $this->ra->id); $this->assertSame('expired', $s->fresh()->status);
    }
    public function test_expired_swap_cannot_be_accepted(): void
    {
        $s = $this->request(); $s->update(['expires_at' => now()->subMinute()]);
        $this->expectException(ValidationException::class); $this->bookings->respondSwap($this->b, $s->id, true);
    }
    public function test_extra_parent_override_cannot_break_daily_limit_via_swap(): void
    {
        $s = $this->request(); $this->bookings->book($this->parent, $this->a->id, '2026-11-09T11:40:00Z', true);
        try { $this->bookings->respondSwap($this->b, $s->id, true); $this->fail(); } catch (ValidationException) {}
        $this->assertSame($this->a->id, $this->ra->fresh()->user_id); $this->assertSame('pending', $s->fresh()->status);
    }
    public function test_fairness_and_absence_never_block_valid_swaps(): void
    {
        $s = $this->request(); HouseholdSetting::current()->update(['morning_limit' => 1, 'restrict_absent' => true]);
        DB::table('attendance_overrides')->insert(['user_id' => $this->a->id, 'day' => '2026-11-09', 'is_home' => false]);
        $this->bookings->respondSwap($this->b, $s->id, true); $this->assertSame('accepted', $s->fresh()->status);
    }
    public function test_duplicate_pending_requests_are_rejected(): void
    {
        $this->request(); $this->expectException(ValidationException::class); $this->request();
    }
}
