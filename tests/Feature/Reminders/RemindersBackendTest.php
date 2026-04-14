<?php

use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Enums\EventStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskStatus;
use App\Events\UserNotificationCreated;
use App\Models\CalendarFeed;
use App\Models\FocusSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\CalendarFeedSyncService;
use App\Services\CollaborationInvitationService;
use App\Services\EventService;
use App\Services\FocusSessionService;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\Reminders\ReminderInsightsSchedulerService;
use App\Services\TaskService;
use App\Tools\LLM\TaskAssistant\DelegatingTool;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['broadcasting.default' => 'null']);

    $this->user = User::factory()->create();
});

test('task create schedules due-soon and overdue reminders', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $dueAt = now()->addDays(2)->setTime(12, 0);

    $task = $service->createTask($this->user, [
        'title' => 'My task',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => $dueAt,
    ]);

    $dueSoon = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskDueSoon->value)
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    $overdue = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskOverdue->value)
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    expect($dueSoon)->toHaveCount(2)
        ->and($overdue)->toHaveCount(1);

    $scheduledAts = $dueSoon->pluck('scheduled_at')->map(fn ($dt) => $dt->timestamp)->all();
    expect($scheduledAts)->toContain($dueAt->copy()->subMinutes(60)->timestamp)
        ->and($scheduledAts)->toContain($dueAt->copy()->subMinutes(1440)->timestamp);

    expect($overdue->first()->scheduled_at->timestamp)->toBe($dueAt->timestamp);
});

test('task end_datetime update cancels old reminders and schedules new ones', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $originalDue = now()->addDays(3)->setTime(10, 0);
    $task = $service->createTask($this->user, [
        'title' => 'Reschedule me',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => $originalDue,
    ]);

    $newDue = now()->addDays(5)->setTime(9, 0);
    $service->updateTask($task, ['end_datetime' => $newDue]);

    $pendingDueSoon = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskDueSoon->value)
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    $cancelledDueSoon = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskDueSoon->value)
        ->where('status', ReminderStatus::Cancelled->value)
        ->get();

    expect($pendingDueSoon)->toHaveCount(2)
        ->and($cancelledDueSoon->count())->toBeGreaterThanOrEqual(2);

    $scheduledAts = $pendingDueSoon->pluck('scheduled_at')->map(fn ($dt) => $dt->timestamp)->all();
    expect($scheduledAts)->toContain($newDue->copy()->subMinutes(60)->timestamp)
        ->and($scheduledAts)->toContain($newDue->copy()->subMinutes(1440)->timestamp);
});

test('syncing task reminders repeatedly does not create duplicate pending rows', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $dueAt = now()->addDays(2)->setTime(10, 0);
    $task = $service->createTask($this->user, [
        'title' => 'Deduplicate reminder rows',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => $dueAt,
    ]);

    $service->updateTask($task, [
        'title' => 'Deduplicate reminder rows v2',
        'end_datetime' => $dueAt,
    ]);

    $pendingCount = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingCount)->toBe(3);
});

test('task completion cancels pending reminders', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $task = $service->createTask($this->user, [
        'title' => 'Complete me',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => now()->addDay(),
    ]);

    $service->updateTask($task, ['status' => TaskStatus::Done->value]);

    $pending = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pending)->toBe(0);
});

test('task reminder sync tolerates existing cancelled duplicates', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $dueAt = now()->addDays(2)->setTime(11, 0);
    $task = $service->createTask($this->user, [
        'title' => 'Duplicate cancellation safety',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => $dueAt,
    ]);

    $pendingDueSoon = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskDueSoon->value)
        ->where('status', ReminderStatus::Pending->value)
        ->firstOrFail();

    Reminder::query()->create([
        'user_id' => $this->user->id,
        'remindable_type' => $pendingDueSoon->remindable_type,
        'remindable_id' => $pendingDueSoon->remindable_id,
        'type' => $pendingDueSoon->type,
        'scheduled_at' => $pendingDueSoon->scheduled_at,
        'status' => ReminderStatus::Cancelled,
        'cancelled_at' => now(),
        'payload' => $pendingDueSoon->payload,
    ]);

    $service->updateTask($task, ['title' => 'Duplicate cancellation safety v2']);

    $pendingCount = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingCount)->toBe(3);
});

