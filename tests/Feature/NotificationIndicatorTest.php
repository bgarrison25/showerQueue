<?php

namespace Tests\Feature;

use App\Livewire\Inbox;
use App\Livewire\NotificationLink;
use App\Models\User;
use App\Notifications\HouseholdNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user): void
    {
        $user->notifyNow(new HouseholdNotification('booked', 'Shower reserved', 'Your household schedule changed.', 'test-update'), ['database']);
    }

    public function test_indicator_picks_up_new_notifications_when_refreshed_without_reloading_the_page(): void
    {
        $user = User::factory()->create();
        $indicator = Livewire::actingAs($user)->test(NotificationLink::class)
            ->assertSee('no unread notifications')
            ->assertDontSeeHtml('class="notification-badge"');

        $this->notify($user);

        $indicator->call('$refresh')
            ->assertSee('1 unread notification')
            ->assertSeeHtml('class="notification-badge"');
    }

    public function test_marking_inbox_read_clears_markers_and_leaves_other_members_notifications_unread(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($user);
        $this->notify($other);

        $indicator = Livewire::actingAs($user)->test(NotificationLink::class)->assertSee('1 unread notification');
        Livewire::test(Inbox::class)->call('markRead')->assertDispatched('notifications-updated');

        $indicator->dispatch('notifications-updated')
            ->assertSee('no unread notifications')
            ->assertDontSeeHtml('class="notification-badge"');

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_inbox_navigation_stays_active_when_its_count_refreshes(): void
    {
        Livewire::actingAs(User::factory()->create())->test(NotificationLink::class, ['placement' => 'mobile', 'active' => true])
            ->call('$refresh')
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_first_login_does_not_poll_while_a_new_credential_is_required(): void
    {
        Livewire::actingAs(User::factory()->create(['must_change_password' => true]))->test(NotificationLink::class)
            ->assertDontSeeHtml('wire:poll.15s.visible');
    }
}
