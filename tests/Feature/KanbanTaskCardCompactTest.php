<?php

use App\Models\Task;
use App\Models\User;
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

    $expectedChipClasses = 'inline-flex items-center gap-1.5 rounded-full border border-blue-500/25 bg-blue-500/15 px-2.5 py-0.5 text-xs font-medium text-blue-700 transition-colors hover:bg-blue-500/20 hover:text-blue-800';

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