test('task due-soon fallback creates immediate reminder when configured offsets are already in the past', function (): void {
    /** @var TaskService $service */
    $service = app(TaskService::class);

    $task = $service->createTask($this->user, [
        'title' => 'Soon due',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => now()->addMinutes(30),
    ]);

    $dueSoon = Reminder::query()
        ->where('remindable_type', $task->getMorphClass())
        ->where('remindable_id', $task->id)
        ->where('type', ReminderType::TaskDueSoon->value)
        ->get();

    expect($dueSoon)->toHaveCount(1)
        ->and($dueSoon->first()->status)->toBe(ReminderStatus::Sent)
        ->and((bool) data_get($dueSoon->first()->payload, 'fallback_immediate'))->toBeTrue()
        ->and($dueSoon->first()->scheduled_at->lte(now()))->toBeTrue();

    expect($this->user->notifications()->count())->toBe(1);
});

test('event create and update schedule start-soon reminders correctly', function (): void {
    /** @var EventService $service */
    $service = app(EventService::class);

    $startAt = now()->addHours(2)->startOfMinute();

    $event = $service->createEvent($this->user, [
        'title' => 'Standup',
        'status' => EventStatus::Scheduled->value,
        'start_datetime' => $startAt,
        'end_datetime' => $startAt->copy()->addMinutes(30),
        'all_day' => false,
    ]);

    $initialPending = Reminder::query()
        ->where('remindable_type', $event->getMorphClass())
        ->where('remindable_id', $event->id)
        ->where('type', ReminderType::EventStartSoon->value)
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    expect($initialPending)->toHaveCount(2);

    $newStartAt = now()->addHours(4)->startOfMinute();
    $service->updateEvent($event, [
        'start_datetime' => $newStartAt,
        'end_datetime' => $newStartAt->copy()->addMinutes(30),
    ]);

    $pendingAfterUpdate = Reminder::query()
        ->where('remindable_type', $event->getMorphClass())
        ->where('remindable_id', $event->id)
        ->where('type', ReminderType::EventStartSoon->value)
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    $cancelledAfterUpdate = Reminder::query()
        ->where('remindable_type', $event->getMorphClass())
        ->where('remindable_id', $event->id)
        ->where('type', ReminderType::EventStartSoon->value)
        ->where('status', ReminderStatus::Cancelled->value)
        ->count();

    $scheduledAts = $pendingAfterUpdate->pluck('scheduled_at')->map(fn ($dt) => $dt->timestamp)->all();
    expect($pendingAfterUpdate)->toHaveCount(2)
        ->and($cancelledAfterUpdate)->toBeGreaterThanOrEqual(2)
        ->and($scheduledAts)->toContain($newStartAt->copy()->subMinutes(15)->timestamp)
        ->and($scheduledAts)->toContain($newStartAt->copy()->subMinutes(60)->timestamp);
});

