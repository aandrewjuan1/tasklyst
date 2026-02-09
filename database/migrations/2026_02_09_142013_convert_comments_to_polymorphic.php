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
        Schema::table('comments', function (Blueprint $table) {
            $table->unsignedBigInteger('commentable_id')->nullable()->after('id');
            $table->string('commentable_type')->nullable()->after('commentable_id');
        });

        // Migrate existing data: all existing comments are for tasks
        DB::table('comments')->update([
            'commentable_id' => DB::raw('task_id'),
            'commentable_type' => 'App\Models\Task',
        ]);

        Schema::table('comments', function (Blueprint $table) {
            $table->unsignedBigInteger('commentable_id')->nullable(false)->change();
            $table->string('commentable_type')->nullable(false)->change();

            // Drop old foreign key and index
            $table->dropForeign(['task_id']);
            $table->dropIndex(['task_id', 'created_at']);

            // Drop old column
            $table->dropColumn('task_id');

            // Add new indexes
            $table->index(['commentable_id', 'commentable_type', 'created_at']);
            $table->index(['commentable_id', 'commentable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // Add task_id column back
            $table->foreignId('task_id')->nullable()->after('id');
        });

        // Migrate data back: only migrate Task comments
        DB::table('comments')
            ->where('commentable_type', 'App\Models\Task')
            ->update([
                'task_id' => DB::raw('commentable_id'),
            ]);

        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable(false)->change();

            // Drop polymorphic indexes
            $table->dropIndex(['commentable_id', 'commentable_type', 'created_at']);
            $table->dropIndex(['commentable_id', 'commentable_type']);

            // Drop polymorphic columns
            $table->dropColumn(['commentable_id', 'commentable_type']);

            // Restore old foreign key and index
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->index(['task_id', 'created_at']);
        });
    }
};
