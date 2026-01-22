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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['to_do', 'doing', 'done'])->nullable()->default('to_do');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->nullable()->default('medium');
            $table->enum('complexity', ['simple', 'moderate', 'complex'])->nullable()->default('moderate');
            $table->integer('duration')->nullable();
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
