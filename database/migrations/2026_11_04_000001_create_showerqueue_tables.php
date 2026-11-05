<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->string('color')->default('teal');
            $table->json('notification_preferences')->nullable();
        });
        Schema::create('household_settings', function (Blueprint $table) {
            $table->id();
            $table->string('timezone')->default('America/Toronto');
            $table->unsignedSmallInteger('slot_minutes')->default(20);
            $table->string('opens_at', 5)->default('06:00');
            $table->string('closes_at', 5)->default('23:00');
            $table->date('weekend_reference')->default('2026-11-06');
            $table->boolean('restrict_absent')->default(false);
            $table->unsignedTinyInteger('morning_limit')->nullable();
            $table->unsignedTinyInteger('reminder_minutes')->default(5);
            $table->json('notification_defaults')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
        });
        DB::table('household_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->boolean('is_home');
            $table->string('note', 200)->nullable();
            $table->unique(['user_id', 'day']);
            $table->timestamps();
        });
        Schema::create('recurring_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->json('weekdays');
            $table->string('time', 5);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('recurring_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->date('day')->index();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('status')->default('booked');
            $table->boolean('is_override')->default(false);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $driver = DB::getDriverName();
            $key = $driver === 'sqlite'
                ? "CASE WHEN status <> 'cancelled' AND is_override = 0 THEN CAST(user_id AS TEXT) || ':' || day ELSE NULL END"
                : "CASE WHEN status <> 'cancelled' AND is_override = 0 THEN CONCAT(user_id, ':', day) ELSE NULL END";
            $table->string('daily_key')->nullable()->storedAs($key)->unique();
            $table->unique(['recurring_pattern_id', 'day']);
        });
        // Exact UTC minute claims provide a portable database uniqueness backstop.
        Schema::create('reservation_minutes', function (Blueprint $table) {
            $table->dateTime('minute')->primary();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_reservation_id')->constrained('reservations');
            $table->foreignId('to_reservation_id')->constrained('reservations');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('recipient_id')->constrained('users');
            $table->unsignedInteger('from_revision');
            $table->unsignedInteger('to_revision');
            $table->string('status')->default('pending')->index();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('recurrence_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_pattern_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('result');
            $table->string('message')->nullable();
            $table->unique(['recurring_pattern_id', 'day']);
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('event_key', 150);
            $table->unique(['user_id', 'event_key']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notifications', 'recurrence_results', 'swap_requests', 'reservation_minutes', 'reservations', 'recurring_patterns', 'attendance_overrides', 'household_settings'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'is_active', 'must_change_password', 'color', 'notification_preferences']));
    }
};
