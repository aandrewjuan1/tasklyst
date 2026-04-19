<?php

use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create teacher with valid name creates teacher in database', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('createTeacher', 'Ms. Example');

    $teacher = Teacher::query()->where('user_id', $this->owner->id)->where('name', 'Ms. Example')->first();
    expect($teacher)->not->toBeNull()
        ->and($teacher->user_id)->toBe($this->owner->id);
});

test('create teacher with empty name does not create teacher', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Teacher::query()->where('user_id', $this->owner->id)->count();

    Livewire::test('pages::workspace.index')
        ->call('createTeacher', '');

    expect(Teacher::query()->where('user_id', $this->owner->id)->count())->toBe($countBefore);
});

test('owner can delete teacher and teacher is removed', function (): void {
    $this->actingAs($this->owner);
    $teacher = Teacher::factory()->for($this->owner)->create(['name' => 'To remove']);
    $teacherId = $teacher->id;

    Livewire::test('pages::workspace.index')
        ->call('deleteTeacher', $teacherId);

    expect(Teacher::find($teacherId))->toBeNull();
});

test('delete teacher fails when school class references teacher', function (): void {
    $this->actingAs($this->owner);
    $teacher = Teacher::factory()->for($this->owner)->create(['name' => 'In use']);
    SchoolClass::factory()->for($this->owner)->create(['teacher_id' => $teacher->id]);

    Livewire::test('pages::workspace.index')
        ->call('deleteTeacher', $teacher->id);

    expect(Teacher::find($teacher->id))->not->toBeNull();
});

test('delete teacher with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Teacher::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteTeacher', 99999);

    expect(Teacher::query()->count())->toBe($countBefore);
});

test('other user cannot delete teacher not owned by them', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create(['name' => 'Owner teacher']);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteTeacher', $teacher->id);

    expect(Teacher::find($teacher->id))->not->toBeNull();
});

test('owner can update teacher name', function (): void {
    $this->actingAs($this->owner);
    $teacher = Teacher::factory()->for($this->owner)->create(['name' => 'Old']);

    Livewire::test('pages::workspace.index')
        ->call('updateTeacher', $teacher->id, 'New Name');

    expect($teacher->fresh()->name)->toBe('New Name');
});

test('update teacher with conflicting name shows error and does not change', function (): void {
    $this->actingAs($this->owner);
    Teacher::factory()->for($this->owner)->create(['name' => 'Taken']);
    $other = Teacher::factory()->for($this->owner)->create(['name' => 'Other']);

    Livewire::test('pages::workspace.index')
        ->call('updateTeacher', $other->id, 'TAKEN');

    expect($other->fresh()->name)->toBe('Other');
});

test('teachers computed returns only authenticated user teachers', function (): void {
    $t1 = Teacher::factory()->for($this->owner)->create(['name' => 'A']);
    Teacher::factory()->for($this->otherUser)->create(['name' => 'B']);
    $this->actingAs($this->owner);

    $component = Livewire::test('pages::workspace.index');
    $teachers = $component->get('teachers');

    expect($teachers)->toHaveCount(1)
        ->and($teachers->first()->id)->toBe($t1->id)
        ->and($teachers->first()->name)->toBe('A');
});
