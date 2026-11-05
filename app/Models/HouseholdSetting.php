<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HouseholdSetting extends Model
{
    protected $guarded = ['id', 'lock_version'];

    protected function casts(): array
    {
        return ['restrict_absent' => 'boolean', 'notification_defaults' => 'array', 'slot_minutes' => 'integer', 'morning_limit' => 'integer', 'reminder_minutes' => 'integer'];
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }
}
