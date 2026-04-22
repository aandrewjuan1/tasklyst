<?php

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
    Livewire::flushState();
});

test('focusFromScheduledPlanItem aligns selected date to planned day and clears search', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00', config('app.timezone')));

    $this->actingAs($this->user);

    $plannedDay = Carbon::parse('2026-04-16 09:30:00', config('app.timezone'));

    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => $plannedDay,
        'end_datetime' => $plannedDay->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'focus-align-1',
        'proposal_id' => 'focus-align-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => $plannedDay,
        'planned_end_at' => $plannedDay->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-15')
        ->set('searchQuery', 'blocked-by-search')
        ->call('focusFromScheduledPlanItem', $item->id)
        ->assertSet('selectedDate', '2026-04-16')
        ->assertSet('searchQuery', null);
});

test('workspace kanban view shows compact scheduled focus strip when plan items exist', function (): void {
    $this->actingAs($this->user);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'kanban-strip-1',
        'proposal_id' => 'kanban-strip-1',
        'entity_type' => 'task',
        'entity_id' => 999,
        'title' => 'Kanban Scheduled Focus Row',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->assertSee('AI Proposed Schedule')
        ->assertSee('Kanban Scheduled Focus Row');
});

test('scheduled focus renders dynamic day labels for today tomorrow and later dates', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));
    $this->actingAs($this->user);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'dynamic-label-today-1',
        'proposal_id' => 'dynamic-label-today-1',
        'entity_type' => 'task',
        'entity_id' => 1,
        'title' => 'Today Proposal',
        'planned_start_at' => Carbon::parse('2026-04-20 12:00:00', config('app.timezone')),
        'planned_end_at' => Carbon::parse('2026-04-20 13:00:00', config('app.timezone')),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'dynamic-label-tomorrow-1',
        'proposal_id' => 'dynamic-label-tomorrow-1',
        'entity_type' => 'task',
        'entity_id' => 2,
        'title' => 'Tomorrow Proposal',
        'planned_start_at' => Carbon::parse('2026-04-21 12:00:00', config('app.timezone')),
        'planned_end_at' => Carbon::parse('2026-04-21 13:00:00', config('app.timezone')),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'dynamic-label-later-1',
        'proposal_id' => 'dynamic-label-later-1',
        'entity_type' => 'task',
        'entity_id' => 3,
        'title' => 'Later Proposal',
        'planned_start_at' => Carbon::parse('2026-04-22 12:00:00', config('app.timezone')),
        'planned_end_at' => Carbon::parse('2026-04-22 13:00:00', config('app.timezone')),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('Today')
        ->assertSee('Tomorrow')
        ->assertSee('Wednesday, April 22')
        ->assertSee('Today Proposal')
        ->assertSee('Tomorrow Proposal')
        ->assertSee('Later Proposal');
});

test('a day section disappears when its last item is removed while other sections stay', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));
    $this->actingAs($this->user);

    $taskToday = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-20 12:00:00', config('app.timezone')),
        'end_datetime' => Carbon::parse('2026-04-20 13:00:00', config('app.timezone')),
    ]);
    $taskTomorrow = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-21 12:00:00', config('app.timezone')),
        'end_datetime' => Carbon::parse('2026-04-21 13:00:00', config('app.timezone')),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'section-disappear-today-1',
        'proposal_id' => 'section-disappear-today-1',
        'entity_type' => 'task',
        'entity_id' => $taskToday->id,
        'title' => 'Today Section Proposal',
        'planned_start_at' => Carbon::parse('2026-04-20 12:00:00', config('app.timezone')),
        'planned_end_at' => Carbon::parse('2026-04-20 13:00:00', config('app.timezone')),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'section-disappear-tomorrow-1',
        'proposal_id' => 'section-disappear-tomorrow-1',
        'entity_type' => 'task',
        'entity_id' => $taskTomorrow->id,
        'title' => 'Tomorrow Section Proposal',
        'planned_start_at' => Carbon::parse('2026-04-21 12:00:00', config('app.timezone')),
        'planned_end_at' => Carbon::parse('2026-04-21 13:00:00', config('app.timezone')),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('Today')
        ->assertSee('Tomorrow')
        ->assertSee('Today Section Proposal')
        ->assertSee('Tomorrow Section Proposal');

    Livewire::test('pages::workspace.index')
        ->call('dismissScheduledFocusForEntity', 'task', (int) $taskToday->id, 'task_datetime_updated');

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertDontSee('Today Section Proposal')
        ->assertSee('Tomorrow')
        ->assertSee('Tomorrow Section Proposal');
});

