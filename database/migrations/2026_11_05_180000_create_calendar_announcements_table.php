<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_announcements', function (Blueprint $table) {
            $table->id();
            $table->char('calendar_key', 64);
            $table->char('event_key', 64);
            $table->timestamps();
            $table->unique(['calendar_key', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_announcements');
    }
};
