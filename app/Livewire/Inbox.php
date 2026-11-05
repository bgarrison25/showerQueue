<?php

namespace App\Livewire;

use Livewire\Component;

class Inbox extends Component
{
    public function markRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-updated');
    }
    public function render() { return view('livewire.inbox', ['notifications' => auth()->user()->notifications()->limit(60)->get()])->layout('layouts.app', ['title' => 'Inbox']); }
}
