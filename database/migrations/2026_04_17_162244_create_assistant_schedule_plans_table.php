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
        Schema::create('assistant_schedule_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('task_assistant_threads')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('task_assistant_messages')->nullOnDelete();
            $table->string('source', 40)->default('assistant_accept_all');
            $table->timestamp('accepted_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'accepted_at']);
            $table->index(['thread_id', 'assistant_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_schedule_plans');
    }
};
