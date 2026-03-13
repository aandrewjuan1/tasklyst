<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system', 'tool', 'meta']);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content_text');
            $table->json('content_json')->nullable();
            $table->text('llm_raw')->nullable();
            $table->json('meta')->nullable();
            $table->string('client_request_id')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