test('dismissing scheduled focus for an entity persists and does not return after reload', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));

    $this->actingAs($this->user);

    $plannedStart = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'));
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => $plannedStart,
        'end_datetime' => $plannedStart->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'persist-dismiss-1',
        'proposal_id' => 'persist-dismiss-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => $plannedStart,
        'planned_end_at' => $plannedStart->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    Livewire::test('pages::workspace.index')
        ->call('dismissScheduledFocusForEntity', 'task', (int) $task->id, 'task_datetime_updated');

    $item->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::Dismissed)
        ->and($item->dismissed_at)->not->toBeNull();

    Livewire::test('pages::workspace.index')
        ->assertSet('scheduledFocusPlanTotalCount', 0);
});

test('entity dismissal bumps workspace items version once', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));

    $this->actingAs($this->user);

    $plannedStart = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'));
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => $plannedStart,
        'end_datetime' => $plannedStart->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'version-bump-1',
        'proposal_id' => 'version-bump-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => $plannedStart,
        'planned_end_at' => $plannedStart->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    Livewire::test('pages::workspace.index')
        ->assertSet('workspaceItemsVersion', 0)
        ->call('dismissScheduledFocusForEntity', 'task', (int) $task->id, 'task_datetime_updated')
        ->assertSet('workspaceItemsVersion', 1);
});

test('task status done deactivates scheduled focus persistently', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));
    $this->actingAs($this->user);

    $plannedStart = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'));
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => $plannedStart,
        'end_datetime' => $plannedStart->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'task-status-done-1',
        'proposal_id' => 'task-status-done-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => $plannedStart,
        'planned_end_at' => $plannedStart->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', (int) $task->id, 'status', TaskStatus::Done->value, true);

    $item->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::Dismissed)
        ->and($item->dismissed_at)->not->toBeNull();
});

test('event status completed deactivates scheduled focus persistently', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));
    $this->actingAs($this->user);

    $plannedStart = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'));
    $event = Event::factory()->for($this->user)->create([
        'status' => EventStatus::Scheduled,
        'start_datetime' => $plannedStart,
        'end_datetime' => $plannedStart->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'event-status-completed-1',
        'proposal_id' => 'event-status-completed-1',
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'title' => $event->title,
        'planned_start_at' => $plannedStart,
        'planned_end_at' => $plannedStart->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', (int) $event->id, 'status', EventStatus::Completed->value, true);

    $item->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::Dismissed)
        ->and($item->dismissed_at)->not->toBeNull();
});

test('panel hides when last item is removed and reappears when new proposal is accepted', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00', config('app.timezone')));
    $this->actingAs($this->user);

    $plannedStart = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'));
    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'start_datetime' => $plannedStart,
        'end_datetime' => $plannedStart->copy()->addHour(),
    ]);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'thread_id' => null,
        'assistant_message_id' => null,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'panel-hide-show-1',
        'proposal_id' => 'panel-hide-show-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => $plannedStart,
        'planned_end_at' => $plannedStart->copy()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace'))
        ->assertSuccessful()
        ->assertSee('AI Proposed Schedule');

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', (int) $task->id, 'status', TaskStatus::Done->value, true);

    $this->get(route('workspace'))
        ->assertSuccessful()
        ->assertDontSee('AI Proposed Schedule');

    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'panel-hide-show-2',
        'proposal_id' => 'panel-hide-show-2',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => 'New Accepted Proposal',
        'planned_start_at' => $plannedStart->copy()->addDay(),
        'planned_end_at' => $plannedStart->copy()->addDay()->addHour(),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace'))
        ->assertSuccessful()
        ->assertSee('AI Proposed Schedule')
        ->assertSee('New Accepted Proposal');
});
