<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\Reservation;
use App\Models\SwapRequest;

class ReminderService
{
    public function run(): void
    {
        app(HouseholdLock::class)->run(function () {
            $settings = HouseholdSetting::current();
            Reservation::where('status', 'running')->where('ends_at', '<=', now())->update(['status' => 'finished', 'finished_at' => now()]);
            foreach (SwapRequest::where('status', 'pending')->where('expires_at', '<=', now())->get() as $swap) {
                $swap->update(['status' => 'expired']);
                foreach ([$swap->requester, $swap->recipient] as $user) app(NotificationService::class)->send($user, 'swap_resolved', 'Swap request expired', 'Your original reservation is unchanged.', "swap-expired:{$swap->id}");
            }
            foreach (Reservation::with('user')->where('status', 'booked')->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($settings->reminder_minutes))->get() as $r) {
                app(NotificationService::class)->send($r->user, 'soon', 'Your shower starts soon', $r->starts_at->setTimezone($settings->timezone)->format('g:i A T').' · Get ready for your time.', "soon:{$r->id}:{$r->revision}");
            }
            $next = Reservation::with('user')->where('status', 'booked')->where('ends_at', '>', now())->where('starts_at', '<=', now()->addMinutes(30))->orderBy('starts_at')->first();
            if ($next) app(NotificationService::class)->send($next->user, 'next', 'Your turn is next', 'Your reserved time is '.$next->starts_at->setTimezone($settings->timezone)->format('g:i A T').'.', "next:{$next->id}:{$next->revision}");
        });
    }
}
