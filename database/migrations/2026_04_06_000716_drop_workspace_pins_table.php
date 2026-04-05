<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('workspace_pins');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pinning was removed; no table to restore.
    }
};
