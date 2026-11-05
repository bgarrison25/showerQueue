<?php

// Integration-test subprocess: never serves HTTP and never creates credentials.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $userId, $iso, $readyPath, $goPath] = $argv;
touch($readyPath);
$deadline = microtime(true) + 15;
while (!file_exists($goPath)) { if (microtime(true) > $deadline) exit(4); usleep(10000); }
try {
    $user = \App\Models\User::findOrFail((int) $userId);
    app(\App\Services\BookingService::class)->book($user, $user->id, $iso);
    exit(0);
} catch (\Illuminate\Validation\ValidationException) { exit(3); }
