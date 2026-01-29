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
        Schema::table('tags', function (Blueprint $table) {
            // Drop the unique constraint on name
            $table->dropUnique(['name']);

            // Delete existing tags since they're not user-scoped
            DB::table('tags')->delete();

            // Add user_id column
            $table->foreignId('user_id')->after('id')->constrained()->onDelete('cascade');

            // Add unique constraint on (user_id, name) combination
            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            // Drop the unique constraint on (user_id, name)
            $table->dropUnique(['user_id', 'name']);

            // Drop foreign key and user_id column
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            // Restore unique constraint on name
            $table->unique('name');
        });
    }
};
