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
        $dedupeSourceQuery = <<<'SQL'
                SELECT
                    remindable_type,
                    remindable_id,
                    type,
                    scheduled_at,
                    status,
                    MIN(id) as keep_id,
                    COUNT(*) as duplicate_count
                FROM reminders
                WHERE status = 'pending'
                GROUP BY remindable_type, remindable_id, type, scheduled_at, status
                HAVING COUNT(*) > 1
            SQL;

        $duplicateIds = DB::table('reminders as r')
            ->join(DB::raw("({$dedupeSourceQuery}) d"), function ($join): void {
                $join->on('r.remindable_type', '=', 'd.remindable_type')
                    ->on('r.remindable_id', '=', 'd.remindable_id')
                    ->on('r.type', '=', 'd.type')
                    ->on('r.scheduled_at', '=', 'd.scheduled_at')
                    ->on('r.status', '=', 'd.status');
            })
            ->whereColumn('r.id', '<>', 'd.keep_id')
            ->orderBy('r.id')
            ->pluck('r.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('reminders')
                ->whereIn('id', $duplicateIds->all())
                ->delete();
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->unique(
                ['remindable_type', 'remindable_id', 'type', 'scheduled_at', 'status'],
                'reminders_pending_schedule_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropUnique('reminders_pending_schedule_unique');
        });
    }
};
