<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HouseholdLock;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetupHousehold extends Command
{
    protected $signature = 'showerqueue:setup';
    protected $description = 'Create the six household accounts and show one-time credentials in this terminal';

    public function handle(HouseholdLock $lock): int
    {
        $credentials = $lock->run(function () {
            if (User::exists()) return null;
            $rows = [];
            foreach (['Bill' => ['teal', true], 'Aleasha' => ['rose', true], 'Liam' => ['blue', false], 'Haydon' => ['amber', false], 'Elliana' => ['violet', false], 'Anabelle' => ['pink', false]] as $name => [$color, $admin]) {
                $password = Str::random(24);
                User::forceCreate(['name' => $name, 'email' => strtolower($name).'@household.invalid', 'password' => $password, 'is_admin' => $admin, 'color' => $color, 'must_change_password' => true]);
                $rows[] = [$name, $admin ? 'Parent' : 'Member', $password];
            }
            return $rows;
        });
        if (!$credentials) { $this->warn('Accounts already exist. Use the parent dashboard or showerqueue:reset-access.'); return self::FAILURE; }
        $this->info('Store these credentials in your password manager. They are shown only once. Each member must choose a new password or PIN at first sign-in.');
        $this->table(['Member', 'Access', 'One-time password'], $credentials);
        return self::SUCCESS;
    }
}
