<?php

namespace Tests\Feature;

use App\Exceptions\CalendarException;
use App\Livewire\Calendar;
use App\Livewire\Schedule;
use App\Models\CalendarExport;
use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CalendarAvailability;
use App\Services\CalendarConflictService;
use App\Services\CalendarSyncService;
use App\Services\FamilyCalendar;
use App\Services\GoogleCalendar;
use App\Services\RecurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;
    private User $child;
    private array $events = [];
    private int $etag = 0;
    private int $listStatus = 200;
    private string $role = 'writer';
    private bool $insertResponseLost = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09T10:00:00Z'));
        $this->parent = User::factory()->create(['name' => 'Bill', 'is_admin' => true]);
        $this->child = User::factory()->create(['name' => 'Liam']);
        config(['google-calendar.id' => 'test@group.calendar.google.com', 'google-calendar.credentials' => 'test-credentials', 'google-calendar.block_busy' => true]);
        Cache::put('google-calendar:token:'.hash('sha256', 'test-credentials'), 'test-token', 3300);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->assertStringStartsWith('/calendar/v3/calendars/', $path);
            $id = str_contains($path, '/events/') ? rawurldecode(explode('/events/', $path, 2)[1]) : null;
            if ($request->method() === 'GET' && !$id) return Http::response(['accessRole' => $this->role, 'items' => array_values($this->events)], $this->listStatus);
            if ($request->method() === 'GET') return isset($this->events[$id]) && ($this->events[$id]['status'] ?? '') !== 'cancelled' ? Http::response($this->events[$id]) : Http::response([], 410);
            if ($request->method() === 'POST') {
                $data = $request->data(); $id = $data['id'];
                if (isset($this->events[$id])) return Http::response([], 409);
                $this->events[$id] = $data + ['etag' => 'etag-'.++$this->etag];
                if ($this->insertResponseLost) { $this->insertResponseLost = false; return Http::response([], 503); }
                return Http::response($this->events[$id], 201);
            }
            if (!isset($this->events[$id])) return Http::response([], 404);
            if ($request->hasHeader('If-Match') && $request->header('If-Match')[0] !== $this->events[$id]['etag']) return Http::response([], 412);
            if ($request->method() === 'DELETE') { $this->events[$id]['status'] = 'cancelled'; return Http::response(null, 204); }
            if ($request->method() === 'PATCH') {
                $this->events[$id] = array_replace_recursive($this->events[$id], $request->data(), ['etag' => 'etag-'.++$this->etag]);
                return Http::response($this->events[$id]);
            }
            throw new \RuntimeException('Unexpected Google request');
        });
    }

    private function family(string $id = 'family123', string $start = '2026-11-09T11:05:00Z', string $end = '2026-11-09T11:25:00Z', array $extra = []): array
    {
        return $this->events[$id] = array_replace_recursive(['id' => $id, 'etag' => 'etag-'.++$this->etag, 'summary' => 'School run', 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end]], $extra);
    }

    private function book(User $user, string $time = '11:00', bool $override = false): Reservation
    {
        return app(BookingService::class)->book($override ? $this->parent : $user, $user->id, "2026-11-09T$time:00Z", $override);
    }

    private function form(array $extra = []): array
    {
        return $extra + ['title' => 'Family dinner', 'location' => 'Home', 'description' => 'Bring dessert', 'allDay' => false, 'busy' => true, 'startsAt' => '2026-11-09T17:00', 'endsAt' => '2026-11-09T18:00'];
    }

    public function test_calendar_requires_household_access_and_renders_external_events(): void
    {
        $this->family();
        $this->get('/calendar')->assertRedirect('/login');
        $this->actingAs($this->parent)->get('/calendar')->assertOk()->assertSee('School run')->assertSee('Family event');
        $this->actingAs($this->child)->get('/calendar')->assertOk()->assertSee('School run')->assertDontSee('wire:click="newEvent"', false);
        $this->actingAs(User::factory()->create(['must_change_password' => true]))->get('/calendar')->assertRedirect('/password');
    }

    public function test_children_cannot_call_family_calendar_mutations(): void
    {
        foreach (['newEvent', 'saveEvent', 'deleteEvent', 'syncNow'] as $method) Livewire::actingAs($this->child)->test(Calendar::class)->call($method)->assertForbidden();
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_parent_can_add_edit_and_delete_a_family_event_from_the_calendar(): void
    {
        $page = Livewire::actingAs($this->parent)->test(Calendar::class)->call('newEvent')->set('title', 'Dinner together')->call('saveEvent')->assertHasNoErrors()->assertSee('Family event saved');
        $event = array_values($this->events)[0];
        $this->assertSame('2026-11-09T17:00:00-05:00', $event['start']['dateTime']);
        $this->assertSame('America/Toronto', $event['start']['timeZone']);
        $page->call('editEvent', $event['id'])->set('title', 'Dinner at home')->call('saveEvent')->assertHasNoErrors();
        $this->assertSame('Dinner at home', $this->events[$event['id']]['summary']);
        $page->call('editEvent', $event['id'])->call('deleteEvent')->assertHasNoErrors();
        $this->assertSame('cancelled', $this->events[$event['id']]['status']);
    }

    public function test_family_event_creation_retries_do_not_duplicate_a_lost_success_response(): void
    {
        $this->insertResponseLost = true;
        $page = Livewire::actingAs($this->parent)->test(Calendar::class)->call('newEvent')->set('title', 'Dinner')->call('saveEvent')->assertHasErrors('calendar')->assertSet('formOpen', true);
        $page->call('saveEvent')->assertHasNoErrors()->assertSet('formOpen', false);
        $this->assertCount(1, $this->events);
    }

    public function test_all_day_end_is_exclusive_and_is_not_a_booking_block(): void
    {
        $event = app(FamilyCalendar::class)->save($this->parent, $this->form(['allDay' => true, 'startsAt' => '2026-11-09', 'endsAt' => '2026-11-10']), str_repeat('a', 32));
        $this->assertSame('2026-11-11', $event['end']['date']);
        $this->assertSame([], app(CalendarAvailability::class)->conflicts([$event], CarbonImmutable::parse('2026-11-09T11:00Z'), CarbonImmutable::parse('2026-11-09T11:20Z')));
        $this->book($this->child);
    }

    public function test_invalid_intervals_and_nonexistent_dst_times_are_rejected(): void
    {
        foreach ([['endsAt' => '2026-11-09T16:00'], ['startsAt' => '2027-03-14T02:30', 'endsAt' => '2027-03-14T04:00']] as $extra) {
            try { app(FamilyCalendar::class)->save($this->parent, $this->form($extra), str_repeat('b', 32)); $this->fail('Invalid event was saved'); }
            catch (ValidationException) {}
        }
        Http::assertNothingSent();
    }

    public function test_concurrent_google_changes_are_not_overwritten_by_a_stale_editor(): void
    {
        $event = $this->family();
        $page = Livewire::actingAs($this->parent)->test(Calendar::class)->call('editEvent', $event['id']);
        $this->events[$event['id']]['etag'] = 'changed-in-google';
        $this->events[$event['id']]['summary'] = 'New Google title';
        $page->set('title', 'Stale title')->call('saveEvent')->assertHasErrors('calendar')->assertSee('Someone changed this event');
        $this->assertSame('New Google title', $this->events[$event['id']]['summary']);
        $page->call('deleteEvent')->assertHasErrors('calendar');
        $this->assertArrayNotHasKey('status', $this->events[$event['id']]);
    }

    public function test_editing_a_recurring_instance_preserves_metadata_and_changes_only_that_instance(): void
    {
        $event = $this->family(extra: ['recurringEventId' => 'series123', 'attendees' => [['email' => 'parent@example.test']]]);
        app(FamilyCalendar::class)->save($this->parent, $this->form(), '', $event['id'], $event['etag']);
        $this->assertSame('series123', $this->events[$event['id']]['recurringEventId']);
        $this->assertSame([['email' => 'parent@example.test']], $this->events[$event['id']]['attendees']);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/family123') && $request->header('If-Match')[0] === $event['etag']);
    }

    public function test_booking_checks_google_fresh_even_when_the_display_cache_is_old(): void
    {
        app(CalendarAvailability::class)->forDay('2026-11-09');
        $this->family();
        try { $this->book($this->child); $this->fail('Busy event should block the booking'); }
        catch (ValidationException $e) { $this->assertStringContainsString('busy family event', $e->getMessage()); }
        $this->assertDatabaseCount('reservations', 0);
        Livewire::actingAs($this->child)->test(Schedule::class)->assertSee('Family time')->assertSee('School run');
    }

    public function test_free_events_and_adjacent_events_do_not_block_showers(): void
    {
        $this->family(extra: ['transparency' => 'transparent']);
        $this->family('adjacent1', '2026-11-09T10:40:00Z', '2026-11-09T11:00:00Z');
        $this->family('adjacent2', '2026-11-09T11:20:00Z', '2026-11-09T11:40:00Z');
        $this->assertSame('booked', $this->book($this->child)->fresh()->status);
    }

    public function test_parent_can_override_a_family_event_but_cannot_overlap_a_shower(): void
    {
        $this->family();
        $this->book($this->child, override: true);
        $this->expectException(ValidationException::class);
        $this->book($this->parent, override: true);
    }

    public function test_failed_move_and_google_outage_preserve_the_original_booking(): void
    {
        $original = $this->book($this->child);
        $this->family(start: '2026-11-09T11:20:00Z', end: '2026-11-09T11:40:00Z');
        foreach ([200, 503] as $status) {
            $this->listStatus = $status;
            try { app(BookingService::class)->book($this->child, $this->child->id, '2026-11-09T11:20:00Z', replaceId: $original->id); $this->fail('Move should fail'); }
            catch (ValidationException) {}
            $this->assertSame('booked', $original->fresh()->status);
            $this->assertSame(20, DB::table('reservation_minutes')->where('reservation_id', $original->id)->count());
        }
        app(BookingService::class)->cancel($this->child, $original->id);
        $this->assertSame('cancelled', $original->fresh()->status);
    }

    public function test_blocking_can_be_disabled_without_disabling_family_events(): void
    {
        $this->family(); config(['google-calendar.block_busy' => false]);
        $this->book($this->child);
        $this->actingAs($this->child)->get('/calendar')->assertOk()->assertSee('School run');
    }

    public function test_recurring_shower_reports_family_event_conflicts(): void
    {
        $this->family();
        $pattern = app(RecurrenceService::class)->save($this->parent, ['user_id' => $this->child->id, 'weekdays' => [1], 'time' => '06:00', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09']);
        $this->assertDatabaseHas('recurrence_results', ['recurring_pattern_id' => $pattern->id, 'result' => 'conflict']);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_outage_does_not_cancel_existing_recurrences_when_editing_a_pattern(): void
    {
        $data = ['user_id' => $this->child->id, 'weekdays' => [1], 'time' => '06:00', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09'];
        $pattern = app(RecurrenceService::class)->save($this->parent, $data);
        $this->listStatus = 503;
        try { app(RecurrenceService::class)->save($this->parent, array_replace($data, ['time' => '07:00']), $pattern->id); $this->fail('Calendar outage should stop the edit'); }
        catch (ValidationException) {}
        $this->assertSame('06:00', $pattern->fresh()->time);
        $this->assertDatabaseHas('reservations', ['recurring_pattern_id' => $pattern->id, 'status' => 'booked']);
    }

    public function test_shower_exports_update_after_swaps_and_are_removed_after_moves_and_cancellation(): void
    {
        $other = User::factory()->create(['name' => 'Aleasha']);
        $a = $this->book($this->child); $b = $this->book($other, '11:20');
        app(CalendarSyncService::class)->run();
        $this->assertCount(2, $this->events);
        $aExport = CalendarExport::where('reservation_id', $a->id)->first();
        $swap = app(BookingService::class)->requestSwap($this->child, $a->id, $b->id);
        app(BookingService::class)->respondSwap($other, $swap->id, true);
        app(CalendarSyncService::class)->run();
        $this->assertSame('Aleasha · Shower', $this->events[$aExport->event_id]['summary']);
        $new = app(BookingService::class)->book($other, $other->id, '2026-11-09T12:00:00Z', replaceId: $a->id);
        app(CalendarSyncService::class)->run();
        $this->assertSame('cancelled', $this->events[$aExport->event_id]['status']);
        $newExport = CalendarExport::where('reservation_id', $new->id)->first();
        app(BookingService::class)->cancel($other, $new->id); app(CalendarSyncService::class)->run();
        $this->assertSame('cancelled', $this->events[$newExport->event_id]['status']);
        $this->assertCount(3, $this->events);
    }

    public function test_export_retries_are_idempotent_and_google_deleted_showers_can_be_recreated(): void
    {
        $reservation = $this->book($this->child); $this->insertResponseLost = true;
        try { app(CalendarSyncService::class)->run(); $this->fail('Expected a lost response'); } catch (CalendarException) {}
        $this->assertDatabaseHas('calendar_exports', ['reservation_id' => $reservation->id, 'fingerprint' => null]);
        app(CalendarSyncService::class)->run(); app(CalendarSyncService::class)->run();
        $this->assertCount(1, $this->events);
        $export = CalendarExport::where('reservation_id', $reservation->id)->first();
        $this->events[$export->event_id]['status'] = 'cancelled';
        $this->travel(7)->hours();
        Cache::put('google-calendar:token:'.hash('sha256', 'test-credentials'), 'test-token', 3300);
        app(CalendarSyncService::class)->run();
        $this->assertCount(2, $this->events);
        $this->assertNotSame($export->event_id, $export->fresh()->event_id);
    }

    public function test_google_shower_copies_do_not_appear_twice_or_block_other_bookings(): void
    {
        $this->book($this->child); app(CalendarSyncService::class)->run();
        $this->actingAs($this->parent)->get('/calendar')->assertSee('Liam · Shower', false);
        $this->assertCount(1, app(CalendarAvailability::class)->forDay('2026-11-09', true));
        $this->book($this->parent, '11:20');
        $id = array_key_first($this->events);
        Livewire::actingAs($this->parent)->test(Calendar::class)->call('editEvent', $id)->assertHasErrors('calendar');
    }

    public function test_new_conflicts_notify_only_the_owner_and_are_deduplicated_without_cancelling(): void
    {
        $reservation = $this->book($this->child);
        DB::table('notifications')->delete(); DB::table('notification_deliveries')->delete();
        $this->family();
        app(CalendarConflictService::class)->run(); app(CalendarConflictService::class)->run();
        $this->assertSame(1, $this->child->notifications()->count());
        $this->assertSame(0, $this->parent->notifications()->count());
        $this->assertSame('family_overlap', $this->child->notifications()->first()->data['kind']);
        $this->assertSame('booked', $reservation->fresh()->status);
    }

    public function test_overlap_preferences_and_stale_push_checks_are_respected(): void
    {
        $reservation = $this->book($this->child);
        DB::table('notifications')->delete();
        $this->child->forceFill(['notification_preferences' => ['family_overlap' => false]])->save();
        $event = $this->family();
        $service = app(CalendarConflictService::class);
        $key = $service->key($reservation, $event);
        $service->run(); $this->assertSame(0, $this->child->notifications()->count());
        $this->assertTrue($service->stillOverlaps($this->child->id, $key));
        $this->events[$event['id']]['transparency'] = 'transparent';
        $this->assertFalse($service->stillOverlaps($this->child->id, $key));
        $this->assertFalse($service->stillOverlaps($this->parent->id, $key));
    }

    public function test_unconfigured_calendar_is_optional_and_does_not_break_the_app(): void
    {
        config(['google-calendar.credentials' => '']);
        $this->actingAs($this->parent)->get('/calendar')->assertOk()->assertSee('connection setup');
        $this->book($this->child);
        $this->artisan('showerqueue:calendar-sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_insufficient_google_access_fails_closed_without_exposing_tokens(): void
    {
        $this->role = 'reader';
        try { $this->book($this->child); $this->fail('Read-only connection should block booking'); }
        catch (ValidationException $e) {
            $this->assertStringContainsString('sharing permission', $e->getMessage());
            $this->assertStringNotContainsString('test-token', $e->getMessage());
        }
        $this->assertDatabaseCount('reservations', 0);
    }
}
