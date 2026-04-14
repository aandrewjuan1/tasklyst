<?php

use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Events\UserNotificationCreated;
use App\Models\ActivityLog;
use App\Models\CollaborationInvitation;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CollaborationInvitationRespondedForOwnerNotification;
use App\Notifications\CollaboratorActivityOnItemNotification;
use App\Services\ActivityLogRecorder;
use App\Services\CollaborationInvitationService;
use App\Support\NotificationBellState;
use Illuminate\Support\Facades\Event;

test('mark accepted notifies owner once with accepted payload and not via collaborator activity channel', function (): void {
    Event::fake([UserNotificationCreated::class]);

    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Shared task',
        'end_datetime' => now()->addDay(),
    ]);

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);

    $beforeInviteResponse = $owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->count();
    $beforeActivity = $owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count();

    app(CollaborationInvitationService::class)->markAccepted($invitation, $invitee);

    expect($owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->count())->toBe($beforeInviteResponse + 1);

    $latest = $owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->latest()
        ->first();
    expect($latest)->not->toBeNull()
        ->and($latest->data['type'] ?? null)->toBe('collaboration_invite_accepted_for_owner')
        ->and($latest->data['message'] ?? '')->toContain('Shared task');

    expect($owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count())->toBe($beforeActivity);

    Event::assertDispatched(UserNotificationCreated::class, function (UserNotificationCreated $event) use ($owner): bool {
        return $event->userId === (int) $owner->id && $event->unreadCount >= 1;
    });
});

test('decline notifies owner with declined payload', function (): void {
    Event::fake([UserNotificationCreated::class]);

    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Decline me',
        'end_datetime' => now()->addDay(),
    ]);

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
    ]);

    $before = $owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->count();

    $ok = app(DeclineCollaborationInvitationAction::class)->execute($invitation, $invitee);

    expect($ok)->toBeTrue()
        ->and($owner->notifications()
            ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
            ->count())->toBe($before + 1);

    $latest = $owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->latest()
        ->first();
    expect($latest->data['type'] ?? null)->toBe('collaboration_invite_declined_for_owner')
        ->and($latest->data['message'] ?? '')->toContain('Decline me');

    Event::assertDispatched(UserNotificationCreated::class, fn (UserNotificationCreated $event): bool => $event->userId === (int) $owner->id);
});

test('accept action still notifies owner and cancels reminder when invitee is already collaborator', function (): void {
    Event::fake([UserNotificationCreated::class]);

    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Already collaborating',
        'end_datetime' => now()->addDay(),
    ]);

    $task->collaborations()->create([
        'user_id' => $invitee->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $invitation = CollaborationInvitation::factory()->create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'inviter_id' => $owner->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => CollaborationPermission::Edit,
        'status' => 'pending',
    ]);

    Reminder::query()->create([
        'user_id' => $invitee->id,
        'remindable_type' => $invitation->getMorphClass(),
        'remindable_id' => $invitation->id,
        'type' => ReminderType::CollaborationInviteReceived,
        'scheduled_at' => now()->addMinutes(10),
        'status' => ReminderStatus::Pending,
        'payload' => ['invitation_id' => $invitation->id],
    ]);

    $before = $owner->notifications()
        ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
        ->count();

    $result = app(AcceptCollaborationInvitationAction::class)->execute($invitation, $invitee);

    expect($result)->toBeNull()
        ->and($invitation->fresh()->status)->toBe('accepted')
        ->and(Reminder::query()
            ->where('remindable_type', $invitation->getMorphClass())
            ->where('remindable_id', $invitation->id)
            ->where('type', ReminderType::CollaborationInviteReceived->value)
            ->where('status', ReminderStatus::Pending->value)
            ->count())->toBe(0)
        ->and($owner->notifications()
            ->where('type', CollaborationInvitationRespondedForOwnerNotification::class)
            ->count())->toBe($before + 1);
});

test('collaborator field update notifies owner with activity log message', function (): void {
    Event::fake([UserNotificationCreated::class]);

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Original',
        'end_datetime' => now()->addDay(),
    ]);

    $task->collaborations()->create([
        'user_id' => $collaborator->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    $before = $owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count();

    app(UpdateTaskPropertyAction::class)->execute($task, 'title', 'Updated title', null, $collaborator);

    $log = ActivityLog::query()
        ->forItem($task)
        ->where('action', ActivityLogAction::FieldUpdated)
        ->latest()
        ->first();

    expect($log)->not->toBeNull();

    expect($owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count())->toBe($before + 1);

    $n = $owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->latest()
        ->first();

    $actorLabel = trim((string) $collaborator->name) !== ''
        ? (string) $collaborator->name
        : (string) $collaborator->email;

    expect($n->data['type'] ?? null)->toBe('collaborator_activity')
        ->and($n->data['message'] ?? '')->toBe(__(':user — :activity', [
            'user' => $actorLabel,
            'activity' => $log->message(),
        ]))
        ->and((int) ($n->data['meta']['activity_log_id'] ?? 0))->toBe($log->id)
        ->and($n->data['meta']['actor_email'] ?? null)->toBe($collaborator->email);

    Event::assertDispatched(UserNotificationCreated::class, fn (UserNotificationCreated $event): bool => $event->userId === (int) $owner->id);
});

test('owner editing own item does not notify owner as collaborator activity', function (): void {
    $owner = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Mine',
        'end_datetime' => now()->addDay(),
    ]);

    $before = $owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count();

    app(UpdateTaskPropertyAction::class)->execute($task, 'title', 'Renamed', null, $owner);

    expect($owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count())->toBe($before);
});

test('new collaboration notification types open workspace row in bell state', function (): void {
    expect(NotificationBellState::notificationDataOpensWorkspaceRow(['type' => 'collaborator_activity']))->toBeTrue()
        ->and(NotificationBellState::notificationDataOpensWorkspaceRow(['type' => 'collaboration_invite_accepted_for_owner']))->toBeTrue()
        ->and(NotificationBellState::notificationDataOpensWorkspaceRow(['type' => 'collaboration_invite_declined_for_owner']))->toBeTrue();
});

test('non collaborator edit does not notify owner as collaborator activity', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Private',
        'end_datetime' => now()->addDay(),
    ]);

    $before = $owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count();

    app(ActivityLogRecorder::class)->record(
        $task,
        $stranger,
        ActivityLogAction::FieldUpdated,
        ['field' => 'title', 'from' => 'Private', 'to' => 'Hacked']
    );

    expect($owner->notifications()
        ->where('type', CollaboratorActivityOnItemNotification::class)
        ->count())->toBe($before);
});
