<?php

use App\Enums\EventStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskStatus;
use App\Events\UserNotificationCreated;
use App\Models\CalendarFeed;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\CalendarFeedSyncService;
use App\Services\CollaborationInvitationService;
use App\Services\EventService;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\TaskService;
use App\Tools\LLM\TaskAssistant\DelegatingTool;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
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
        ->where('status', ReminderStatus::Pending->value)
        ->get();

    expect($dueSoon)->toHaveCount(1)
        ->and((bool) data_get($dueSoon->first()->payload, 'fallback_immediate'))->toBeTrue()
        ->and($dueSoon->first()->scheduled_at->lte(now()))->toBeTrue();
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
