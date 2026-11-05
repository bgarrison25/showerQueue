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

Artisan::command('showerqueue:calendar-sync', function () {
    $google = app(\App\Services\GoogleCalendar::class);
    if (!$google->configured()) { $this->info('Google Calendar is not configured; nothing to sync.'); return 0; }
    try {
        $count = app(\App\Services\CalendarSyncService::class)->run();
        $this->info("Calendar checked: $count shower reservations, plus new family events and overlap alerts.");
        return 0;
    } catch (\App\Exceptions\CalendarException $e) {
        $this->error($e->getMessage());
        return 1;
    }
})->purpose('Sync showers, announce new family events, and check family-event overlaps');

Schedule::command('showerqueue:calendar-sync')->everyMinute()->withoutOverlapping(10);
