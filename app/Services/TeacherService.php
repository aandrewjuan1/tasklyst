<?php

namespace App\Services;

use App\Exceptions\TeacherCannotBeDeletedException;
use App\Exceptions\TeacherDisplayNameConflictException;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeacherService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTeacher(User $user, array $attributes): Teacher
    {
        return DB::transaction(function () use ($user, $attributes): Teacher {
            return Teacher::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTeacher(Teacher $teacher, array $attributes): Teacher
    {
        unset($attributes['user_id']);

        $name = $attributes['name'] ?? null;
        if ($name !== null && is_string($name)) {
            $normalized = Teacher::normalizeDisplayName($name);
            $conflictExists = Teacher::query()
                ->forUser((int) $teacher->user_id)
                ->where('name_normalized', $normalized)
                ->whereKeyNot($teacher->id)
                ->exists();

            if ($conflictExists) {
                throw TeacherDisplayNameConflictException::make();
            }
        }

        return DB::transaction(function () use ($teacher, $attributes): Teacher {
            $teacher->fill($attributes);
            $teacher->save();

            return $teacher->fresh();
        });
    }

    /**
     * @throws TeacherCannotBeDeletedException
     */
    public function deleteTeacher(Teacher $teacher): bool
    {
        if (SchoolClass::query()->where('teacher_id', $teacher->id)->exists()) {
            throw TeacherCannotBeDeletedException::make();
        }

        return DB::transaction(function () use ($teacher): bool {
            return (bool) $teacher->delete();
        });
    }
}
