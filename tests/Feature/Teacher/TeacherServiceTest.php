<?php

use App\Exceptions\TeacherCannotBeDeletedException;
use App\Exceptions\TeacherDisplayNameConflictException;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(TeacherService::class);
});

test('create teacher sets user_id and name', function (): void {
    $teacher = $this->service->createTeacher($this->user, ['name' => 'Dr. Jones']);

    expect($teacher)->toBeInstanceOf(Teacher::class)
        ->and($teacher->user_id)->toBe($this->user->id)
        ->and($teacher->name)->toBe('Dr. Jones')
        ->and($teacher->name_normalized)->toBe(Teacher::normalizeDisplayName('Dr. Jones'))
        ->and($teacher->exists)->toBeTrue();
});

test('update teacher updates name and does not allow user_id override', function (): void {
    $teacher = Teacher::factory()->for($this->user)->create(['name' => 'Original']);
    $otherUser = User::factory()->create();

    $updated = $this->service->updateTeacher($teacher, [
        'name' => 'Updated name',
        'user_id' => $otherUser->id,
    ]);

    expect($updated->name)->toBe('Updated name')
        ->and($teacher->fresh()->name)->toBe('Updated name')
        ->and($teacher->fresh()->user_id)->toBe($this->user->id);
});

test('update teacher throws when normalized name conflicts with another teacher', function (): void {
    Teacher::factory()->for($this->user)->create(['name' => 'Alpha']);
    $beta = Teacher::factory()->for($this->user)->create(['name' => 'Beta']);

    $this->service->updateTeacher($beta, ['name' => 'ALPHA']);
})->throws(TeacherDisplayNameConflictException::class);

test('delete teacher removes teacher from database when not referenced', function (): void {
    $teacher = Teacher::factory()->for($this->user)->create();

    $result = $this->service->deleteTeacher($teacher);

    expect($result)->toBeTrue()
        ->and(Teacher::find($teacher->id))->toBeNull();
});

test('delete teacher throws when school class references teacher', function (): void {
    $teacher = Teacher::factory()->for($this->user)->create();
    SchoolClass::factory()->for($this->user)->create(['teacher_id' => $teacher->id]);

    $this->service->deleteTeacher($teacher);
})->throws(TeacherCannotBeDeletedException::class);
