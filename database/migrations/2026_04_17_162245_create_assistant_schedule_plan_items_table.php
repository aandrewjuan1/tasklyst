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
        Schema::create('assistant_schedule_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_schedule_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('proposal_uuid', 100);
            $table->string('proposal_id', 100)->nullable();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->string('title', 200);
            $table->timestamp('planned_start_at')->index();
            $table->timestamp('planned_end_at')->nullable()->index();
            $table->unsignedInteger('planned_duration_minutes')->nullable();
            $table->string('status', 24)->default('planned')->index();
            $table->timestamp('accepted_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['assistant_schedule_plan_id', 'proposal_uuid'], 'assistant_schedule_plan_item_unique_proposal');
            $table->index(['user_id', 'status', 'planned_start_at']);
            $table->index(['user_id', 'entity_type', 'entity_id']);
            $table->index(['user_id', 'accepted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_schedule_plan_items');
    }
};
