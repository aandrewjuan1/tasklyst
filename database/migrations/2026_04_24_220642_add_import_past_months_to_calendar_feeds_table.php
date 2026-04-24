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
            $table->unsignedTinyInteger('import_past_months')
                ->nullable()
                ->after('exclude_overdue_items');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_feeds', function (Blueprint $table) {
            $table->dropColumn('import_past_months');
        });
    }
};
