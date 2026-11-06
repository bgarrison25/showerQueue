<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class HouseholdNotification extends Notification
{
    public function __construct(public string $kind, public string $title, public string $body, public string $eventKey, public ?string $day = null) {}
    public function via(object $notifiable): array { return ['database', WebPushChannel::class]; }
    public function toArray(object $notifiable): array { return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url(), 'tag' => $this->eventKey, 'day' => $this->day]; }
    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        return (new WebPushMessage)->title($this->title)->body($this->body)->icon('/icons/icon-192.png')->tag($this->eventKey)->data(['url' => $this->url()]);
    }

    private function url(): string { return app(\App\Services\NotificationDestination::class)->url(['kind' => $this->kind, 'tag' => $this->eventKey, 'day' => $this->day]); }
}
