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
                    collaboratable_type,
                    collaboratable_id,
                    invitee_email,
                    status,
                    MIN(id) as keep_id,
                    COUNT(*) as duplicate_count
                FROM collaboration_invitations
                WHERE status = 'pending'
                GROUP BY collaboratable_type, collaboratable_id, invitee_email, status
                HAVING COUNT(*) > 1
            SQL;

        $duplicateIds = DB::table('collaboration_invitations as i')
            ->join(DB::raw("({$dedupeSourceQuery}) d"), function ($join): void {
                $join->on('i.collaboratable_type', '=', 'd.collaboratable_type')
                    ->on('i.collaboratable_id', '=', 'd.collaboratable_id')
                    ->on('i.invitee_email', '=', 'd.invitee_email')
                    ->on('i.status', '=', 'd.status');
            })
            ->whereColumn('i.id', '<>', 'd.keep_id')
            ->orderBy('i.id')
            ->pluck('i.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('collaboration_invitations')
                ->whereIn('id', $duplicateIds->all())
                ->delete();
        }

        Schema::table('collaboration_invitations', function (Blueprint $table) {
            $table->unique(
                ['collaboratable_type', 'collaboratable_id', 'invitee_email', 'status'],
                'collaboration_invitations_pending_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collaboration_invitations', function (Blueprint $table) {
            $table->dropUnique('collaboration_invitations_pending_unique');
        });
    }
};
