<?php

namespace App\Services;

use App\Jobs\SendPush;
use App\Models\HouseholdSetting;
use App\Models\User;
use App\Notifications\HouseholdNotification;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public const TYPES = ['booked' => 'New reservations', 'changed' => 'Changes & cancellations', 'swap_requested' => 'Swap requests', 'swap_resolved' => 'Swap responses', 'soon' => 'Shower reminders', 'next' => 'Your turn is next'];

    public function preferences(User $user): array
    {
        return array_replace(array_fill_keys(array_keys(self::TYPES), true), HouseholdSetting::current()->notification_defaults ?? [], $user->notification_preferences ?? []);
    }

    public function household(string $type, string $title, string $body, string $key): void
    {
        foreach (User::where('is_active', true)->get() as $user) $this->send($user, $type, $title, $body, $key);
    }

    public function send(User $user, string $type, string $title, string $body, string $key): void
    {
        if (!$user->is_active || !($this->preferences($user)[$type] ?? false)) return;
        DB::transaction(function () use ($user, $type, $title, $body, $key) {
            $inserted = DB::table('notification_deliveries')->insertOrIgnore(['user_id' => $user->id, 'event_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            if (!$inserted) return;
            $user->notifyNow(new HouseholdNotification($type, $title, $body, $key), ['database']);
            if (config('webpush.vapid.public_key') && config('webpush.vapid.private_key')) SendPush::dispatch($user->id, $type, $title, $body, $key)->afterCommit();
        });
    }
}
