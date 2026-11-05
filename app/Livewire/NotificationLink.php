<?php

namespace App\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationLink extends Component
{
    #[Locked]
    public string $placement = 'header';

    #[Locked]
    public bool $active = false;

    public function mount(string $placement = 'header', bool $active = false): void
    {
        abort_unless(in_array($placement, ['header', 'sidebar', 'mobile'], true), 404);
        $this->placement = $placement;
        $this->active = $active;
    }

    #[On('notifications-updated')]
    public function refreshCount(): void
    {
        // The event triggers a render, which always reads the current account's count.
    }

    public function render()
    {
        return view('livewire.notification-link', [
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
