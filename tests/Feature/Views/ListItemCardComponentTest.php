<?php

use App\Enums\CollaborationPermission;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\EventService;
use App\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;

it('renders project card with list-item-project date pickers', function (): void {
    $project = Project::factory()->create([
        'name' => 'Test Project',
        'start_datetime' => now()->startOfDay(),
        'end_datetime' => now()->startOfDay()->addHours(2),
    ]);

    $html = Blade::render('<x-workspace.list-item-card kind="project" :item="$item" />', [
        'item' => $project,
    ]);

    expect($html)->toContain('Test Project')
        ->toContain(__('Start'))
        ->toContain(__('End'));
});

it('renders recurring pill for recurring tasks', function (): void {
    $task = Task::factory()->create();

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $task->load('recurringTask');

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('Daily');
});

it('renders recurring selection for non-recurring tasks', function (): void {
    $task = Task::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('aria-label="Repeat this task"');
});

it('renders recurring pill for recurring events', function (): void {
    $event = Event::factory()->create();

    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Weekly,
        'interval' => 1,
        'days_of_week' => null,
        'start_datetime' => now(),
        'end_datetime' => null,
    ]);

    $event->load('recurringEvent');

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('Weekly');
});

it('renders recurring selection for non-recurring events', function (): void {
    $event = Event::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('aria-label="Repeat this event"');
});

it('renders collaborators popover with accepted collaborators for projects', function (): void {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create([
        'name' => 'Jane Collaborator',
        'email' => 'jane@example.test',
    ]);

    $project = Project::factory()->for($owner)->create([
        'name' => 'Shared Project',
    ]);

    $project->collaborators()->attach($collaborator->id, [
        'permission' => CollaborationPermission::Edit,
    ]);

    $project->load('collaborators');

    $html = Blade::render('<x-workspace.list-item-card kind="project" :item="$item" />', [
        'item' => $project,
    ]);

    expect($html)
        ->toContain('Collab')
        ->toContain('jane@example.test')
        ->toContain('Edit');
});

it('renders collaborators popover for tasks without collaborators', function (): void {
    $task = Task::factory()->create([
        'title' => 'Solo Task',
    ]);

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)
        ->toContain('Collab')
        ->toContain('No collaborators yet');
});

it('renders pending collaboration invitations in collaborators popover', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create([
        'name' => 'Pending User',
        'email' => 'pending@example.test',
    ]);

    $task = Task::factory()
        ->for($owner)
        ->create([
            'title' => 'Task with pending invite',
        ]);

    CollaborationInvitation::factory()
        ->for($task, 'collaboratable')
        ->for($owner, 'inviter')
        ->create([
            'invitee_email' => $invitee->email,
            'invitee_user_id' => $invitee->id,
            'permission' => CollaborationPermission::Edit,
            'status' => 'pending',
        ]);

    $task->load(['collaborators', 'collaborationInvitations.invitee']);

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)
        ->toContain('pending@example.test')
        ->toContain('Pending')
        ->toContain('Can edit');
});

it('renders declined collaboration invitations in collaborators popover', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create([
        'name' => 'Declined User',
        'email' => 'declined@example.test',
    ]);

    $event = Event::factory()
        ->for($owner)
        ->create([
            'title' => 'Event with declined invite',
        ]);

    CollaborationInvitation::factory()
        ->for($event, 'collaboratable')
        ->for($owner, 'inviter')
        ->create([
            'invitee_email' => $invitee->email,
            'invitee_user_id' => $invitee->id,
            'permission' => CollaborationPermission::View,
            'status' => 'declined',
        ]);

    $event->load(['collaborators', 'collaborationInvitations.invitee']);

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)
        ->toContain('declined@example.test')
        ->toContain('Declined')
        ->toContain('Can view');
});

it('uses base task status for recurring task', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'status' => TaskStatus::Doing,
    ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $task->load('recurringTask');

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate(
        $task,
        Carbon::parse('2026-02-06')
    );

    $task->effectiveStatusForDate = $effectiveStatus;

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('Doing');
});

it('uses base event status for recurring event', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'status' => EventStatus::Tentative,
    ]);

    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Weekly,
        'interval' => 1,
        'days_of_week' => null,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => null,
    ]);

    $event->load('recurringEvent');

    $effectiveStatus = app(EventService::class)->getEffectiveStatusForDate(
        $event,
        Carbon::parse('2026-02-06')
    );

    $event->effectiveStatusForDate = $effectiveStatus;

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('Tentative');
});
