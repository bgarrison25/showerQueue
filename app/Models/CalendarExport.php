<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['synced_at' => 'immutable_datetime'];
    }
}
