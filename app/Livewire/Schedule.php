<?php

namespace App\Livewire;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\SwapRequest;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\BookingService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Schedule extends Component
{
    #[Url] public string $day = '';
    #[Url] public string $viewMode = 'day';
    public string $period = 'all';
    public int $ownerId = 0;
    public bool $override = false;
    public ?int $movingId = null;
    public bool $showPast = false;
    public bool $expandedSlots = false;
    public string $notice = '';
    #[Locked] public ?int $swapTargetId = null;

    public function mount(): void { $this->ownerId = auth()->id(); $this->normalizeDay(); }

    private function normalizeDay(): void
    {
        $today = app(SlotService::class)->today();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day) || !strtotime($this->day) || $this->day < CarbonImmutable::parse($today)->subDays(7)->toDateString() || $this->day > CarbonImmutable::parse($today)->addDays(90)->toDateString()) $this->day = $today;
    }

    public function navigate(int $days): void
    {
        $this->normalizeDay();
        $this->day = CarbonImmutable::parse($this->day)->addDays(max(-7, min(7, $days)))->toDateString();
        $this->normalizeDay(); $this->movingId = null; $this->resetErrorBag();
    }

    public function selectDay(string $day): void { $this->day = $day; $this->normalizeDay(); $this->viewMode = 'day'; $this->movingId = null; }
    public function today(): void { $this->selectDay(app(SlotService::class)->today()); }

    private function perform(callable $action, string $message): void
    {
        $this->resetErrorBag(); $this->notice = ''; $this->swapTargetId = null;
        try { $action(); $this->notice = $message; $this->dispatch('notifications-updated'); }
        catch (ValidationException $e) { $this->addError('booking', collect($e->errors())->flatten()->first()); }
        catch (QueryException $e) {
            if (!in_array((string) $e->getCode(), ['23000', '23505', 'HY000'])) throw $e;
            $this->addError('booking', 'The schedule is busy or that time just changed. Your original reservation is safe. Please try again.');
        }
    }

    public function book(string $iso): void
    {
        $this->perform(function () use ($iso) {
            app(BookingService::class)->book(auth()->user(), $this->ownerId, $iso, $this->override, $this->movingId);
            $this->movingId = null;
        }, 'You’re all set. The household schedule is up to date.');
    }

    public function move(int $id): void
    {
        $r = Reservation::active()->findOrFail($id);
        abort_unless(auth()->user()->is_admin || $r->user_id === auth()->id(), 403);
        $this->movingId = $id; $this->ownerId = $r->user_id; $this->day = $r->day; $this->resetErrorBag();
    }

    public function cancel(int $id): void { $this->perform(fn () => app(BookingService::class)->cancel(auth()->user(), $id), 'Reservation cancelled. That time is available again.'); }
    public function start(int $id): void { $this->perform(fn () => app(BookingService::class)->start(auth()->user(), $id), 'Your shower timer is running. Enjoy!'); }
    public function finish(int $id): void { $this->perform(fn () => app(BookingService::class)->finish(auth()->user(), $id), 'All finished. The shower is available.'); }
    public function requestSwap(int $from, int $to): void
    {
        $this->perform(fn () => app(BookingService::class)->requestSwap(auth()->user(), $from, $to), 'Swap requested. Your reservation stays yours until they accept.');
        $this->swapTargetId = $to;
    }

    public function render()
    {
        $this->normalizeDay();
        $settings = HouseholdSetting::current();
        $today = app(SlotService::class)->today();
        $members = User::where('is_active', true)->get();
        $attendance = $members->mapWithKeys(fn ($u) => [$u->id => app(AttendanceService::class)->isHome($u, $this->day, $settings)]);
        $bookings = Reservation::active()->with('user')->where('day', $this->day)->orderBy('starts_at')->get();
        $familyEvents = []; $calendarError = '';
        try { $familyEvents = app(\App\Services\CalendarAvailability::class)->forDay($this->day); }
        catch (\App\Exceptions\CalendarException $e) { $calendarError = $e->getMessage(); }
        $blocksBusy = config('google-calendar.block_busy') && app(\App\Services\GoogleCalendar::class)->configured();
        $mine = $bookings->first(fn ($r) => $r->user_id === auth()->id() && $r->status !== 'finished' && $r->ends_at->isFuture());
        $pendingSwaps = $mine ? SwapRequest::where('requested_by', auth()->id())
            ->where('from_reservation_id', $mine->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->get()->keyBy('to_reservation_id') : collect();
        $rows = collect(app(SlotService::class)->slots($this->day, $settings))->map(function ($slot) use ($bookings, $familyEvents, $blocksBusy) {
            $slot['reservation'] = $bookings->first(fn ($r) => $r->starts_at->lt($slot['end']) && $r->ends_at->gt($slot['start']));
            $slot['familyConflicts'] = app(\App\Services\CalendarAvailability::class)->conflicts($familyEvents, $slot['start'], $slot['end']);
            $slot['blocked'] = $blocksBusy && count($slot['familyConflicts']) > 0;
            return $slot;
        });
        $nextAvailable = $calendarError && $blocksBusy ? null : $rows->first(fn ($slot) => !$slot['reservation'] && !$slot['blocked'] && $slot['start']->isFuture());
        $rows = $rows->filter(function ($slot) use ($today) {
            if (!$this->showPast && $this->day === $today && $slot['end']->isPast()) return false;
            $hour = (int) $slot['local']->format('H');
            return match ($this->period) { 'morning' => $hour < 12, 'afternoon' => $hour >= 12 && $hour < 17, 'evening' => $hour >= 17, default => true };
        });
        $totalSlots = $rows->count();
        if (!$this->expandedSlots) $rows = $rows->take(12);
        $current = Reservation::active()->with('user')->whereIn('status', ['booked', 'running'])->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
        $week = collect(range(0, 6))->map(function ($offset) {
            $date = CarbonImmutable::parse($this->day)->startOfWeek()->addDays($offset);
            return ['date' => $date, 'bookings' => Reservation::active()->with('user')->where('day', $date->toDateString())->orderBy('starts_at')->get()];
        });
        return view('livewire.schedule', compact('settings', 'today', 'members', 'attendance', 'bookings', 'mine', 'pendingSwaps', 'rows', 'totalSlots', 'nextAvailable', 'current', 'week', 'calendarError', 'blocksBusy'))->layout('layouts.app', ['title' => 'Schedule']);
    }
}
