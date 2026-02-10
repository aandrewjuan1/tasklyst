<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaborator = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('scope for user returns projects owned by the user', function (): void {
    $owned = Project::factory()->for($this->owner)->create(['name' => 'Owned project']);
    Project::factory()->for($this->otherUser)->create(['name' => 'Other project']);

    $projects = Project::query()->forUser($this->owner->id)->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($owned->id);
});

test('scope for user returns projects where user is collaborator', function (): void {
    $project = Project::factory()->for($this->owner)->create(['name' => 'Shared project']);
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $projects = Project::query()->forUser($this->collaborator->id)->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($project->id);
});

test('scope for user does not return other users projects without collaboration', function (): void {
    Project::factory()->for($this->owner)->create(['name' => 'Owner only project']);

    $projects = Project::query()->forUser($this->collaborator->id)->get();

    expect($projects)->toHaveCount(0);
});

test('scope not archived excludes soft deleted projects', function (): void {
    $active = Project::factory()->for($this->owner)->create(['name' => 'Active project']);
    $deleted = Project::factory()->for($this->owner)->create(['name' => 'Deleted project']);
    $deleted->delete();

    $projects = Project::query()->forUser($this->owner->id)->notArchived()->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($active->id);
});

test('scope active for date includes projects with no dates', function (): void {
    $project = Project::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);

    $date = Carbon::parse('2025-02-10');
    $projects = Project::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($projects->contains('id', $project->id))->toBeTrue();
});

test('scope active for date includes projects when date is before or on end date', function (): void {
    $endDate = Carbon::parse('2025-02-15')->endOfDay();
    $project = Project::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => $endDate,
    ]);

    $date = Carbon::parse('2025-02-10');
    $projects = Project::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($projects->contains('id', $project->id))->toBeTrue();
});

test('scope active for date includes projects when date is within start and end range', function (): void {
    $start = Carbon::parse('2025-02-08')->startOfDay();
    $end = Carbon::parse('2025-02-12')->endOfDay();
    $project = Project::factory()->for($this->owner)->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    $date = Carbon::parse('2025-02-10');
    $projects = Project::query()->forUser($this->owner->id)->activeForDate($date)->get();

    expect($projects->contains('id', $project->id))->toBeTrue();
});

test('scope overdue returns projects with end_datetime before given date', function (): void {
    $pastEnd = Project::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-05'),
    ]);
    Project::factory()->for($this->owner)->create([
        'end_datetime' => Carbon::parse('2025-02-15'),
    ]);

    $asOf = Carbon::parse('2025-02-10');
    $projects = Project::query()->forUser($this->owner->id)->overdue($asOf)->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($pastEnd->id);
});

test('scope upcoming returns projects with start_datetime on or after from date', function (): void {
    $from = Carbon::parse('2025-02-10')->startOfDay();
    $upcoming = Project::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(2),
    ]);
    Project::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->subDay(),
    ]);

    $projects = Project::query()->forUser($this->owner->id)->upcoming($from)->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($upcoming->id);
});

test('scope with no date returns only projects with null start and end datetime', function (): void {
    $noDate = Project::factory()->for($this->owner)->create([
        'start_datetime' => null,
        'end_datetime' => null,
    ]);
    Project::factory()->for($this->owner)->create([
        'start_datetime' => now(),
        'end_datetime' => null,
    ]);

    $projects = Project::query()->forUser($this->owner->id)->withNoDate()->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($noDate->id);
});

test('scope order by start time orders chronologically', function (): void {
    $early = Project::factory()->for($this->owner)->create(['start_datetime' => Carbon::parse('2025-02-10 09:00')]);
    $late = Project::factory()->for($this->owner)->create(['start_datetime' => Carbon::parse('2025-02-10 14:00')]);

    $projects = Project::query()->forUser($this->owner->id)->orderByStartTime()->get();

    expect($projects->first()->id)->toBe($early->id)
        ->and($projects->last()->id)->toBe($late->id);
});

test('scope order by name orders alphabetically', function (): void {
    $alpha = Project::factory()->for($this->owner)->create(['name' => 'Alpha project']);
    $beta = Project::factory()->for($this->owner)->create(['name' => 'Beta project']);

    $projects = Project::query()->forUser($this->owner->id)->orderByName()->get();

    expect($projects->first()->id)->toBe($alpha->id)
        ->and($projects->last()->id)->toBe($beta->id);
});

test('scope starting soon returns projects starting within given days from date', function (): void {
    $from = Carbon::parse('2025-02-10')->startOfDay();
    $soon = Project::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(3),
    ]);
    Project::factory()->for($this->owner)->create([
        'start_datetime' => $from->copy()->addDays(10),
    ]);

    $projects = Project::query()->forUser($this->owner->id)->startingSoon($from, 7)->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($soon->id);
});

test('scope with incomplete tasks returns only projects that have at least one incomplete task', function (): void {
    $withIncomplete = Project::factory()->for($this->owner)->create();
    Task::factory()->for($withIncomplete)->for($this->owner)->create(['completed_at' => null]);

    $allComplete = Project::factory()->for($this->owner)->create();
    Task::factory()->for($allComplete)->for($this->owner)->create(['completed_at' => now()]);

    $projects = Project::query()->forUser($this->owner->id)->withIncompleteTasks()->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($withIncomplete->id);
});

test('scope with tasks returns only projects that have at least one task', function (): void {
    $withTasks = Project::factory()->for($this->owner)->create();
    Task::factory()->for($withTasks)->for($this->owner)->create();

    $noTasks = Project::factory()->for($this->owner)->create();

    $projects = Project::query()->forUser($this->owner->id)->withTasks()->get();

    expect($projects)->toHaveCount(1)
        ->and($projects->first()->id)->toBe($withTasks->id);
});

test('deleting project cascades to collaborations and collaboration invitations', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $collab = Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'inviter_id' => $this->owner->id,
        'invitee_user_id' => $this->collaborator->id,
    ]);

    $project->delete();

    expect(Collaboration::find($collab->id))->toBeNull()
        ->and(CollaborationInvitation::find($invitation->id))->toBeNull();
});

test('property to column maps startDatetime and endDatetime to snake_case', function (): void {
    expect(Project::propertyToColumn('startDatetime'))->toBe('start_datetime')
        ->and(Project::propertyToColumn('endDatetime'))->toBe('end_datetime')
        ->and(Project::propertyToColumn('name'))->toBe('name');
});

test('get property value for update returns correct value for name description and dates', function (): void {
    $project = Project::factory()->for($this->owner)->create([
        'name' => 'Test Project',
        'description' => 'Test description',
        'start_datetime' => $start = Carbon::parse('2025-02-10 09:00'),
        'end_datetime' => $end = Carbon::parse('2025-02-11 17:00'),
    ]);

    expect($project->getPropertyValueForUpdate('name'))->toBe('Test Project')
        ->and($project->getPropertyValueForUpdate('description'))->toBe('Test description')
        ->and($project->getPropertyValueForUpdate('startDatetime'))->toEqual($start)
        ->and($project->getPropertyValueForUpdate('endDatetime'))->toEqual($end);
});
