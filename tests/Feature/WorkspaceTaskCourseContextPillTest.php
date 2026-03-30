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

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'PillCourse')
        ->assertSee('PillCourseTask')
        ->assertSee('CS 220 UniqueSubjectPill')
        ->assertSee('Prof UniqueTeacherPill');
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

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('searchQuery', 'SubjectOnlyPill')
        ->assertSee('SubjectOnlyPillTask')
        ->assertSee('OnlySubjectPillValue');
});
