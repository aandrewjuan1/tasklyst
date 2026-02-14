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
        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('focusable_type')->nullable();
            $table->unsignedBigInteger('focusable_id')->nullable();
            $table->string('type');
            $table->unsignedSmallInteger('sequence_number')->default(1);
            $table->unsignedInteger('duration_seconds');
            $table->boolean('completed')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::table('focus_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'started_at']);
            $table->index(['focusable_type', 'focusable_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
    }
};
