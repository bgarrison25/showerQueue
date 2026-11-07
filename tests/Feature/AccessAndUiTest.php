<?php

namespace Tests\Feature;

use App\Livewire\HouseholdAdmin;
use App\Livewire\Schedule;
use App\Livewire\Preferences;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccessAndUiTest extends TestCase
{
    use RefreshDatabase;
    public function test_unauthenticated_visitors_are_sent_to_login(): void { $this->get('/')->assertRedirect('/login'); $this->get('/household')->assertRedirect('/login'); }
    public function test_public_registration_does_not_exist(): void { $this->get('/register')->assertNotFound(); }
    public function test_livewire_update_endpoint_requires_an_active_authenticated_account(): void
    {
        $this->post('/livewire/update', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_active' => false]))->post('/livewire/update', [])->assertRedirect('/login');
    }
    public function test_children_cannot_open_or_call_parent_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/household')->assertForbidden();
        Livewire::test(HouseholdAdmin::class)->assertForbidden();
    }
    public function test_first_login_requires_replacing_generated_credentials(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => true]))->get('/')->assertRedirect('/password');
    }
    public function test_first_login_can_replace_temporary_password_and_unlock_navigation(): void
    {
        $temporary = bin2hex(random_bytes(16));
        $member = User::factory()->create(['name' => 'FirstLogin', 'password' => $temporary, 'must_change_password' => true]);
        $this->post('/login', ['name' => $member->name, 'password' => $temporary])->assertRedirect('/');
        $this->get('/')->assertRedirect('/password');
        $this->get('/password')->assertOk()->assertSee('Temporary password')->assertDontSee('Main navigation')->assertDontSee('Mobile navigation');
        $pin = (string) random_int(100000, 999999);
        $this->put('/password', ['current_password' => $temporary, 'password' => $pin, 'password_confirmation' => $pin])->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertFalse($member->fresh()->must_change_password);
        $this->assertTrue(Hash::check($pin, $member->fresh()->password));
        $this->get('/')->assertOk()->assertSee('Main navigation');
    }

    public function test_calendar_and_schedule_weeks_start_on_sunday(): void
    {
        config(['google-calendar.id' => '', 'google-calendar.credentials' => '']);
        $member = User::factory()->create();
        foreach (['2026-11-06' => ['2026-11-01', '2026-12-05'], '2026-08-15' => ['2026-07-26', '2026-09-05']] as $day => [$first, $last]) {
            Livewire::actingAs($member)->test(\App\Livewire\Calendar::class, ['day' => $day])
                ->assertViewHas('days', fn ($days) => $days[0]['date']->toDateString() === $first && end($days)['date']->toDateString() === $last && count($days) % 7 === 0);
        }
        Livewire::actingAs($member)->test(Schedule::class, ['day' => '2026-11-08'])
            ->assertViewHas('week', fn ($week) => $week->first()['date']->toDateString() === '2026-11-08' && $week->last()['date']->toDateString() === '2026-11-14');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) $this->post('/login', ['name' => 'Nobody', 'password' => 'incorrect'])->assertSessionHasErrors();
        $this->post('/login', ['name' => 'Nobody', 'password' => 'incorrect'])->assertSessionHasErrors('credential');
        $this->assertStringContainsString('Too many attempts.', session('errors')->first('credential'));
    }
    public function test_passwords_and_pins_are_hashed_and_short_pins_are_rejected(): void
    {
        $temporary = bin2hex(random_bytes(16)); $user = User::factory()->create(['password' => $temporary, 'must_change_password' => true]);
        $this->actingAs($user)->put('/password', ['current_password' => $temporary, 'password' => '1234', 'password_confirmation' => '1234'])->assertSessionHasErrors('password');
        $pin = (string) random_int(100000, 999999);
        $this->put('/password', ['current_password' => $temporary, 'password' => $pin, 'password_confirmation' => $pin])->assertRedirect('/');
        $this->assertTrue(Hash::check($pin, $user->fresh()->password)); $this->assertFalse($user->fresh()->must_change_password);
    }
    public function test_all_signed_in_screens_render_for_a_parent(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['/', '/?viewMode=week', '/swaps', '/inbox', '/menu', '/profile', '/preferences', '/household', '/password'] as $url) $this->get($url)->assertOk();
    }
    public function test_navigation_groups_are_consistent_and_parent_tools_remain_restricted(): void
    {
        $this->get('/menu')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/menu')->assertOk()->assertSee('Daily plans')->assertSee('Your account')
            ->assertSee('Members')->assertDontSee('Manage household');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/menu')->assertOk()->assertSee('Manage household');
    }

    public function test_sidebar_marks_the_actual_page_and_mobile_does_not_call_settings_profile(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['/preferences' => 'Settings', '/profile' => 'Profile', '/household' => 'Manage household', '/inbox' => 'Inbox'] as $url => $label) {
            $html = $this->get($url)->assertOk()->getContent();
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $active = $xpath->query('//nav[@aria-label="Main navigation"]//a[@aria-current="page"]');
            $this->assertCount(1, $active);
            $this->assertStringContainsString($label, $active->item(0)->textContent);
            $this->assertSame(0, $xpath->query('//nav[@aria-label="Mobile navigation"]//a[@aria-current="page" and contains(@href,"/profile")]')->length);
        }
    }

    public function test_livewire_booking_and_move_updates_shared_database(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z')); $user = User::factory()->create();
        Livewire::actingAs($user)->test(Schedule::class)->call('book', '2026-11-09T11:00:00Z')->assertHasNoErrors()->assertSee('You’re all set');
        $this->assertDatabaseHas('reservations', ['user_id' => $user->id, 'day' => '2026-11-09']);
    }
    public function test_child_cannot_tamper_with_booking_owner(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z')); $a = User::factory()->create(); $b = User::factory()->create();
        Livewire::actingAs($a)->test(Schedule::class)->set('ownerId', $b->id)->call('book', '2026-11-09T11:00:00Z')->assertForbidden();
    }
    public function test_status_card_tracks_reserved_running_and_free_while_viewing_another_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $user = User::factory()->create(['name' => 'Liam']);
        $bookings = app(\App\Services\BookingService::class);
        $reservation = $bookings->book($user, $user->id, '2026-11-09T11:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-11-09T11:05:00Z'));

        $screen = Livewire::actingAs($user)->test(Schedule::class, ['day' => '2026-11-10'])
            ->assertSee('Shower reserved')->assertSee('Reserved for Liam');
        $bookings->start($user, $reservation->id);
        $screen->call('$refresh')->assertSee('Shower in use')->assertSee('Liam is showering');
        $bookings->finish($user, $reservation->id);
        $screen->call('$refresh')->assertSee('Shower is free')->assertDontSee('Shower in use');
    }
    public function test_parent_can_save_household_settings(): void
    {
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test(HouseholdAdmin::class)->set('settings.slot_minutes', 30)->call('saveSettings')->assertHasNoErrors();
        $this->assertDatabaseHas('household_settings', ['slot_minutes' => 30]);
    }
    public function test_invalid_timezone_is_a_validation_error_even_with_bookings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z')); $parent = User::factory()->create(['is_admin' => true]);
        app(\App\Services\BookingService::class)->book($parent, $parent->id, '2026-11-09T11:00:00Z');
        Livewire::actingAs($parent)->test(HouseholdAdmin::class)->set('settings.timezone', 'Invalid/Zone')->call('saveSettings')->assertHasErrors('settings.timezone');
    }
    public function test_notification_preferences_are_saved_for_current_user_only(): void
    {
        $a = User::factory()->create(); $b = User::factory()->create();
        Livewire::actingAs($a)->test(Preferences::class)->set('preferences.booked', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($a->fresh()->notification_preferences['booked']); $this->assertNull($b->fresh()->notification_preferences);
    }
    public function test_parents_can_request_a_swap_from_the_calendar_too(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z')); $parent = User::factory()->create(['is_admin' => true]); $child = User::factory()->create(['name' => 'Liam']);
        $bookings = app(\App\Services\BookingService::class);
        $bookings->book($parent, $parent->id, '2026-11-09T11:00:00Z'); $bookings->book($child, $child->id, '2026-11-09T11:20:00Z');
        Livewire::actingAs($parent)->test(Schedule::class)->assertSee('Request a swap with Liam');
    }
    public function test_setup_creates_exactly_six_accounts_once_with_no_known_passwords(): void
    {
        $this->artisan('showerqueue:setup')->assertSuccessful(); $this->assertSame(['Bill', 'Aleasha', 'Liam', 'Haydon', 'Elliana', 'Anabelle'], User::orderBy('id')->pluck('name')->all());
        $this->assertSame(2, User::where('is_admin', true)->count()); $this->assertSame(6, User::where('must_change_password', true)->count());
        $this->artisan('showerqueue:setup')->assertFailed(); $this->assertSame(6, User::count());
    }
    public function test_push_endpoint_rejects_internal_network_targets(): void
    {
        config(['webpush.vapid.public_key' => 'configured']);
        $this->actingAs(User::factory()->create())->postJson('/push-subscriptions', ['endpoint' => 'https://127.0.0.1/private', 'keys' => ['p256dh' => 'key', 'auth' => 'token']])->assertUnprocessable();
    }
}
