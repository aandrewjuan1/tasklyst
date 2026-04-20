<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the overly broad unique index on (collaboratable, invitee_email, status).
     *
     * The previous index prevented multiple rows with status "declined" for the same
     * collaboratable and invitee, so re-inviting after a decline failed on the second decline.
     * Pending invites are still deduped: at most one pending row per collaboratable + email.
     */
    public function up(): void
    {
        Schema::table('collaboration_invitations', function (Blueprint $table) {
            $table->dropUnique('collaboration_invitations_pending_unique');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX collaboration_invitations_pending_unique ON collaboration_invitations (collaboratable_type, collaboratable_id, invitee_email) WHERE status = 'pending'"
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('collaboration_invitations', function (Blueprint $table) {
                $table->string('pending_invitee_key', 512)->nullable()->storedAs(
                    "CASE WHEN `status` = 'pending' THEN CONCAT(`collaboratable_type`, ':', `collaboratable_id`, ':', LOWER(`invitee_email`)) ELSE NULL END"
                );
            });
            Schema::table('collaboration_invitations', function (Blueprint $table) {
                $table->unique('pending_invitee_key', 'collaboration_invitations_pending_unique');
            });

            return;
        }

        throw new \RuntimeException(
            "Unsupported database driver [{$driver}] for collaboration invitation pending dedupe. Extend this migration for your driver."
        );
    }

    /**
     * Drops the partial / generated-column unique. Does not restore the old four-column unique,
     * because doing so would fail if multiple declined rows exist for the same invitee.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS collaboration_invitations_pending_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('collaboration_invitations', function (Blueprint $table) {
                $table->dropUnique('collaboration_invitations_pending_unique');
            });
            Schema::table('collaboration_invitations', function (Blueprint $table) {
                $table->dropColumn('pending_invitee_key');
            });
        }
    }
};
