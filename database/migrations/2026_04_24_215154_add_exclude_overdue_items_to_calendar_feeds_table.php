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
        Schema::table('calendar_feeds', function (Blueprint $table) {
            $table->boolean('exclude_overdue_items')
                ->default(false)
                ->after('sync_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_feeds', function (Blueprint $table) {
            $table->dropColumn('exclude_overdue_items');
        });
    }
};
