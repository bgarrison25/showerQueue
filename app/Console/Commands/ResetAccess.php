<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HouseholdLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetAccess extends Command
{
    protected $signature = 'showerqueue:reset-access {name}';
    protected $description = 'Reset one member with a generated one-time password (server operator only)';
    public function handle(HouseholdLock $lock): int
    {
        $password = Str::random(24);
        $user = $lock->run(function () use ($password) {
            $user = User::where('name', $this->argument('name'))->firstOrFail();
            $user->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => null, 'is_active' => true])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->pushSubscriptions()->delete();
            return $user;
        });
        $this->info($user->name.' — one-time password: '.$password);
        return self::SUCCESS;
    }
}
