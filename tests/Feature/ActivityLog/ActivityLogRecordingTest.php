<?php

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Models\ActivityLog;
use App\Models\CollaborationInvitation;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\CollaborationInvitationService;
use App\Services\ProjectService;
use App\Services\TaskService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->recorder = app(ActivityLogRecorder::class);
});

test('activity log recorder creates log with loggable actor action and payload', function (): void {
    $task = Task::factory()->for($this->user)->create(['title' => 'Log me']);

    $log = $this->recorder->record(
        $task,
        $this->user,
        ActivityLogAction::ItemCreated,
        ['title' => $task->title]
    );

    expect($log)->toBeInstanceOf(ActivityLog::class)
        ->and($log->loggable_type)->toBe(Task::class)
        ->and($log->loggable_id)->toBe($task->id)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->action)->toBe(ActivityLogAction::ItemCreated)
        ->and($log->payload)->toEqual(['title' => $task->title]);
});

test('activity log recorder allows null actor', function (): void {
    $task = Task::factory()->for($this->user)->create();

    $log = $this->recorder->record($task, null, ActivityLogAction::ItemDeleted, ['title' => $task->title]);

    expect($log->user_id)->toBeNull()
        ->and($log->action)->toBe(ActivityLogAction::ItemDeleted);
});

test('create task records item created activity log', function (): void {
    $service = app(TaskService::class);

    $task = $service->createTask($this->user, [
        'title' => 'New task',
        'status' => 'to_do',
    ]);

    $log = ActivityLog::query()->forItem($task)->where('action', ActivityLogAction::ItemCreated)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->payload['title'])->toBe('New task');
});

test('delete task with actor records item deleted activity log', function (): void {
    $task = Task::factory()->for($this->user)->create(['title' => 'Gone']);
    $service = app(TaskService::class);

    $service->deleteTask($task, $this->user);

    $log = ActivityLog::query()
        ->where('loggable_type', Task::class)
        ->where('loggable_id', $task->id)
        ->where('action', ActivityLogAction::ItemDeleted)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->payload['title'])->toBe('Gone');
});

test('create project records item created activity log', function (): void {
    $service = app(ProjectService::class);

    $project = $service->createProject($this->user, ['name' => 'New project']);

    $log = ActivityLog::query()->forItem($project)->where('action', ActivityLogAction::ItemCreated)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->payload['name'])->toBe('New project');
});

test('create collaboration invitation records collaborator invited activity log', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $service = app(CollaborationInvitationService::class);

    $invitation = $service->createInvitation([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => 'invitee@example.com',
        'permission' => CollaborationPermission::Edit,
    ]);

    $log = ActivityLog::query()
        ->forItem($task)
        ->where('action', ActivityLogAction::CollaboratorInvited)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->payload['invitee_email'])->toBe('invitee@example.com')
        ->and($log->payload['permission'])->toBe(CollaborationPermission::Edit->value);
});

test('mark invitation accepted records collaborator invitation accepted activity log', function (): void {
    $task = Task::factory()->for($this->user)->create();
    $invitee = User::factory()->create();
    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $this->user->id,
        'invitee_email' => $invitee->email,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);
    $service = app(CollaborationInvitationService::class);

    $service->markAccepted($invitation, $invitee);

    $log = ActivityLog::query()
        ->forItem($task)
        ->where('action', ActivityLogAction::CollaboratorInvitationAccepted)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($invitee->id)
        ->and($log->payload['invitee_email'])->toBe($invitee->email);
});
