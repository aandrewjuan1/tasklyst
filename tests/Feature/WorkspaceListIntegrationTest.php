<?php

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\EventStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
use App\Models\Event;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('recurring task with past parent end_datetime still appears on today workspace list', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'title' => 'RecurringPastEndWorkspace',
        'start_datetime' => now()->subWeek()->startOfDay(),
        'end_datetime' => now()->subWeek()->endOfDay(),
        'status' => TaskStatus::ToDo,
    ]);

    RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->subDays(30)->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString());

    $titles = $component->instance()->getAllListEntries()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('RecurringPastEndWorkspace');
});

test('workspace list dedupes overdue task that also matches selected date', function (): void {
    $yesterday = now()->subDay()->startOfDay();
    $task = Task::factory()->for($this->user)->create([
        'title' => 'OverlapDup',
        'start_datetime' => $yesterday->copy()->subDay(),
        'end_datetime' => $yesterday->copy()->addHours(12),
        'status' => TaskStatus::ToDo,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', $yesterday->toDateString());

    $entries = $component->instance()->getAllListEntries();
    $taskRows = $entries->filter(fn (array $e): bool => $e['kind'] === 'task' && (int) $e['item']->id === $task->id);

    expect($taskRows)->toHaveCount(1);
});

test('workspace list view shows empty hint on item creation when there are no items for the selected day', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('data-test="workspace-item-creation"', false)
        ->assertSee('data-test="workspace-item-creation-empty"', false)
        ->assertSee(
            __('No tasks, projects, events, or classes for :date', ['date' => __('today')]),
            false
        );
});

test('workspace list aggregates all day tasks without planner section metadata', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Overdue Section Task',
        'start_datetime' => Carbon::parse('2026-04-10 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-10 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Today Section Task',
        'start_datetime' => Carbon::parse('2026-04-13 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-13 18:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Tomorrow Section Task',
        'start_datetime' => Carbon::parse('2026-04-14 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-14 11:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Upcoming Section Task',
        'start_datetime' => Carbon::parse('2026-04-16 10:00:00'),
        'end_datetime' => Carbon::parse('2026-04-16 11:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    $entries = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'all_items')
        ->set('selectedDate', '2026-04-16')
        ->instance()
        ->getAllListEntries();

    $titles = $entries->pluck('item.title')->all();

    expect($titles)->toContain('Overdue Section Task')
        ->and($titles)->toContain('Today Section Task')
        ->and($titles)->toContain('Tomorrow Section Task')
        ->and($titles)->toContain('Upcoming Section Task');

    foreach ($entries as $entry) {
        expect($entry)->not->toHaveKey('plannerSection');
    }
});

test('workspace list orders overdue tasks by urgency first', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Low Later Overdue',
        'priority' => 'low',
        'start_datetime' => Carbon::parse('2026-04-12 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-12 17:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    Task::factory()->for($this->user)->create([
        'title' => 'Urgent Earlier Overdue',
        'priority' => 'urgent',
        'start_datetime' => Carbon::parse('2026-04-11 08:00:00'),
        'end_datetime' => Carbon::parse('2026-04-11 09:00:00'),
        'status' => TaskStatus::ToDo,
    ]);

    $titles = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-13')
        ->instance()
        ->getAllListEntries()
        ->filter(fn (array $entry): bool => ($entry['isOverdue'] ?? false) === true && $entry['kind'] === 'task')
        ->pluck('item.title')
        ->values()
        ->all();

    expect($titles)->toBe([
        'Urgent Earlier Overdue',
        'Low Later Overdue',
    ]);
});

test('workspace hides completed tasks by default and shows them when toggle is enabled', function (): void {
    $this->actingAs($this->user);

    Task::factory()->for($this->user)->create([
        'title' => 'Hidden Done Task',
        'status' => TaskStatus::Done,
        'start_datetime' => now()->subHour(),
        'end_datetime' => now()->addHour(),
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString())
        ->set('showCompleted', '0');

    $activeTitles = $component->instance()
        ->getAllListEntries()
        ->pluck('item.title')
        ->all();

    expect($activeTitles)->not->toContain('Hidden Done Task');

    $component->set('showCompleted', '1');

    $activeTitlesWhenShowingCompleted = $component->instance()
        ->getAllListEntries()
        ->pluck('item.title')
        ->all();

    $completedTitles = $component->instance()
        ->completedListEntries()
        ->pluck('item.title')
        ->all();

    expect($activeTitlesWhenShowingCompleted)->not->toContain('Hidden Done Task')
        ->and($completedTitles)->toContain('Hidden Done Task');
});

test('workspace list shows scheduled focus panel when assistant accepted plan items exist', function (): void {
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
        'proposal_uuid' => 'scheduled-focus-1',
        'proposal_id' => 'scheduled-focus-1',
        'entity_type' => 'task',
        'entity_id' => 123,
        'title' => 'AI Planned Task',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [],
    ]);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('AI Planned Task')
        ->assertSee('Task')
        ->assertSee('Time:');
});

test('workspace list shows rescheduled badge for superseded latest plan item', function (): void {
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
        'proposal_uuid' => 'scheduled-focus-rescheduled-1',
        'proposal_id' => 'scheduled-focus-rescheduled-1',
        'entity_type' => 'task',
        'entity_id' => 321,
        'title' => 'Rescheduled Focus Task',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
        'metadata' => [
            'actions' => [
                'last_action' => 'rescheduled',
                'last_action_at' => now()->toIso8601String(),
            ],
            'rescheduled_from_previous_plan_item_count' => 1,
        ],
    ]);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('Rescheduled Focus Task')
        ->assertSee('Rescheduled');
});

