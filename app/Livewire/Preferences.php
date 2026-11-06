<?php

namespace App\Livewire;

use App\Services\NotificationService;
use Livewire\Component;

class Preferences extends Component
{
    public array $preferences = [];
    public string $notice = '';
    public function mount(): void { $this->preferences = app(NotificationService::class)->preferences(auth()->user()); }
    public function save(): void
    {
        $rules = []; foreach (NotificationService::TYPES as $key => $label) $rules['preferences.'.$key] = 'required|boolean';
        $this->validate($rules);
        auth()->user()->forceFill(['notification_preferences' => array_intersect_key($this->preferences, NotificationService::TYPES)])->save();
        $this->notice = 'Your notification preferences are saved.';
    }
    public function useDefaults(): void
    {
        auth()->user()->forceFill(['notification_preferences' => null])->save();
        $this->preferences = app(NotificationService::class)->preferences(auth()->user());
        $this->notice = 'You’re now using the household notification defaults.';
    }
    public function render() { return view('livewire.preferences')->layout('layouts.app', ['title' => 'Settings']); }
}
