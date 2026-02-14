<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pomodoro_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('work_duration_minutes')->default(25);
            $table->unsignedSmallInteger('short_break_minutes')->default(5);
            $table->unsignedSmallInteger('long_break_minutes')->default(15);
            $table->unsignedSmallInteger('long_break_after_pomodoros')->default(4);
            $table->boolean('auto_start_break')->default(false);
            $table->boolean('auto_start_pomodoro')->default(false);
            $table->boolean('sound_enabled')->default(true);
            $table->unsignedTinyInteger('sound_volume')->default(80);
            $table->boolean('notification_on_complete')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pomodoro_settings');
    }
};
