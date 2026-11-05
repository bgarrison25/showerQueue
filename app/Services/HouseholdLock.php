<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

class HouseholdLock
{
    public function run(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            // First statement is a write: SQLite obtains its RESERVED lock before reads;
            // InnoDB locks the same singleton row until commit. All writers share it.
            DB::table('household_settings')->where('id', 1)->increment('lock_version');
            return $callback();
        }, 5);
    }
}
