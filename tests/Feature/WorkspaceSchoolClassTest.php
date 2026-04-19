<?php

use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use App\ViewModels\ListItemCardViewModel;
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

test('teacher selection markup uses delete mode toggle and warning state', function (): void {
    $path = resource_path('views/components/workspace/teacher-selection.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('toggleTeacherDeleteMode()')
        ->and($contents)->toContain('x-show="teacherDeleteMode"')
        ->and($contents)->toContain('x-if="teacherDeleteMode"')
        ->and($contents)->toContain('Deleting a teacher here also unassigns them from other classes.')
        ->and($contents)->toContain('teacher-delete-request');
});

test('item creation x-data resets teacher delete mode when popover closes', function (): void {
    $path = resource_path('views/components/workspace/partials/item-creation-xdata.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('teacherDeleteMode: false')
        ->and($contents)->toContain('this.teacherDeleteMode = false;');
});

test('item creation x-data clears selected teacher when teacher-deleted event is received', function (): void {
    $path = resource_path('views/components/workspace/partials/item-creation-xdata.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('onTeacherDeleted(event)')
        ->and($contents)->toContain('this.formData.schoolClass.teacherId = null;')
        ->and($contents)->toContain("this.formData.schoolClass.teacherName = '';");
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

test('workspace school class card does not render description controls', function (): void {
    $teacher = Teacher::firstOrCreateByDisplayName($this->user->id, 'Ms. No Description');
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'NoDescriptionClass',
        'teacher_id' => $teacher->id,
        'start_datetime' => now()->startOfDay()->addHours(11),
        'end_datetime' => now()->startOfDay()->addHours(12),
    ]);

    $this->actingAs($this->user);

    $html = Blade::render('<x-workspace.list-item-card kind="schoolClass" :item="$item" :list-filter-date="$date" :filters="[]" :available-tags="[]" />', [
        'item' => $schoolClass->fresh(['teacher', 'recurringSchoolClass']),
        'date' => now()->toDateString(),
    ]);

    expect($html)->toContain('NoDescriptionClass')
        ->and($html)->not->toContain('x-ref="descriptionInput"');
});

test('school class list item card config uses subjectName for inline title edits', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'Biology',
    ]);

    $viewModel = new ListItemCardViewModel(
        kind: 'schoolClass',
        item: $schoolClass,
        listFilterDate: now()->toDateString(),
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
        pomodoroSettings: null,
    );

    $alpineConfig = $viewModel->alpineConfig();

    expect($alpineConfig['canEdit'])->toBeTrue()
        ->and($alpineConfig['titleProperty'])->toBe('subjectName')
        ->and($alpineConfig['editedTitle'])->toBe('Biology');
});

test('school class list item uses editable popover components', function (): void {
    $path = resource_path('views/components/workspace/list-item-school-class.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('x-workspace.teacher-selection')
        ->and($contents)->toContain('x-workspace.school-class-hours-selection')
        ->and($contents)->toContain('x-recurring-selection')
        ->and($contents)->toContain('updatePropertyMethod')
        ->and($contents)->toContain('updateProperty(\'teacherName\'')
        ->and($contents)->toContain('handleDatePickerUpdated')
        ->and($contents)->toContain('handleRecurringSelectionUpdated');
});

test('school class list item entry and card pass teachers and update method props', function (): void {
    $entryPath = resource_path('views/components/workspace/list-item-entry.blade.php');
    $entryContents = file_get_contents($entryPath);
    $cardPath = resource_path('views/components/workspace/list-item-card.blade.php');
    $cardContents = file_get_contents($cardPath);

    expect($entryContents)->toContain(':teachers="$teachers"')
        ->and($cardContents)->toContain(':update-property-method="$updatePropertyMethod"')
        ->and($cardContents)->toContain(':teachers="$teachers"');
});

test('updateSchoolClassProperty updates allowed school class property', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'Original Subject',
    ]);

    $result = Livewire::test('pages::workspace.index')
        ->call('updateSchoolClassProperty', $schoolClass->id, 'subjectName', 'Updated Subject');

    $result->assertReturned(true);

    expect($schoolClass->fresh()->subject_name)->toBe('Updated Subject');
});

test('updateSchoolClassProperty rejects disallowed property', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'Original Subject',
    ]);

    $result = Livewire::test('pages::workspace.index')
        ->call('updateSchoolClassProperty', $schoolClass->id, 'user_id', 999);

    $result->assertReturned(false);

    expect($schoolClass->fresh()->user_id)->toBe($this->user->id);
});

test('updateSchoolClassProperty requires owner authorization', function (): void {
    $owner = User::factory()->create();
    $schoolClass = SchoolClass::factory()->for($owner)->create();

    $this->actingAs($this->user);

    $result = Livewire::test('pages::workspace.index')
        ->call('updateSchoolClassProperty', $schoolClass->id, 'subjectName', 'Unauthorized Update');

    $result->assertReturned(false);
});

test('updateSchoolClassProperty returns false for validation failure', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'Original Subject',
    ]);

    $result = Livewire::test('pages::workspace.index')
        ->call('updateSchoolClassProperty', $schoolClass->id, 'subjectName', '   ');

    $result->assertReturned(false);

    expect($schoolClass->fresh()->subject_name)->toBe('Original Subject');
});

test('updateSchoolClassProperty recurrence returns recurring school class id payload', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create();

    Livewire::test('pages::workspace.index')
        ->call('updateSchoolClassProperty', $schoolClass->id, 'recurrence', [
            'enabled' => true,
            'type' => 'weekly',
            'interval' => 1,
            'daysOfWeek' => [1, 3],
        ]);

    $schoolClass->refresh()->load('recurringSchoolClass');

    expect($schoolClass->recurringSchoolClass)->not->toBeNull();
});

test('deleteSchoolClass soft deletes an owned class', function (): void {
    $this->actingAs($this->user);
    $schoolClass = SchoolClass::factory()->for($this->user)->create([
        'subject_name' => 'Delete Me',
    ]);

    $result = Livewire::test('pages::workspace.index')
        ->call('deleteSchoolClass', $schoolClass->id);

    $result->assertReturned(true);

    expect(SchoolClass::query()->find($schoolClass->id))->toBeNull()
        ->and(SchoolClass::query()->withTrashed()->find($schoolClass->id)?->trashed())->toBeTrue();
});

test('deleteSchoolClass returns false when class does not belong to user', function (): void {
    $owner = User::factory()->create();
    $schoolClass = SchoolClass::factory()->for($owner)->create();

    $this->actingAs($this->user);

    $result = Livewire::test('pages::workspace.index')
        ->call('deleteSchoolClass', $schoolClass->id);

    $result->assertReturned(false);

    expect(SchoolClass::query()->find($schoolClass->id))->not->toBeNull();
});

test('restoreSchoolClass restores an owned trashed class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->user)->create();
    $schoolClass->delete();

    $this->actingAs($this->user);

    $result = Livewire::test('pages::workspace.index')
        ->call('restoreSchoolClass', $schoolClass->id);

    $result->assertReturned(true);

    expect(SchoolClass::query()->find($schoolClass->id))->not->toBeNull()
        ->and(SchoolClass::query()->find($schoolClass->id)?->trashed())->toBeFalse();
});

test('forceDeleteSchoolClass permanently deletes an owned class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->user)->create();
    $schoolClass->delete();

    $this->actingAs($this->user);

    $result = Livewire::test('pages::workspace.index')
        ->call('forceDeleteSchoolClass', $schoolClass->id);

    $result->assertReturned(true);

    expect(SchoolClass::query()->withTrashed()->find($schoolClass->id))->toBeNull();
});
