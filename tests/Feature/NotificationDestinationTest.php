<?php

namespace Tests\Feature;

use App\Jobs\SendPush;
use App\Models\User;
use App\Notifications\HouseholdNotification;
use App\Services\BookingService;
use App\Services\NotificationDestination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_swap_notifications_link_to_the_request_even_with_the_old_url(): void
    {
        $user = User::factory()->create();
        $user->notifyNow(new HouseholdNotification('swap_requested', 'Bill wants to swap', 'Open Swaps.', 'swap-request:42'), ['database']);
        $notification = $user->notifications()->sole();
        $notification->update(['data' => array_replace($notification->data, ['url' => '/'])]);

        $this->actingAs($user)->get('/inbox')->assertOk()->assertSee('href="/swaps#swap-42"', false);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_reservation_and_reminder_links_include_the_day_even_after_cancellation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $user = User::factory()->create();
        $booking = app(BookingService::class)->book($user, $user->id, '2026-11-10T11:00:00Z');
        app(BookingService::class)->cancel($user, $booking->id);
        $resolver = app(NotificationDestination::class);
        foreach (['booked', 'changed', 'cancelled', 'soon', 'next'] as $prefix) {
            $this->assertSame('/?day=2026-11-10', $resolver->url(['kind' => $prefix, 'tag' => "$prefix:{$booking->id}:1", 'url' => '/']));
        }
        $this->assertSame('/calendar?day=2026-11-10#selected-day-events', $resolver->url(['kind' => 'family_overlap', 'tag' => "family-overlap:{$booking->id}:{$user->id}:hash"]));
        $this->actingAs($user)->get('/inbox')->assertSee('href="/?day=2026-11-10"', false);
        $this->get('/?day=2026-11-10')->assertOk()->assertSee('Tuesday’s schedule');
    }

    public function test_family_events_and_phone_push_share_the_calendar_day_destination(): void
    {
        $user = User::factory()->create();
        $notification = new HouseholdNotification('family_event', 'Dinner', 'Family dinner.', 'family-event:calendar:event', '2026-12-24');
        $user->notifyNow($notification, ['database']);
        $url = '/calendar?day=2026-12-24#selected-day-events';
        $this->assertSame($url, $notification->toWebPush($user, $notification)->toArray()['data']['url']);
        $this->actingAs($user)->get('/inbox')->assertSee('href="'.$url.'"', false);
        $this->assertSame('/swaps#swap-17', (new HouseholdNotification('swap_resolved', 'Accepted', 'Done', 'swap-accepted:17'))->toWebPush($user, $notification)->toArray()['data']['url']);
    }

    public function test_older_family_notifications_and_missing_records_have_safe_fallbacks(): void
    {
        $resolver = app(NotificationDestination::class);
        $this->assertSame('/calendar', $resolver->url(['kind' => 'family_event', 'url' => 'javascript:alert(1)']));
        $this->assertSame('/', $resolver->url(['kind' => 'booked', 'tag' => 'booked:999999', 'url' => 'https://example.com']));
        $this->assertSame('/calendar', $resolver->url(['kind' => 'family_event', 'day' => '2026-02-31']));
        $this->assertSame('/swaps', $resolver->url(['kind' => 'swap_requested', 'tag' => 'invalid']));
    }

    public function test_push_jobs_queued_before_the_update_remain_compatible(): void
    {
        $job = new SendPush(1, 'family_event', 'Event', 'Details', 'family-event:old');
        unset($job->day);
        $restored = unserialize(serialize($job));
        $this->assertNull($restored->day);
    }
}
