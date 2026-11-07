<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\ProfilePhotoService;
use Livewire\Component;
use Livewire\WithFileUploads;

class Profile extends Component
{
    use WithFileUploads;

    public string $firstName = '';
    public string $lastName = '';
    public string $nickname = '';
    public string $notice = '';
    public $photo;

    public function mount(): void
    {
        $user = auth()->user();
        $this->firstName = $user->profileFirstName();
        $this->lastName = $user->profileLastName();
        $this->nickname = $user->nickname ?? '';
    }

    public function saveProfile(): void
    {
        $plainText = 'not_regex:/[\x00-\x1F\x7F<>]/u';
        $this->validate([
            'firstName' => ['required', 'string', 'max:60', $plainText],
            'lastName' => ['nullable', 'string', 'max:80', $plainText],
            'nickname' => ['nullable', 'string', 'max:30', $plainText],
        ], ['*.not_regex' => 'Use plain text without angle brackets or control characters.']);
        $this->firstName = preg_replace('/\s+/u', ' ', trim($this->firstName));
        $this->lastName = preg_replace('/\s+/u', ' ', trim($this->lastName));
        $this->nickname = preg_replace('/\s+/u', ' ', trim($this->nickname));
        if ($this->photo) {
            $this->savePhoto();
        }
        // The account's sign-in name and permissions are deliberately not editable here.
        auth()->user()->forceFill([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'nickname' => $this->nickname !== '' ? $this->nickname : null,
        ])->save();
        $this->notice = 'Your profile is saved. Your household will see your updated name and photo.';
        $this->dispatch('profile-updated');
    }

    public function savePhoto(): void
    {
        $this->validate(['photo' => 'required|file']);
        app(ProfilePhotoService::class)->save(auth()->user(), $this->photo);
        $this->discardPhoto();
        auth()->user()->refresh();
        $this->notice = 'Your profile photo is saved. It now appears throughout the household schedule.';
        $this->dispatch('profile-photo-saved');
        $this->dispatch('profile-updated');
    }

    public function discardPhoto(): void
    {
        if ($this->photo instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) $this->photo->delete();
        $this->reset('photo');
        $this->resetValidation('photo');
    }

    public function removePhoto(): void
    {
        app(ProfilePhotoService::class)->remove(auth()->user());
        $this->discardPhoto();
        auth()->user()->refresh();
        $this->notice = 'Profile photo removed. Your initials are back.';
        $this->dispatch('profile-photo-saved');
        $this->dispatch('profile-updated');
    }

    public function render()
    {
        return view('livewire.profile', [
            'displayPreview' => User::formatDisplayName($this->firstName, $this->nickname, $this->lastName),
        ])->layout('layouts.app', ['title' => 'Your profile']);
    }
}
