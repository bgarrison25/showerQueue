<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    public function test_two_real_processes_competing_for_one_slot_produce_one_winner(): void
    {
        $this->race(false);
    }

    public function test_two_simultaneous_bookings_for_one_person_produce_one_winner(): void
    {
        $this->race(true);
    }

    private function race(bool $sameUser): void
    {
        $path = tempnam(sys_get_temp_dir(), 'showerqueue-race-');
        $original = config('database.default');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path, 'webpush.database_connection' => 'sqlite']); DB::purge('sqlite');
        $workers = [];
        try {
            Artisan::call('migrate', ['--force' => true]);
            $users = User::factory()->count(2)->create();
            $iso = CarbonImmutable::now('America/Toronto')->addDay()->setTime(6, 0)->utc()->toIso8601String();
            foreach ($users as $i => $user) {
                $owner = $sameUser ? $users[0]->id : $user->id;
                $time = $sameUser ? CarbonImmutable::parse($iso)->addMinutes($i * 20)->toIso8601String() : $iso;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/booking-worker.php'), (string) $owner, $time, "$path.ready$i", "$path.go"], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4']);
                $worker->setTimeout(25); $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (!file_exists("$path.ready0") || !file_exists("$path.ready1")) { if (microtime(true) > $deadline) $this->fail('Booking workers failed to initialize.'); usleep(10000); }
            touch("$path.go"); foreach ($workers as $worker) $worker->wait();
            $codes = array_map(fn ($worker) => $worker->getExitCode(), $workers); sort($codes);
            $this->assertSame([0, 3], $codes, implode("\n", array_map(fn ($p) => $p->getErrorOutput().$p->getOutput(), $workers)));
            $this->assertSame(1, Reservation::active()->count()); $this->assertSame(20, DB::table('reservation_minutes')->count());
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            DB::purge('sqlite'); config(['database.default' => $original]);
            foreach (['', '-wal', '-shm', '.ready0', '.ready1', '.go'] as $suffix) if (is_file($path.$suffix)) unlink($path.$suffix);
        }
    }
}
