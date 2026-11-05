<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $guarded = ['id', 'daily_key'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'is_override' => 'boolean'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function scopeActive(Builder $query): void { $query->where('status', '<>', 'cancelled'); }
}