test('workspace list hides scheduled focus panel when no active assistant plan items exist', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertDontSee('AI Proposed Schedule');
});

test('workspace mark scheduled focus in progress updates both plan item and task', function (): void {
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
    ]);
    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
    ]);
    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'qa-doing-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => now()->addHour(),
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
    ]);

    Livewire::test('pages::workspace.index')
        ->call('markScheduledFocusInProgress', $item->id);

    $item->refresh();
    $task->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::InProgress);
    expect($task->status)->toBe(TaskStatus::Doing);
    expect($task->start_datetime)->not->toBeNull();
});

test('workspace mark scheduled focus done updates both plan item and task', function (): void {
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create([
        'status' => TaskStatus::Doing,
        'priority' => TaskPriority::High,
    ]);
    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
    ]);
    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'qa-done-1',
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'title' => $task->title,
        'planned_start_at' => now()->addHour(),
        'status' => AssistantSchedulePlanItemStatus::InProgress,
        'accepted_at' => now(),
    ]);

    Livewire::test('pages::workspace.index')
        ->call('markScheduledFocusDone', $item->id);

    $item->refresh();
    $task->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::Completed);
    expect($item->completed_at)->not->toBeNull();
    expect($task->status)->toBe(TaskStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('workspace reschedule scheduled focus updates both plan item and event', function (): void {
    $this->actingAs($this->user);

    $event = Event::factory()->for($this->user)->create([
        'status' => EventStatus::Scheduled,
        'start_datetime' => now()->addHour(),
        'end_datetime' => now()->addHours(2),
    ]);
    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
    ]);
    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'qa-reschedule-1',
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'title' => $event->title,
        'planned_start_at' => now()->addHours(3),
        'planned_end_at' => now()->addHours(4),
        'planned_duration_minutes' => 60,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
    ]);

    $start = now()->addDay()->setTime(13, 0);
    $end = now()->addDay()->setTime(15, 0);

    Livewire::test('pages::workspace.index')
        ->call('rescheduleScheduledFocusItem', $item->id, $start->toIso8601String(), $end->toIso8601String());

    $item->refresh();
    $event->refresh();

    expect($event->start_datetime?->toIso8601String())->toContain($start->format('Y-m-d\TH'));
    expect($event->end_datetime?->toIso8601String())->toContain($end->format('Y-m-d\TH'));
    expect($item->planned_start_at?->toIso8601String())->toContain($start->format('Y-m-d\TH'));
    expect($item->planned_end_at?->toIso8601String())->toContain($end->format('Y-m-d\TH'));
    expect($item->planned_duration_minutes)->toBe(120);
});

test('workspace dismiss scheduled focus item removes it from active panel counts', function (): void {
    $this->actingAs($this->user);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
    ]);
    $item = AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'qa-dismiss-1',
        'entity_type' => 'project',
        'entity_id' => 999,
        'title' => 'Dismiss me',
        'planned_start_at' => now()->addHour(),
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
    ]);

    $component = Livewire::test('pages::workspace.index');
    expect($component->instance()->scheduledFocusPlanTotalCount)->toBe(1);

    $component->call('dismissScheduledFocusItem', $item->id);
    $item->refresh();

    expect($item->status)->toBe(AssistantSchedulePlanItemStatus::Dismissed);

    $component = Livewire::test('pages::workspace.index');
    expect($component->instance()->scheduledFocusPlanTotalCount)->toBe(0);
});

test('workspace scheduled focus shows human-readable duration labels', function (): void {
    $this->actingAs($this->user);

    $plan = AssistantSchedulePlan::query()->create([
        'user_id' => $this->user->id,
        'source' => 'assistant_accept_all',
        'accepted_at' => now(),
    ]);
    AssistantSchedulePlanItem::query()->create([
        'assistant_schedule_plan_id' => $plan->id,
        'user_id' => $this->user->id,
        'proposal_uuid' => 'qa-duration-1',
        'entity_type' => 'task',
        'entity_id' => 111,
        'title' => 'Long focus block',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(5),
        'planned_duration_minutes' => 240,
        'status' => AssistantSchedulePlanItemStatus::Planned,
        'accepted_at' => now(),
    ]);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('4 hours')
        ->assertDontSee('240 mins');
});

test('workspace list region skeleton loading targets include selected date', function (): void {
    $this->actingAs($this->user);

    $html = Livewire::test('pages::workspace.index')->html();

    expect($html)->toMatch('/wire:target="[^"]*selectedDate[^"]*"/');
});
