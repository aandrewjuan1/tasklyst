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
        Schema::create('school_class_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->date('instance_date');
            $table->enum('status', ['scheduled', 'cancelled', 'completed', 'tentative', 'ongoing']);
            $table->boolean('cancelled')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_class_instances');
    }
};
