<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\HouseholdNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NotificationChannels\WebPush\WebPushChannel;

class SendPush implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 30;
    public function __construct(public int $userId, public string $kind, public string $title, public string $body, public string $eventKey) {}
    public function backoff(): array { return [10, 60, 300]; }
    public function handle(NotificationService $notifications): void
    {
        $user = User::find($this->userId);
        if (in_array($this->kind, ['soon', 'next']) && preg_match('/^(?:soon|next):(\d+):(\d+)$/', $this->eventKey, $match)) {
            $valid = \App\Models\Reservation::where('id', $match[1])->where('revision', $match[2])->where('user_id', $this->userId)->where('status', 'booked')->where('ends_at', '>', now())->exists();
            if (!$valid) return;
        }
        if ($user?->is_active && ($notifications->preferences($user)[$this->kind] ?? false)) $user->notifyNow(new HouseholdNotification($this->kind, $this->title, $this->body, $this->eventKey), [WebPushChannel::class]);
    }
}
