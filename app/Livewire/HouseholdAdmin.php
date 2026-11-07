<?php

namespace App\Livewire;

use App\Models\HouseholdSetting;
use App\Models\RecurringPattern;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\HouseholdCredential;
use App\Services\HouseholdLock;
use App\Services\NotificationService;
use App\Services\RecurrenceService;
use App\Services\SlotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class HouseholdAdmin extends Component
{
    #[\Livewire\Attributes\Locked]
    public ?int $editingMemberId = null;
    public array $memberProfile = ['first_name' => '', 'last_name' => '', 'nickname' => '', 'color' => 'teal'];
    public array $settings = [];
    public array $attendance = ['user_id' => '', 'day' => '', 'is_home' => true, 'note' => ''];
    public array $pattern = ['user_id' => '', 'weekdays' => [1, 2], 'time' => '07:00', 'starts_on' => '', 'ends_on' => null];
    public ?int $patternId = null;
    public int $resetUserId = 0;
    public string $newCredential = '';
    public string $notice = '';
    public string $bookingDay = '';

    #[\Livewire\Attributes\Computed]
    public function allBookings()
    {
        return Reservation::with('user')->when($this->bookingDay, fn ($q) => $q->where('day', $this->bookingDay))->orderByDesc('starts_at')->limit(100)->get();
    }
    public function cancelBooking(int $id): void { app(\App\Services\BookingService::class)->cancel(auth()->user(), $id); $this->notice = 'Reservation cancelled.'; }
    public function endTimer(int $id): void { app(\App\Services\BookingService::class)->finish(auth()->user(), $id); $this->notice = 'Shower timer ended.'; }

    public function boot(): void { Gate::authorize('admin'); }
    public function mount(): void
    {
        $this->settings = HouseholdSetting::current()->only(['timezone', 'slot_minutes', 'opens_at', 'closes_at', 'weekend_reference', 'restrict_absent', 'morning_limit', 'reminder_minutes', 'notification_defaults']);
        $this->settings['notification_defaults'] = array_replace(array_fill_keys(array_keys(NotificationService::TYPES), true), $this->settings['notification_defaults'] ?? []);
        $this->attendance['day'] = $this->pattern['starts_on'] = app(SlotService::class)->today();
        $this->attendance['user_id'] = $this->pattern['user_id'] = auth()->id();
    }

    public function editMember(int $id): void
    {
        $member = User::findOrFail($id);
        $this->editingMemberId = $id;
        $this->memberProfile = ['first_name' => $member->profileFirstName(), 'last_name' => $member->profileLastName(), 'nickname' => $member->nickname ?? '', 'color' => $member->color];
        $this->resetErrorBag();
    }

    public function cancelMemberEdit(): void
    {
        $this->editingMemberId = null;
        $this->resetErrorBag();
    }

    public function saveMember(): void
    {
        abort_unless($this->editingMemberId, 422);
        $plain = 'not_regex:/[\x00-\x1F\x7F<>]/u';
        $data = $this->validate([
            'memberProfile.first_name' => ['required', 'string', 'max:60', $plain],
            'memberProfile.last_name' => ['nullable', 'string', 'max:80', $plain],
            'memberProfile.nickname' => ['nullable', 'string', 'max:30', $plain],
            'memberProfile.color' => ['required', Rule::in(['teal', 'rose', 'blue', 'amber', 'violet', 'pink'])],
        ])['memberProfile'];
        foreach (['first_name', 'last_name', 'nickname'] as $field) $data[$field] = preg_replace('/\s+/u', ' ', trim($data[$field] ?? ''));
        $data['nickname'] = $data['nickname'] ?: null;
        User::findOrFail($this->editingMemberId)->forceFill(collect($data)->only(['first_name', 'last_name', 'nickname', 'color'])->all())->save();
        $this->editingMemberId = null;
        $this->notice = 'Member profile saved. Their sign-in name and reservations are unchanged.';
        $this->dispatch('profile-updated');
    }

    public function saveSettings(): void
    {
        $this->validate(['settings.timezone' => 'required|timezone', 'settings.slot_minutes' => 'required|integer|min:5|max:60', 'settings.opens_at' => 'required|date_format:H:i', 'settings.closes_at' => 'required|date_format:H:i|after:settings.opens_at', 'settings.weekend_reference' => 'required|date_format:Y-m-d', 'settings.restrict_absent' => 'required|boolean', 'settings.morning_limit' => 'nullable|integer|min:1|max:7', 'settings.reminder_minutes' => 'required|integer|min:1|max:30', 'settings.notification_defaults' => 'required|array', 'settings.notification_defaults.*' => 'boolean']);
        app(HouseholdLock::class)->run(function () {
            $s = HouseholdSetting::current();
            if ($s->timezone !== $this->settings['timezone'] && Reservation::active()->where('ends_at', '>', now())->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['settings.timezone' => 'Cancel upcoming reservations before changing the household timezone.']);
            $data = array_intersect_key($this->settings, array_flip(['timezone', 'slot_minutes', 'opens_at', 'closes_at', 'weekend_reference', 'restrict_absent', 'morning_limit', 'reminder_minutes', 'notification_defaults']));
            $data['morning_limit'] = $data['morning_limit'] ?: null;
            $data['notification_defaults'] = array_intersect_key($data['notification_defaults'], NotificationService::TYPES);
            $s->update($data);
        });
        $this->notice = 'Household settings saved. Existing reservations keep their times.';
    }

    public function saveAttendance(): void
    {
        $this->validate(['attendance.user_id' => 'required|integer|exists:users,id', 'attendance.day' => 'required|date_format:Y-m-d', 'attendance.is_home' => 'required|boolean', 'attendance.note' => 'nullable|string|max:200']);
        app(HouseholdLock::class)->run(fn () => DB::table('attendance_overrides')->updateOrInsert(['user_id' => $this->attendance['user_id'], 'day' => $this->attendance['day']], ['is_home' => $this->attendance['is_home'], 'note' => $this->attendance['note'], 'created_at' => now(), 'updated_at' => now()]));
        $this->notice = 'Attendance updated. Existing bookings are unchanged.';
    }
    public function removeAttendance(int $id): void { app(HouseholdLock::class)->run(fn () => DB::table('attendance_overrides')->where('id', $id)->delete()); $this->notice = 'Regular attendance restored.'; }
    public function savePattern(): void { app(RecurrenceService::class)->save(auth()->user(), $this->pattern, $this->patternId); $this->patternId = null; $this->notice = 'Recurring pattern saved. The next 30 days were checked; any conflicts are listed below.'; }
    public function editPattern(int $id): void { $p = RecurringPattern::findOrFail($id); $this->pattern = $p->only(['user_id', 'weekdays', 'time', 'starts_on', 'ends_on']); $this->patternId = $id; }
    public function removePattern(int $id): void { app(RecurrenceService::class)->remove(auth()->user(), $id); $this->notice = 'Pattern removed and its upcoming reservations cancelled.'; }
    public function toggleAccount(int $id): void
    {
        abort_if($id === auth()->id(), 422, 'You cannot pause your own account.');
        app(HouseholdLock::class)->run(function () use ($id) {
            $user = User::findOrFail($id);
            $user->forceFill(['is_active' => !$user->is_active, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $id)->delete();
            if (!$user->is_active) $user->pushSubscriptions()->delete();
        });
        $this->notice = 'Account access updated.';
    }
    public function resetCredential(): void
    {
        $this->validate(['resetUserId' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([auth()->id()])], 'newCredential' => ['required', new HouseholdCredential]]);
        app(HouseholdLock::class)->run(function () {
            $user = User::findOrFail($this->resetUserId);
            $user->forceFill(['password' => $this->newCredential, 'must_change_password' => true, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete(); $user->pushSubscriptions()->delete();
        });
        $this->newCredential = ''; $this->notice = 'Temporary access saved. Share it privately; they must change it at next sign-in.';
    }
    public function render()
    {
        $members = User::all();
        $overrides = DB::table('attendance_overrides')->join('users', 'users.id', '=', 'attendance_overrides.user_id')
            ->select('attendance_overrides.*', 'users.name')->orderByDesc('day')->limit(30)->get()
            ->map(function ($override) use ($members) {
                $override->display_name = $members->firstWhere('id', $override->user_id)?->display_name ?? $override->name;
                return $override;
            });
        return view('livewire.household-admin', [
            'members' => $members,
            'patterns' => RecurringPattern::with('user')->where('enabled', true)->get(),
            'overrides' => $overrides,
            'conflicts' => DB::table('recurrence_results')->where('result', 'conflict')->where('day', '>=', app(SlotService::class)->today())->orderBy('day')->limit(30)->get(),
        ])->layout('layouts.app', ['title' => 'Manage household']);
    }
}
