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
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_normalized');
            $table->timestamps();

            $table->unique(['user_id', 'name_normalized']);
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('user_id')->constrained('teachers')->restrictOnDelete();
        });

        $this->backfillTeachersAndSchoolClasses();

        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('teacher_name');
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable(false)->change();
        });

        DB::table('tasks')->whereNotNull('school_class_id')->update(['teacher_name' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('teacher_name')->after('subject_name');
        });

        if (Schema::hasTable('teachers')) {
            $classes = DB::table('school_classes')->orderBy('id')->get();
            foreach ($classes as $row) {
                $name = DB::table('teachers')->where('id', $row->teacher_id)->value('name');
                if ($name !== null) {
                    DB::table('school_classes')->where('id', $row->id)->update(['teacher_name' => $name]);
                }
            }
        }

        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn('teacher_id');
        });

        Schema::dropIfExists('teachers');
    }

    private function backfillTeachersAndSchoolClasses(): void
    {
        $rows = DB::table('school_classes')->select('id', 'user_id', 'teacher_name')->orderBy('id')->get();

        foreach ($rows as $row) {
            $displayName = trim((string) $row->teacher_name);
            $normalized = mb_strtolower($displayName);

            if ($normalized === '') {
                throw new \RuntimeException('School class id '.$row->id.' has empty teacher_name; cannot backfill.');
            }

            $existingId = DB::table('teachers')
                ->where('user_id', $row->user_id)
                ->where('name_normalized', $normalized)
                ->value('id');

            if ($existingId === null) {
                $now = now();
                $existingId = DB::table('teachers')->insertGetId([
                    'user_id' => $row->user_id,
                    'name' => $displayName,
                    'name_normalized' => $normalized,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('school_classes')->where('id', $row->id)->update(['teacher_id' => $existingId]);
        }
    }
};
