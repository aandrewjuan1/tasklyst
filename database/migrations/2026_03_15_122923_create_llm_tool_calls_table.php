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
        Schema::create('llm_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('task_assistant_threads')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('task_assistant_messages')->nullOnDelete();
            $table->string('tool_name');
            $table->json('params_json');
            $table->json('result_json')->nullable();
            $table->string('status')->default('pending');
            $table->string('operation_token')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_tool_calls');
    }
};
