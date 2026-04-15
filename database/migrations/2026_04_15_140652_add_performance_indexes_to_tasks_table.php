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
            $table->index(
                ['user_id', 'completed_at', 'end_datetime'],
                'tasks_user_completed_end_datetime_index'
            );

            $table->index(
                ['calendar_feed_id', 'source_type', 'updated_at'],
                'tasks_feed_source_updated_at_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_user_completed_end_datetime_index');
            $table->dropIndex('tasks_feed_source_updated_at_index');
        });
    }
};
