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
        // Tasks: optimize user-scoped + date queries and overdue lookups.
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['user_id', 'completed_at'], 'tasks_user_completed_index');
            $table->index(['user_id', 'start_datetime', 'end_datetime'], 'tasks_user_start_end_index');
            $table->index('end_datetime', 'tasks_end_datetime_index');
        });

        // Events: optimize user-scoped, status, and date range queries.
        Schema::table('events', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'events_user_status_index');
            $table->index(['user_id', 'start_datetime', 'end_datetime'], 'events_user_start_end_index');
            $table->index('end_datetime', 'events_end_datetime_index');
        });

        // Projects: optimize user-scoped active/archived and date range queries.
        Schema::table('projects', function (Blueprint $table): void {
            $table->index(['user_id', 'deleted_at'], 'projects_user_deleted_index');
            $table->index(['user_id', 'start_datetime', 'end_datetime'], 'projects_user_start_end_index');
        });

        // Task instances: optimize lookups by recurring task and instance date.
        Schema::table('task_instances', function (Blueprint $table): void {
            $table->index('recurring_task_id', 'task_instances_recurring_task_id_index');
            $table->index(['recurring_task_id', 'instance_date'], 'task_instances_recurring_date_index');
        });

        // Event instances: optimize lookups by recurring event and instance date.
        Schema::table('event_instances', function (Blueprint $table): void {
            $table->index('recurring_event_id', 'event_instances_recurring_event_id_index');
            $table->index(['recurring_event_id', 'instance_date'], 'event_instances_recurring_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_user_completed_index');
            $table->dropIndex('tasks_user_start_end_index');
            $table->dropIndex('tasks_end_datetime_index');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_user_status_index');
            $table->dropIndex('events_user_start_end_index');
            $table->dropIndex('events_end_datetime_index');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('projects_user_deleted_index');
            $table->dropIndex('projects_user_start_end_index');
        });

        Schema::table('task_instances', function (Blueprint $table): void {
            $table->dropIndex('task_instances_recurring_task_id_index');
            $table->dropIndex('task_instances_recurring_date_index');
        });

        Schema::table('event_instances', function (Blueprint $table): void {
            $table->dropIndex('event_instances_recurring_event_id_index');
            $table->dropIndex('event_instances_recurring_date_index');
        });
    }
};
