<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\RecurringPattern;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecurrenceService
{
    public function __construct(private HouseholdLock $lock, private BookingService $bookings, private SlotService $slots) {}

    public function save(User $actor, array $data, ?int $id = null): RecurringPattern
    {
        abort_unless($actor->fresh()?->is_active && $actor->fresh()->is_admin, 403);
        $data = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|between:1,7|distinct', 'time' => 'required|date_format:H:i', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on'])->validate();
        $data['ends_on'] = $data['ends_on'] ?: null;
        $data['weekdays'] = array_map('intval', $data['weekdays']);
        $pattern = $this->lock->run(function () use ($data, $id, $actor) {
            if ($id) {
                $pattern = RecurringPattern::findOrFail($id);
                foreach (Reservation::active()->where('recurring_pattern_id', $id)->where('starts_at', '>', now())->get() as $r) $this->bookings->cancel($actor, $r->id);
                Reservation::where('recurring_pattern_id', $id)->where('starts_at', '>', now())->update(['recurring_pattern_id' => null]);
                DB::table('recurrence_results')->where('recurring_pattern_id', $id)->where('day', '>=', $this->slots->today())->delete();
                $pattern->update($data + ['enabled' => true]);
                return $pattern;
            }
            return RecurringPattern::create($data + ['created_by' => $actor->id]);
        });
        $this->materialize($pattern);
        return $pattern;
    }

    public function remove(User $actor, int $id): void
    {
        abort_unless($actor->fresh()?->is_active && $actor->fresh()->is_admin, 403);
        $this->lock->run(function () use ($actor, $id) {
            $pattern = RecurringPattern::findOrFail($id);
            $pattern->update(['enabled' => false]);
            foreach (Reservation::active()->where('recurring_pattern_id', $id)->where('starts_at', '>', now())->get() as $r) $this->bookings->cancel($actor, $r->id);
        });
    }

    public function materialize(RecurringPattern $pattern): void
    {
        $settings = HouseholdSetting::current();
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        for ($day = $today; $day->lte($today->addDays(30)); $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($date < $pattern->starts_on || ($pattern->ends_on && $date > $pattern->ends_on) || !in_array($day->dayOfWeekIso, $pattern->weekdays)) continue;
            $this->lock->run(function () use ($pattern, $date, $settings) {
                $fresh = $pattern->fresh();
                if (!$fresh->enabled || DB::table('recurrence_results')->where('recurring_pattern_id', $pattern->id)->where('day', $date)->exists()) return;
                $result = 'reserved'; $message = null;
                try {
                    $matches = array_values(array_filter($this->slots->slots($date, $settings), fn ($slot) => $slot['local']->format('H:i') === $fresh->time));
                    if (!$matches) throw ValidationException::withMessages(['booking' => 'Time is outside current scheduling hours or does not exist on this date.']);
                    $this->bookings->book(User::findOrFail($fresh->created_by), $fresh->user_id, $matches[0]['start']->toIso8601String(), patternId: $fresh->id);
                } catch (ValidationException $e) {
                    $result = 'conflict'; $message = collect($e->errors())->flatten()->first();
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $result = 'conflict'; $message = 'The parent who created this pattern no longer has access.';
                }
                DB::table('recurrence_results')->insert(['recurring_pattern_id' => $pattern->id, 'day' => $date, 'result' => $result, 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
            });
        }
    }
}
