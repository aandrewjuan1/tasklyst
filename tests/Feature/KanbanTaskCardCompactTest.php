<?php

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('omits status pill markup for kanban layout and keeps it for list layout', function (): void {
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create();
    $task->load(['tags', 'project', 'event', 'recurringTask', 'collaborators']);

    $tags = collect();

    $kanbanHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="kanban"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    $listHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="list"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    expect($kanbanHtml)->not->toContain(__('Status').':')
        ->and($listHtml)->toContain(__('Status').':');
});

it('uses the same source link chip classes in kanban and list layouts', function (): void {
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create([
        'source_url' => 'https://example.com/task',
    ]);
    $task->load(['tags', 'project', 'event', 'recurringTask', 'collaborators']);

    $tags = collect();

    $expectedChipClasses = 'inline-flex items-center gap-1.5 transition-[box-shadow,transform] duration-150 ease-out';

    $kanbanHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="kanban"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    $listHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="list"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    expect($kanbanHtml)->toContain($expectedChipClasses)
        ->and($listHtml)->toContain($expectedChipClasses);
});

it('uses the shared workspace focus trigger styling in kanban layout', function (): void {
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create();
    $task->load(['tags', 'project', 'event', 'recurringTask', 'collaborators']);

    $tags = collect();

    $kanbanHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="kanban"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    expect($kanbanHtml)->toContain('class="workspace-focus-trigger"')
        ->and($kanbanHtml)->toContain('x-ref="focusTrigger"');
});

it('renders deadline label metadata in due date picker for list and kanban task cards', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 09:00:00'));
    $this->actingAs($this->user);

    $task = Task::factory()->for($this->user)->create([
        'end_datetime' => Carbon::parse('2026-04-13 12:00:00'),
    ]);
    $task->load(['tags', 'project', 'event', 'recurringTask', 'collaborators']);
    $tags = collect();

    $kanbanHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="kanban"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    $listHtml = Blade::render(
        '<x-workspace.list-item-task
            :item="$task"
            :available-tags="$tags"
            update-property-method="updateTaskProperty"
            layout="list"
        />',
        ['task' => $task, 'tags' => $tags]
    );

    expect($kanbanHtml)->toContain('useDeadlineLabel: true')
        ->and($kanbanHtml)->toContain("initialDeadlineLabel: 'Due tomorrow'")
        ->and($kanbanHtml)->toContain('date-picker-root-soon')
        ->and($listHtml)->toContain('useDeadlineLabel: true')
        ->and($listHtml)->toContain("initialDeadlineLabel: 'Due tomorrow'")
        ->and($listHtml)->toContain('date-picker-root-soon');
    expect($kanbanHtml)->not->toContain('rounded-full border px-2.5 py-0.5 text-[11px] font-semibold')
        ->and($listHtml)->not->toContain('rounded-full border px-2.5 py-0.5 text-[11px] font-semibold');

    Carbon::setTestNow();
});
