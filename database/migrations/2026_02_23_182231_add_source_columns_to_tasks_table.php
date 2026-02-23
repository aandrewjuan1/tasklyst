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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('complexity');
            $table->string('source_id')->nullable()->after('source_type');
            $table->foreignId('calendar_feed_id')
                ->nullable()
                ->after('event_id')
                ->constrained('calendar_feeds')
                ->nullOnDelete();

            $table->unique(['user_id', 'source_type', 'source_id'], 'tasks_user_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_user_source_unique');

            $table->dropConstrainedForeignId('calendar_feed_id');

            $table->dropColumn([
                'source_type',
                'source_id',
            ]);
        });
    }
};
