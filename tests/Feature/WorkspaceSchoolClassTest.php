<?php

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('school class recurring selection is weekly-only in markup', function (): void {
    $html = Blade::render('<x-recurring-selection kind="schoolClass" />', []);

    expect($html)->toContain('weeklyOnly')
        ->and($html)->toContain(__('Weekly schedule'));
});

test('createSchoolClass creates a school class from workspace', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');
    $component->set('selectedDate', now()->toDateString());

    $component->call('createSchoolClass', [
        'scheduleMode' => 'one_off',
        'subjectName' => 'Calculus',
        'teacherName' => 'Dr. Example',
        'meetingDate' => now()->toDateString(),
        'startTime' => '09:00',
        'endTime' => '10:00',
        'recurrence' => [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $class = SchoolClass::query()->where('user_id', $this->user->id)->first();
    expect($class)->not->toBeNull()
        ->and($class->subject_name)->toBe('Calculus')
        ->and($class->teacher_name)->toBe('Dr. Example');

    $stripIds = $component->instance()->schoolClassesForSelectedDate->pluck('id');
    expect($stripIds)->toContain($class->id);
});

test('createSchoolClass does not create when required fields are invalid', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');

    $before = SchoolClass::query()->count();

    $component->call('createSchoolClass', [
        'scheduleMode' => 'one_off',
        'subjectName' => '   ',
        'teacherName' => '   ',
        'meetingDate' => null,
        'startTime' => null,
        'endTime' => null,
        'recurrence' => [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    expect(SchoolClass::query()->count())->toBe($before);
});

test('workspace list renders school class in the main list', function (): void {
    SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'WorkspaceListClass',
        'teacher_name' => 'Ms. Example',
        'start_datetime' => now()->startOfDay()->addHours(9),
        'end_datetime' => now()->startOfDay()->addHours(10),
    ]);

    $this->actingAs($this->user);

    $html = (string) $this->get(route('workspace'))->assertSuccessful()->getContent();

    expect($html)->toContain('data-test="workspace-school-class-item"')
        ->and($html)->toContain('WorkspaceListClass');
});
