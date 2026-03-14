<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop tables in dependency order if they exist.
        if (Schema::hasTable('llm_tool_calls')) {
            Schema::drop('llm_tool_calls');
        }

        if (Schema::hasTable('chat_messages')) {
            Schema::drop('chat_messages');
        }

        if (Schema::hasTable('chat_threads')) {
            Schema::drop('chat_threads');
        }
    }

    public function down(): void
    {
        // Intentional no-op. These tables were part of the removed LLM module.
    }
};
