<?php

use App\Exceptions\TeacherDisplayNameConflictException;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherService;
use Illuminate\Validation\ValidationException;

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

    expect($result['deleted'])->toBeTrue()
        ->and($result['affectedClassCount'])->toBe(0)
        ->and(Teacher::find($teacher->id))->toBeNull();
});

test('delete teacher unassigns school classes and deletes teacher when referenced', function (): void {
    $teacher = Teacher::factory()->for($this->user)->create();
    $class = SchoolClass::factory()->for($this->user)->create(['teacher_id' => $teacher->id]);

    $result = $this->service->deleteTeacher($teacher);

    expect($result['deleted'])->toBeTrue()
        ->and($result['affectedClassCount'])->toBe(1)
        ->and(Teacher::find($teacher->id))->toBeNull()
        ->and($class->fresh()->teacher_id)->toBeNull();
});

test('create teacher rejects names longer than max length', function (): void {
    $this->service->createTeacher($this->user, [
        'name' => str_repeat('a', Teacher::MAX_NAME_LENGTH + 1),
    ]);
})->throws(ValidationException::class);
