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
        Schema::table('recurring_events', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        DB::table('recurring_events')
            ->where('recurrence_type', 'custom')
            ->update(['recurrence_type' => 'daily']);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE recurring_events MODIFY recurrence_type ENUM('daily', 'weekly', 'monthly', 'yearly') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recurring_events DROP CONSTRAINT IF EXISTS recurring_events_recurrence_type_check');
            DB::statement("ALTER TABLE recurring_events ADD CONSTRAINT recurring_events_recurrence_type_check CHECK (recurrence_type IN ('daily', 'weekly', 'monthly', 'yearly'))");
        } elseif ($driver === 'sqlite') {
            // SQLite enum check alterations are not portable in-place.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE recurring_events MODIFY recurrence_type ENUM('daily', 'weekly', 'monthly', 'yearly', 'custom') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recurring_events DROP CONSTRAINT IF EXISTS recurring_events_recurrence_type_check');
            DB::statement("ALTER TABLE recurring_events ADD CONSTRAINT recurring_events_recurrence_type_check CHECK (recurrence_type IN ('daily', 'weekly', 'monthly', 'yearly', 'custom'))");
        } elseif ($driver === 'sqlite') {
            // SQLite enum check alterations are not portable in-place.
        }

        Schema::table('recurring_events', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->after('end_datetime');
        });
    }
};
