<?php

use App\Models\SchoolClass;
use App\Models\Teacher;
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

test('school class creation recurring trigger shows repeat label', function (): void {
    $html = Blade::render('<x-recurring-selection kind="schoolClass" :school-class-creation="true" />', []);

    expect($html)->toContain(__('Repeat'))
        ->and($html)->toContain('schoolClassCreation')
        ->and($html)->not->toContain(__('Don\'t repeat'));
});

test('school class creation defaults recurrence to weekly enabled in x-data', function (): void {
    $path = resource_path('views/components/workspace/partials/item-creation-xdata.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain("scheduleMode: 'recurring'")
        ->and($contents)->toContain('enabled: true')
        ->and($contents)->toContain("type: 'weekly'");
});

test('creation school class fields includes teacher selection component', function (): void {
    $path = resource_path('views/components/workspace/creation-school-class-fields.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('teacher-selection')
        ->and($contents)->toContain('school-class-meeting-day')
        ->and($contents)->toContain('@date-picker-opened')
        ->and($contents)->toContain('clearSchoolClassMeetingDateForRecurringChoice')
        ->and($contents)->toContain('@recurring-selection-updated')
        ->and($contents)->toContain('school-class-hours-selection')
        ->and($contents)->toContain("__('Class starts')")
        ->and($contents)->toContain("__('Class ends')")
        ->and($contents)->toContain('flux:tooltip');
});

test('item creation x-data clears meeting date when recurring is chosen', function (): void {
    $path = resource_path('views/components/workspace/partials/item-creation-xdata.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('clearSchoolClassMeetingDateForRecurringChoice')
        ->and($contents)->toContain('formData.schoolClass.meetingDate')
        ->and($contents)->toContain('toggleClassHoursPopover');
});

test('item creation x-data clears recurring value when one meeting date is chosen', function (): void {
    $path = resource_path('views/components/workspace/partials/item-creation-xdata.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('clearSchoolClassRecurrenceForOneOffChoice')
        ->and($contents)->toContain("path !== 'formData.schoolClass.meetingDate'")
        ->and($contents)->toContain("this.formData.schoolClass.scheduleMode = 'one_off'")
        ->and($contents)->toContain("new CustomEvent('recurring-value'")
        ->and($contents)->toContain("path: 'formData.schoolClass.recurrence'");
});

test('creation school class schedule date pickers include tooltip content', function (): void {
    $path = resource_path('views/components/workspace/creation-school-class-fields.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain("__('Set the first day in range.')")
        ->and($contents)->toContain("__('Set the last day this class meets.')");
});

test('date picker school class meeting day uses schedule chip trigger', function (): void {
    $html = Blade::render(
        '<x-date-picker label="Meeting date" model="formData.schoolClass.meetingDate" type="date" :school-class-meeting-day="true" trigger-label="One meeting" />',
        []
    );

    expect($html)->toContain('One meeting')
        ->and($html)->toContain('group-data-[schedule-mode=one_off]/sc');
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

    $class = SchoolClass::query()->where('user_id', $this->user->id)->with('teacher')->first();
    expect($class)->not->toBeNull()
        ->and($class->subject_name)->toBe('Calculus')
        ->and($class->teacher)->not->toBeNull()
        ->and($class->teacher->name)->toBe('Dr. Example');

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
    $teacher = Teacher::firstOrCreateByDisplayName($this->user->id, 'Ms. Example');
    SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'WorkspaceListClass',
        'teacher_id' => $teacher->id,
        'start_datetime' => now()->startOfDay()->addHours(9),
        'end_datetime' => now()->startOfDay()->addHours(10),
    ]);

    $this->actingAs($this->user);

    $html = (string) $this->get(route('workspace'))->assertSuccessful()->getContent();

    expect($html)->toContain('data-test="workspace-school-class-item"')
        ->and($html)->toContain('WorkspaceListClass');
});
