<?php

namespace Tests\Feature;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\RecurrenceService;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurrenceAndNotificationTest extends TestCase
{
    use RefreshDatabase;
    private User $parent; private User $child;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $this->parent = User::factory()->create(['is_admin' => true]); $this->child = User::factory()->create();
    }
    private function pattern(): array { return ['user_id' => $this->parent->id, 'weekdays' => [1], 'time' => '06:00', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-23']; }
    public function test_children_cannot_create_recurrences_even_for_themselves(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RecurrenceService::class)->save($this->child, $this->pattern());
    }
    public function test_recurrence_skips_conflicts_and_never_overwrites_another_booking(): void
    {
        $r = app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:00:00Z');
        $p = app(RecurrenceService::class)->save($this->parent, $this->pattern());
        $this->assertSame('booked', $r->fresh()->status);
        $this->assertDatabaseHas('recurrence_results', ['recurring_pattern_id' => $p->id, 'day' => '2026-11-09', 'result' => 'conflict']);
        $this->assertSame(2, Reservation::where('recurring_pattern_id', $p->id)->count());
        app(RecurrenceService::class)->materialize($p); $this->assertSame(2, Reservation::where('recurring_pattern_id', $p->id)->count());
    }
    public function test_recurrences_respect_daily_limits(): void
    {
        app(BookingService::class)->book($this->parent, $this->parent->id, '2026-11-09T11:20:00Z');
        $p = app(RecurrenceService::class)->save($this->parent, $this->pattern());
        $this->assertDatabaseHas('recurrence_results', ['recurring_pattern_id' => $p->id, 'day' => '2026-11-09', 'result' => 'conflict']);
    }
    public function test_removing_a_pattern_cancels_future_occurrences(): void
    {
        $p = app(RecurrenceService::class)->save($this->parent, $this->pattern());
        app(RecurrenceService::class)->remove($this->parent, $p->id);
        $this->assertFalse($p->fresh()->enabled); $this->assertSame(0, Reservation::active()->where('recurring_pattern_id', $p->id)->count());
    }
    public function test_modifying_a_pattern_rechecks_future_times(): void
    {
        $p = app(RecurrenceService::class)->save($this->parent, $this->pattern());
        $data = $this->pattern(); $data['time'] = '06:40';
        app(RecurrenceService::class)->save($this->parent, $data, $p->id);
        $this->assertSame(3, Reservation::active()->where('recurring_pattern_id', $p->id)->count());
        $this->assertSame('11:40', Reservation::active()->where('recurring_pattern_id', $p->id)->first()->starts_at->format('H:i'));
    }
    public function test_personal_preferences_override_household_defaults(): void
    {
        HouseholdSetting::current()->update(['notification_defaults' => ['booked' => false]]);
        $service = app(NotificationService::class); $service->send($this->child, 'booked', 'Test', 'Body', 'test1');
        $this->assertSame(0, $this->child->notifications()->count());
        $this->child->forceFill(['notification_preferences' => ['booked' => true]])->save();
        $service->send($this->child, 'booked', 'Test', 'Body', 'test2'); $this->assertSame(1, $this->child->notifications()->count());
    }
    public function test_repeated_scheduler_runs_never_duplicate_reminders(): void
    {
        $r = app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:56:00Z'));
        app(ReminderService::class)->run(); app(ReminderService::class)->run();
        $this->assertSame(1, DB::table('notification_deliveries')->where('event_key', "soon:{$r->id}:1")->count());
        $this->assertSame(1, $this->child->notifications()->get()->filter(fn ($n) => $n->data['kind'] === 'soon')->count());
    }
    public function test_disabled_reminders_are_not_delivered(): void
    {
        $this->child->forceFill(['notification_preferences' => ['soon' => false, 'next' => false]])->save();
        app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:56:00Z')); app(ReminderService::class)->run();
        $this->assertSame(0, $this->child->notifications()->get()->filter(fn ($n) => in_array($n->data['kind'], ['soon', 'next']))->count());
    }
    public function test_scheduler_finishes_elapsed_timer(): void
    {
        $r = app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-11-09T11:01:00Z')); app(BookingService::class)->start($this->child, $r->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-09T11:21:00Z')); app(ReminderService::class)->run(); $this->assertSame('finished', $r->fresh()->status);
    }
    public function test_cancelled_reservation_cannot_send_an_old_queued_push_reminder(): void
    {
        $r = app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:00:00Z');
        app(BookingService::class)->cancel($this->child, $r->id);
        \Illuminate\Support\Facades\Notification::fake();
        (new \App\Jobs\SendPush($this->child->id, 'soon', 'Reminder', 'Body', "soon:{$r->id}:1"))->handle(app(NotificationService::class));
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }
}
