<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->char('calendar_key', 64);
            $table->string('event_id', 64);
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['reservation_id', 'calendar_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_exports');
    }
};
