<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasPushSubscriptions;

    protected $attributes = ['is_admin' => false, 'is_active' => true, 'must_change_password' => true, 'color' => 'teal'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'profile_photo_path',
    ];

    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo_path
            ? route('profile-photo', ['user' => $this->id, 'v' => substr(hash('sha256', $this->profile_photo_path), 0, 16)], false)
            : null;
    }

    public function profileFirstName(): string
    {
        return $this->first_name ?? explode(' ', trim($this->name), 2)[0];
    }

    public function profileLastName(): string
    {
        return $this->last_name ?? (explode(' ', trim($this->name), 2)[1] ?? '');
    }

    public static function formatDisplayName(string $first, ?string $nickname, string $last): string
    {
        return implode(' ', array_filter([trim($first), trim($nickname ?? '') !== '' ? '“'.trim($nickname).'”' : '', trim($last)], fn ($part) => $part !== ''));
    }

    public function getDisplayNameAttribute(): string
    {
        return self::formatDisplayName($this->profileFirstName(), $this->nickname, $this->profileLastName());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }
}
