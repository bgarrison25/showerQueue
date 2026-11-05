<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('showerqueue:tick', function () {
    app(\App\Services\ReminderService::class)->run();
    $this->info('Timers, swap expiry, and reminders checked.');
})->purpose('Process upcoming reminders and expire swap requests');

Artisan::command('showerqueue:recurrences', function () {
    foreach (\App\Models\RecurringPattern::where('enabled', true)->get() as $pattern) app(\App\Services\RecurrenceService::class)->materialize($pattern);
    $this->info('Recurring reservations checked for the next 30 days.');
})->purpose('Materialize upcoming parent-created recurring reservations');

Schedule::command('showerqueue:tick')->everyMinute()->withoutOverlapping();
Schedule::command('showerqueue:recurrences')->dailyAt('00:05')->timezone('UTC')->withoutOverlapping();
