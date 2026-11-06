<?php

namespace App\Livewire;

use Livewire\Component;

class Inbox extends Component
{
    public function mount(): void
    {
        $this->markRead();
    }

    public function markRead(): void
    {
        $updated = auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        if ($updated) $this->dispatch('notifications-updated');
    }
    public function render()
    {
        return view('livewire.inbox', [
            'notifications' => auth()->user()->notifications()->limit(60)->get(),
            'destinations' => app(\App\Services\NotificationDestination::class),
        ])->layout('layouts.app', ['title' => 'Inbox']);
    }
}
