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
        Schema::create('task_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->date('instance_date');
            $table->enum('status', ['to_do', 'doing', 'done']);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_instances');
    }
};
