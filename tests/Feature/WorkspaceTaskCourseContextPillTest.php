<?php

use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace task list shows course context pill when subject and teacher are set', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'PillCourseTask',
        'subject_name' => 'CS 220 UniqueSubjectPill',
        'teacher_name' => 'Prof UniqueTeacherPill',
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'PillCourse');

    $task = $component->instance()->tasks()->firstWhere('title', 'PillCourseTask');
    expect($task)->not->toBeNull()
        ->and($task->subject_name)->toBe('CS 220 UniqueSubjectPill')
        ->and($task->teacher_name)->toBe('Prof UniqueTeacherPill');
});

test('workspace task list shows course context pill with subject only when teacher is empty', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'SubjectOnlyPillTask',
        'subject_name' => 'OnlySubjectPillValue',
        'teacher_name' => null,
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'SubjectOnlyPill');

    $task = $component->instance()->tasks()->firstWhere('title', 'SubjectOnlyPillTask');
    expect($task)->not->toBeNull()
        ->and($task->subject_name)->toBe('OnlySubjectPillValue')
        ->and($task->teacher_name)->toBeNull();
});