test('dispatchDueForRemindable only processes reminders for that remindable', function (): void {
    /** @var ReminderDispatcherService $dispatcher */
    $dispatcher = app(ReminderDispatcherService::class);

    $taskA = Task::factory()->for($this->user)->create([
        'title' => 'Task A',
        'end_datetime' => now()->addMinute(),
        'completed_at' => null,
    ]);

    $taskB = Task::factory()->for($this->user)->create([
        'title' => 'Task B',
        'end_datetime' => now()->addMinute(),
        'completed_at' => null,
    ]);

    Reminder::query()->create([
        'user_id' => $this->user->id,
        'remindable_type' => $taskA->getMorphClass(),
        'remindable_id' => $taskA->id,
        'type' => ReminderType::TaskOverdue,
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'task_id' => $taskA->id,
            'task_title' => $taskA->title,
            'due_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    Reminder::query()->create([
        'user_id' => $this->user->id,
        'remindable_type' => $taskB->getMorphClass(),
        'remindable_id' => $taskB->id,
        'type' => ReminderType::TaskOverdue,
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'task_id' => $taskB->id,
            'task_title' => $taskB->title,
            'due_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $count = $dispatcher->dispatchDueForRemindable($taskA->getMorphClass(), (int) $taskA->id);

    expect($count)->toBe(1)
        ->and($this->user->notifications()->count())->toBe(1);
});

test('task update to past due processes overdue reminder immediately via queued job', function (): void {
    Event::fake([UserNotificationCreated::class]);

    /** @var TaskService $service */
    $service = app(TaskService::class);

    $task = $service->createTask($this->user, [
        'title' => 'Later due',
        'status' => TaskStatus::ToDo->value,
        'end_datetime' => now()->addWeek(),
    ]);

    $service->updateTask($task, [
        'end_datetime' => now()->subHour(),
    ]);

    $this->user->refresh();

    expect($this->user->notifications()->count())->toBe(1);

    expect(
        Reminder::query()
            ->where('remindable_type', $task->getMorphClass())
            ->where('remindable_id', $task->id)
            ->where('type', ReminderType::TaskOverdue)
            ->where('status', ReminderStatus::Sent)
            ->exists(),
    )->toBeTrue();

    Event::assertDispatched(UserNotificationCreated::class);
});

test('dispatcher creates database notifications and marks reminders sent', function (): void {
    Event::fake([UserNotificationCreated::class]);

    /** @var ReminderDispatcherService $dispatcher */
    $dispatcher = app(ReminderDispatcherService::class);

    $task = Task::factory()->for($this->user)->create([
        'title' => 'Dispatch test',
        'end_datetime' => now()->addMinute(),
        'completed_at' => null,
    ]);

    $reminder = Reminder::query()->create([
        'user_id' => $this->user->id,
        'remindable_type' => $task->getMorphClass(),
        'remindable_id' => $task->id,
        'type' => ReminderType::TaskOverdue,
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'due_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $count = $dispatcher->dispatchDue(10);
    $reminder->refresh();
    $this->user->refresh();

    expect($count)->toBe(1)
        ->and($reminder->status)->toBe(ReminderStatus::Sent)
        ->and($this->user->notifications()->count())->toBe(1);

    Event::assertDispatched(UserNotificationCreated::class);
});

test('dispatcher retries on notification failure and cancels when max attempts reached', function (): void {
    config()->set('reminders.dispatch.retry_delay_minutes', 7);
    config()->set('reminders.dispatch.max_attempts', 1);

    $notificationDispatcher = \Mockery::mock(NotificationDispatcher::class);
    $notificationDispatcher->shouldReceive('send')->andThrow(new RuntimeException('forced notify failure'));
    app()->instance(NotificationDispatcher::class, $notificationDispatcher);

    /** @var ReminderDispatcherService $dispatcher */
    $dispatcher = app(ReminderDispatcherService::class);

    $task = Task::factory()->for($this->user)->create([
        'title' => 'Dispatch failure test',
        'end_datetime' => now()->addMinute(),
        'completed_at' => null,
    ]);

    $reminder = Reminder::query()->create([
        'user_id' => $this->user->id,
        'remindable_type' => $task->getMorphClass(),
        'remindable_id' => $task->id,
        'type' => ReminderType::TaskOverdue,
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'due_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $count = $dispatcher->dispatchDue(10);
    $reminder->refresh();

    expect($count)->toBe(0)
        ->and($reminder->status)->toBe(ReminderStatus::Cancelled)
        ->and($reminder->cancelled_at)->not->toBeNull()
        ->and((int) data_get($reminder->payload, 'dispatch_attempts'))->toBe(1)
        ->and((string) data_get($reminder->payload, 'last_error'))->toContain('forced notify failure');
});

test('calendar feed sync failure creates reminder once per cooldown window', function (): void {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'My feed',
        'feed_url' => 'https://example.com/feed.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    /** @var CalendarFeedSyncService $service */
    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed);
    $service->sync($feed);

    $count = Reminder::query()
        ->where('user_id', $this->user->id)
        ->where('remindable_type', $feed->getMorphClass())
        ->where('remindable_id', $feed->id)
        ->where('type', ReminderType::CalendarFeedSyncFailed->value)
        ->count();

    expect($count)->toBe(1);
});

test('collaboration invitation creation schedules invite received reminder for invitee user', function (): void {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $task = Task::factory()->for($inviter)->create();

    /** @var CollaborationInvitationService $service */
    $service = app(CollaborationInvitationService::class);

    $invitation = $service->createInvitation([
        'collaboratable_type' => $task->getMorphClass(),
        'collaboratable_id' => $task->id,
        'inviter_id' => $inviter->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => 'view',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $exists = Reminder::query()
        ->where('user_id', $invitee->id)
        ->where('remindable_type', $invitation->getMorphClass())
        ->where('remindable_id', $invitation->id)
        ->where('type', ReminderType::CollaborationInviteReceived->value)
        ->exists();

    expect($exists)->toBeTrue();
});

test('assistant tool call failure creates a reminder (deduped by operation token)', function (): void {
    $user = $this->user;

    $tool = new class($user) extends DelegatingTool
    {
        public function publicRun(array $params): string
        {
            $this->action = function (): void {
                throw new RuntimeException('boom');
            };

            return $this->runDelegatedAction($params, 'test_tool', 'op-token-1');
        }
    };

    $tool->publicRun(['x' => 1]);
    $tool->publicRun(['x' => 1]);

    $count = Reminder::query()
        ->where('user_id', $user->id)
        ->where('type', ReminderType::AssistantToolCallFailed->value)
        ->where('payload->operation_token', 'op-token-1')
        ->count();

    expect($count)->toBe(1);
});

test('accepting collaboration invitation cancels pending invite reminder', function (): void {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee-accept@example.com']);
    $task = Task::factory()->for($inviter)->create();

    /** @var CollaborationInvitationService $service */
    $service = app(CollaborationInvitationService::class);

    $invitation = $service->createInvitation([
        'collaboratable_type' => $task->getMorphClass(),
        'collaboratable_id' => $task->id,
        'inviter_id' => $inviter->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => 'view',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    Reminder::query()->create([
        'user_id' => $invitee->id,
        'remindable_type' => $invitation->getMorphClass(),
        'remindable_id' => $invitation->id,
        'type' => ReminderType::CollaborationInviteReceived,
        'scheduled_at' => now()->addMinutes(10),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'invitation_id' => $invitation->id,
            'invitee_email' => $invitee->email,
            'collaboratable_type' => $task->getMorphClass(),
            'collaboratable_id' => $task->id,
            'permission' => 'view',
        ],
    ]);

    $pendingBefore = Reminder::query()
        ->where('remindable_type', $invitation->getMorphClass())
        ->where('remindable_id', $invitation->id)
        ->where('type', ReminderType::CollaborationInviteReceived->value)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingBefore)->toBe(1);

    $service->markAccepted($invitation->fresh(), $invitee);

    $pendingAfter = Reminder::query()
        ->where('remindable_type', $invitation->getMorphClass())
        ->where('remindable_id', $invitation->id)
        ->where('type', ReminderType::CollaborationInviteReceived->value)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingAfter)->toBe(0);
});

test('declining collaboration invitation cancels pending invite reminder', function (): void {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee-decline@example.com']);
    $task = Task::factory()->for($inviter)->create();

    /** @var CollaborationInvitationService $service */
    $service = app(CollaborationInvitationService::class);

    $invitation = $service->createInvitation([
        'collaboratable_type' => $task->getMorphClass(),
        'collaboratable_id' => $task->id,
        'inviter_id' => $inviter->id,
        'invitee_email' => $invitee->email,
        'invitee_user_id' => $invitee->id,
        'permission' => 'view',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    Reminder::query()->create([
        'user_id' => $invitee->id,
        'remindable_type' => $invitation->getMorphClass(),
        'remindable_id' => $invitation->id,
        'type' => ReminderType::CollaborationInviteReceived,
        'scheduled_at' => now()->addMinutes(10),
        'status' => ReminderStatus::Pending,
        'payload' => [
            'invitation_id' => $invitation->id,
            'invitee_email' => $invitee->email,
            'collaboratable_type' => $task->getMorphClass(),
            'collaboratable_id' => $task->id,
            'permission' => 'view',
        ],
    ]);

    $pendingBefore = Reminder::query()
        ->where('remindable_type', $invitation->getMorphClass())
        ->where('remindable_id', $invitation->id)
        ->where('type', ReminderType::CollaborationInviteReceived->value)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingBefore)->toBe(1);

    $ok = app(DeclineCollaborationInvitationAction::class)->execute($invitation->fresh(), $invitee);
    expect($ok)->toBeTrue();

    $pendingAfter = Reminder::query()
        ->where('remindable_type', $invitation->getMorphClass())
        ->where('remindable_id', $invitation->id)
        ->where('type', ReminderType::CollaborationInviteReceived->value)
        ->where('status', ReminderStatus::Pending->value)
        ->count();

    expect($pendingAfter)->toBe(0);
});

test('insight scheduler creates daily summary and stalled task reminders', function (): void {
    /** @var ReminderInsightsSchedulerService $service */
    $service = app(ReminderInsightsSchedulerService::class);

    Task::factory()->for($this->user)->create([
        'title' => 'Due today',
        'end_datetime' => now()->setTime(18, 0),
        'completed_at' => null,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Stalled urgent',
        'priority' => 'urgent',
        'completed_at' => null,
        'updated_at' => now()->subDays(4),
    ]);

    $created = $service->evaluateDueInsights(now()->setTime((int) config('reminders.daily_due_summary_hour', 7), 0));

    expect($created)->toBeGreaterThan(0)
        ->and(Reminder::query()->where('user_id', $this->user->id)->where('type', ReminderType::DailyDueSummary->value)->exists())->toBeTrue()
        ->and(Reminder::query()->where('user_id', $this->user->id)->where('type', ReminderType::TaskStalled->value)->exists())->toBeTrue();
});

test('focus session completion schedules and dispatches completion notification reminder', function (): void {
    /** @var FocusSessionService $focusSessionService */
    $focusSessionService = app(FocusSessionService::class);

    $task = Task::factory()->for($this->user)->create([
        'title' => 'Focus target',
    ]);

    $session = $focusSessionService->startWorkSession(
        user: $this->user,
        task: $task,
        startedAt: now()->subMinutes(25),
        durationSeconds: 1500,
    );

    $focusSessionService->completeSession(
        session: $session,
        endedAt: now(),
        completed: true,
    );

    $exists = Reminder::query()
        ->where('user_id', $this->user->id)
        ->where('remindable_type', FocusSession::class)
        ->where('remindable_id', $session->id)
        ->where('type', ReminderType::FocusSessionCompleted->value)
        ->where('status', ReminderStatus::Sent->value)
        ->exists();

    expect($exists)->toBeTrue();
});

test('calendar feed successful sync after failure creates recovered reminder', function (): void {
    Http::fakeSequence()
        ->push('', 500)
        ->push("BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:abc-123\nSUMMARY:Recovered sync\nDTSTART:20260501T090000Z\nDTEND:20260501T100000Z\nEND:VEVENT\nEND:VCALENDAR", 200);

    $feed = CalendarFeed::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Recoverable feed',
        'feed_url' => 'https://example.com/feed.ics',
        'source' => 'brightspace',
        'sync_enabled' => true,
    ]);

    /** @var CalendarFeedSyncService $service */
    $service = app(CalendarFeedSyncService::class);
    $service->sync($feed);
    $service->sync($feed);

    $recoveredExists = Reminder::query()
        ->where('user_id', $this->user->id)
        ->where('remindable_type', $feed->getMorphClass())
        ->where('remindable_id', $feed->id)
        ->where('type', ReminderType::CalendarFeedRecovered->value)
        ->exists();

    expect($recoveredExists)->toBeTrue();
});
