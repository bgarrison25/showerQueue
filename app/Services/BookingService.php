<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\SwapRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private HouseholdLock $lock, private SlotService $slots, private AttendanceService $attendance, private NotificationService $notifications) {}

    public function book(User $actor, int $ownerId, string $iso, bool $override = false, ?int $replaceId = null, ?int $patternId = null, ?array $calendarEvents = null): Reservation
    {
        $this->actor($actor);
        abort_unless($actor->is_admin || ($ownerId === $actor->id && !$override && !$patternId), 403);
        if (!$override && config('google-calendar.block_busy') && app(GoogleCalendar::class)->configured() && $calendarEvents === null) {
            [$start, , $day] = $this->slots->resolve($iso, HouseholdSetting::current());
            try { $calendarEvents = app(CalendarAvailability::class)->forDay($day, true); }
            catch (\App\Exceptions\CalendarException $e) { throw ValidationException::withMessages(['booking' => $e->getMessage().' Availability could not be checked. Your existing reservation is safe.']); }
        }
        // Google is checked before obtaining the household write lock. Recurrences
        // provide a freshly fetched window, avoiding network I/O inside their transaction.
        return $this->lock->run(function () use ($actor, $ownerId, $iso, $override, $replaceId, $patternId, $calendarEvents) {
            $this->actor($actor);
            abort_unless($actor->is_admin || ($ownerId === $actor->id && !$override && !$patternId), 403);
            $owner = User::findOrFail($ownerId);
            $this->check($owner->is_active, 'This account is paused.');
            $settings = HouseholdSetting::current();
            [$start, $end, $day] = $this->slots->resolve($iso, $settings);
            if (!$override && config('google-calendar.block_busy')) {
                $conflicts = app(CalendarAvailability::class)->conflicts($calendarEvents ?? [], $start, $end);
                $this->check(!$conflicts, 'A busy family event overlaps that time. Choose another slot or ask a parent to use an override.');
            }
            $old = $replaceId ? Reservation::find($replaceId) : null;
            $this->check(!$replaceId || $old !== null, 'Your original reservation changed. Refresh the schedule and choose again.');
            if ($old) {
                abort_unless($actor->is_admin || $old->user_id === $actor->id, 403);
                $this->check($old->status === 'booked' && $old->starts_at->isFuture(), 'Only upcoming reservations can be changed.');
            }
            $others = Reservation::active()->when($old, fn ($q) => $q->where('id', '<>', $old->id));
            $this->check(!(clone $others)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 'That time was just taken. Please choose another slot.');
            $daily = (clone $others)->where('user_id', $ownerId)->where('day', $day)->exists();
            $this->check(!$daily || ($actor->is_admin && $override), 'One shower per person each day. Tap another time to move your reservation.');
            if (!$override) {
                $this->check(!$settings->restrict_absent || $this->attendance->isHome($owner, $day, $settings), 'You are marked away that day. Ask a parent to update attendance.');
                if ($settings->morning_limit && (int) $start->setTimezone($settings->timezone)->format('H') < 9) {
                    $week = $start->setTimezone($settings->timezone)->startOfWeek();
                    $morningCount = (clone $others)->where('user_id', $ownerId)->whereBetween('day', [$week->toDateString(), $week->addDays(6)->toDateString()])->get()
                        ->filter(fn ($r) => (int) $r->starts_at->setTimezone($settings->timezone)->format('H') < 9)->count();
                    $this->check($morningCount < $settings->morning_limit, 'Your morning allowance for this week is full. Choose a time after 9 AM or ask a parent.');
                }
            }
            if ($old) $this->cancelInside($old);
            $reservation = Reservation::create(['user_id' => $ownerId, 'created_by' => $actor->id, 'day' => $day, 'starts_at' => $start, 'ends_at' => $end, 'is_override' => $override, 'recurring_pattern_id' => $patternId]);
            $minutes = [];
            for ($minute = $start; $minute->lt($end); $minute = $minute->addMinute()) $minutes[] = ['minute' => $minute->format('Y-m-d H:i:s'), 'reservation_id' => $reservation->id];
            DB::table('reservation_minutes')->insert($minutes);
            $kind = $old ? 'changed' : 'booked';
            $label = $start->setTimezone($settings->timezone)->format('D, M j · g:i A T');
            $this->notifications->household($kind, "{$owner->display_name}'s shower ".($old ? 'moved' : 'reserved'), $label, "$kind:{$reservation->id}");
            return $reservation;
        });
    }

    public function cancel(User $actor, int $id): void
    {
        $this->lock->run(function () use ($actor, $id) {
            $this->actor($actor);
            $r = Reservation::findOrFail($id);
            abort_unless($actor->is_admin || $r->user_id === $actor->id, 403);
            $this->check($r->status === 'booked' && ($actor->is_admin || $r->ends_at->isFuture()), 'This reservation can no longer be cancelled.');
            $this->cancelInside($r);
            $this->notifications->household('changed', "{$r->user->display_name}'s shower cancelled", $r->starts_at->setTimezone(HouseholdSetting::current()->timezone)->format('D, M j · g:i A T'), "cancelled:$id");
        });
    }

    private function cancelInside(Reservation $r): void
    {
        $r->update(['status' => 'cancelled', 'revision' => $r->revision + 1]);
        DB::table('reservation_minutes')->where('reservation_id', $r->id)->delete();
        $this->invalidateSwaps([$r->id]);
    }

    public function requestSwap(User $actor, int $fromId, int $toId): SwapRequest
    {
        return $this->lock->run(function () use ($actor, $fromId, $toId) {
            $this->actor($actor);
            $a = Reservation::findOrFail($fromId);
            $b = Reservation::findOrFail($toId);
            abort_unless($a->user_id === $actor->id, 403);
            $this->check($a->user_id !== $b->user_id && $a->day === $b->day, 'Choose another person on the same day.');
            $this->check($a->status === 'booked' && $b->status === 'booked' && $a->starts_at->isFuture() && $b->starts_at->isFuture(), 'Only upcoming reservations can be swapped.');
            $this->check(!SwapRequest::where('requested_by', $actor->id)->where('from_reservation_id', $fromId)->where('to_reservation_id', $toId)->where('status', 'pending')->where('expires_at', '>', now())->exists(), 'You have already requested this swap.');
            $expires = now()->addHours(12)->min($a->starts_at)->min($b->starts_at);
            $swap = SwapRequest::create(['from_reservation_id' => $fromId, 'to_reservation_id' => $toId, 'requested_by' => $actor->id, 'recipient_id' => $b->user_id, 'from_revision' => $a->revision, 'to_revision' => $b->revision, 'expires_at' => $expires]);
            $this->notifications->send($b->user, 'swap_requested', "{$actor->display_name} wants to swap", 'Open Swaps to see the times and respond.', "swap-request:{$swap->id}");
            return $swap;
        });
    }

    public function respondSwap(User $actor, int $id, bool $accept): void
    {
        $this->lock->run(function () use ($actor, $id, $accept) {
            $this->actor($actor);
            $swap = SwapRequest::findOrFail($id);
            abort_unless($actor->is_admin || $actor->id === $swap->recipient_id || (!$accept && $actor->id === $swap->requested_by), 403);
            $this->check($swap->status === 'pending' && $swap->expires_at->isFuture(), 'This swap is no longer available.');
            $a = $swap->fromReservation; $b = $swap->toReservation;
            $this->check($a->status === 'booked' && $b->status === 'booked' && $a->starts_at->isFuture() && $b->starts_at->isFuture() && $a->revision === $swap->from_revision && $b->revision === $swap->to_revision && $a->user_id === $swap->requested_by && $b->user_id === $swap->recipient_id, 'A reservation changed. Request a new swap.');
            if ($accept) {
                foreach ([$a, $b] as $r) {
                    $this->check(!Reservation::active()->where('user_id', $r->user_id)->where('day', $a->day)->whereNotIn('id', [$a->id, $b->id])->exists(), 'A member has another reservation that day. Resolve it before swapping.');
                }
                // Temporarily release only daily keys inside this transaction. Minute
                // claims and intervals stay untouched, so the shower is never double-booked.
                $a->update(['is_override' => true]); $b->update(['is_override' => true]);
                $ownerA = $a->user_id; $ownerB = $b->user_id;
                $a->update(['user_id' => $ownerB, 'is_override' => false, 'revision' => $a->revision + 1]);
                $b->update(['user_id' => $ownerA, 'is_override' => false, 'revision' => $b->revision + 1]);
                $this->invalidateSwaps([$a->id, $b->id], $swap->id);
            }
            $status = $accept ? 'accepted' : 'declined';
            $swap->update(['status' => $status]);
            foreach ([$swap->requester, $swap->recipient] as $user) $this->notifications->send($user, 'swap_resolved', "Swap $status", 'Your shared schedule is up to date.', "swap-$status:$id");
        });
    }

    public function start(User $actor, int $id): void
    {
        $this->lock->run(function () use ($actor, $id) {
            $this->actor($actor);
            $r = Reservation::findOrFail($id);
            abort_unless($actor->is_admin || $actor->id === $r->user_id, 403);
            $this->check($r->status === 'booked' && $r->starts_at->lte(now()) && $r->ends_at->gt(now()), 'Start your shower during your reserved time.');
            $this->check(!Reservation::where('status', 'running')->where('ends_at', '>', now())->exists(), 'A shower is already running.');
            $r->update(['status' => 'running', 'started_at' => now(), 'revision' => $r->revision + 1]);
            $this->invalidateSwaps([$id]);
        });
    }

    public function finish(User $actor, int $id): void
    {
        $this->lock->run(function () use ($actor, $id) {
            $this->actor($actor);
            $r = Reservation::findOrFail($id);
            abort_unless($actor->is_admin || $actor->id === $r->user_id, 403);
            $this->check($r->status === 'running', 'This shower is already finished.');
            $r->update(['status' => 'finished', 'finished_at' => now()]);
        });
    }

    private function invalidateSwaps(array $ids, ?int $except = null): void
    {
        $swaps = SwapRequest::where('status', 'pending')->when($except, fn ($q) => $q->where('id', '<>', $except))->where(fn ($q) => $q->whereIn('from_reservation_id', $ids)->orWhereIn('to_reservation_id', $ids))->get();
        foreach ($swaps as $swap) {
            $swap->update(['status' => 'expired']);
            foreach ([$swap->requester, $swap->recipient] as $user) $this->notifications->send($user, 'swap_resolved', 'Swap no longer available', 'A reservation changed. You can request a new swap.', "swap-invalid:{$swap->id}");
        }
    }

    public function actor(User $actor): void { $actor->refresh(); abort_unless($actor->is_active, 403); }
    private function check(bool $condition, string $message): void { if (!$condition) throw ValidationException::withMessages(['booking' => $message]); }
}
