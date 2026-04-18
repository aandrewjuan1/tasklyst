<?php

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\TaskStatus;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
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
        ->assertSee('Scheduled focus')
        ->assertSee('Kanban Scheduled Focus Row');
});
