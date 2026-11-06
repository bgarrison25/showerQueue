<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class ProfileLink extends Component
{
    #[On('profile-updated')]
    public function refreshProfile(): void {}

    public function render()
    {
        return view('livewire.profile-link', ['user' => auth()->user()->fresh()]);
    }
}
