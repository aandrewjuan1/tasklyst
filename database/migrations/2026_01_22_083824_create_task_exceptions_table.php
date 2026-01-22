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
        Schema::create('task_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_task_id')->constrained()->cascadeOnDelete();
            $table->date('exception_date');
            $table->boolean('is_deleted')->default(false);
            $table->foreignId('replacement_instance_id')->nullable()->constrained('task_instances')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_task_id', 'exception_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_exceptions');
    }
};
