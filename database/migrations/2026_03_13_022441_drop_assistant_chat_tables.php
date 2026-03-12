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
        if (Schema::hasTable('assistant_messages')) {
            Schema::drop('assistant_messages');
        }

        if (Schema::hasTable('assistant_threads')) {
            Schema::drop('assistant_threads');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('assistant_threads')) {
            Schema::create('assistant_threads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index(['user_id', 'updated_at']);
            });
        }

        if (! Schema::hasTable('assistant_messages')) {
            Schema::create('assistant_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assistant_thread_id')->constrained()->cascadeOnDelete();
                $table->string('role');
                $table->text('content');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('assistant_thread_id');
                $table->index(['assistant_thread_id', 'created_at']);
            });
        }
    }
};
