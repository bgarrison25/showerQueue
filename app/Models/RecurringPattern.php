<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringPattern extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['weekdays' => 'array', 'enabled' => 'boolean']; }
    public function user() { return $this->belongsTo(User::class); }
}
