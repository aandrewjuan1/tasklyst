<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recurring_tasks DROP CONSTRAINT IF EXISTS recurring_tasks_recurrence_type_check');
            DB::statement("ALTER TABLE recurring_tasks ADD CONSTRAINT recurring_tasks_recurrence_type_check CHECK (recurrence_type IN ('daily', 'weekly', 'monthly', 'yearly'))");

            return;
        }

        Schema::table('recurring_tasks', function (Blueprint $table) {
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recurring_tasks DROP CONSTRAINT IF EXISTS recurring_tasks_recurrence_type_check');
            DB::statement("ALTER TABLE recurring_tasks ADD CONSTRAINT recurring_tasks_recurrence_type_check CHECK (recurrence_type IN ('daily', 'weekly', 'monthly'))");

            return;
        }

        Schema::table('recurring_tasks', function (Blueprint $table) {
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly'])->change();
        });
    }
};
