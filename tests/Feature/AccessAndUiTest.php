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
        foreach (['/', '/?viewMode=week', '/swaps', '/inbox', '/preferences', '/household', '/password'] as $url) $this->get($url)->assertOk();
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
