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
        Schema::table('recurring_tasks', function (Blueprint $table): void {
            $table->dateTime('start_datetime')->nullable()->change();
        });

        Schema::table('recurring_events', function (Blueprint $table): void {
            $table->dateTime('start_datetime')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_tasks', function (Blueprint $table): void {
            $table->dateTime('start_datetime')->nullable(false)->change();
        });

        Schema::table('recurring_events', function (Blueprint $table): void {
            $table->dateTime('start_datetime')->nullable(false)->change();
        });
    }
};
