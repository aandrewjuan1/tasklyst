<?php

use App\Models\Task;
use App\Models\User;
use App\Services\Llm\RuleBasedPrioritizationService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(RuleBasedPrioritizationService::class);
});

test('prioritize tasks returns overdue task first then by due date', function (): void {
    $overdue = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->subDay(),
        'completed_at' => null,
        'title' => 'Overdue',
    ]);
    $dueTomorrow = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->addDay(),
        'completed_at' => null,
        'title' => 'Due tomorrow',
    ]);
    $dueNextWeek = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->addWeek(),
        'completed_at' => null,
        'title' => 'Due next week',
    ]);

    $result = $this->service->prioritizeTasks($this->user, 12);

    $ids = $result->pluck('id')->all();
    expect($ids[0])->toBe($overdue->id)
        ->and($ids[1])->toBe($dueTomorrow->id)
        ->and($ids[2])->toBe($dueNextWeek->id);
});

test('prioritize tasks excludes completed tasks', function (): void {
    $incomplete = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->addDay(),
        'completed_at' => null,
        'title' => 'Incomplete',
    ]);
    $completed = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->subDay(),
        'completed_at' => now(),
        'title' => 'Completed',
    ]);

    $result = $this->service->prioritizeTasks($this->user, 12);

    expect($result->pluck('id')->all())->toContain($incomplete->id)
        ->and($result->pluck('id')->all())->not->toContain($completed->id);
});

test('prioritize tasks respects limit', function (): void {
    foreach (range(1, 5) as $i) {
        Task::factory()->for($this->user)->create([
            'end_datetime' => now()->addDays($i),
            'completed_at' => null,
        ]);
    }

    $result = $this->service->prioritizeTasks($this->user, 3);

    expect($result)->toHaveCount(3);
});

test('prioritize tasks orders by priority when due dates are equal', function (): void {
    $due = now()->addDay();
    $low = Task::factory()->for($this->user)->create([
        'end_datetime' => $due,
        'priority' => \App\Enums\TaskPriority::Low,
        'completed_at' => null,
        'title' => 'Low',
    ]);
    $urgent = Task::factory()->for($this->user)->create([
        'end_datetime' => $due,
        'priority' => \App\Enums\TaskPriority::Urgent,
        'completed_at' => null,
        'title' => 'Urgent',
    ]);
    $medium = Task::factory()->for($this->user)->create([
        'end_datetime' => $due,
        'priority' => \App\Enums\TaskPriority::Medium,
        'completed_at' => null,
        'title' => 'Medium',
    ]);

    $result = $this->service->prioritizeTasks($this->user, 12);

    $ids = $result->pluck('id')->all();
    $urgentPos = array_search($urgent->id, $ids, true);
    $mediumPos = array_search($medium->id, $ids, true);
    $lowPos = array_search($low->id, $ids, true);
    expect($urgentPos)->toBeLessThan($mediumPos)
        ->and($mediumPos)->toBeLessThan($lowPos);
});

test('prioritize tasks returns only tasks for the given user', function (): void {
    $otherUser = User::factory()->create();
    $myTask = Task::factory()->for($this->user)->create([
        'end_datetime' => now()->addDay(),
        'completed_at' => null,
    ]);
    $otherTask = Task::factory()->for($otherUser)->create([
        'end_datetime' => now()->subDay(),
        'completed_at' => null,
    ]);

    $result = $this->service->prioritizeTasks($this->user, 12);

    expect($result->pluck('id')->all())->toContain($myTask->id)
        ->and($result->pluck('id')->all())->not->toContain($otherTask->id);
});

test('prioritize tasks buckets by overdue today soon later and no-date', function (): void {
    $overdue = Task::factory()->for($this->user)->create([
        'title' => 'Overdue',
        'end_datetime' => now()->subDay(),
        'completed_at' => null,
    ]);

    $today = Task::factory()->for($this->user)->create([
        'title' => 'Today',
        'end_datetime' => now()->addHours(2),
        'completed_at' => null,
    ]);

    $thisWeek = Task::factory()->for($this->user)->create([
        'title' => 'This week',
        'end_datetime' => now()->addDays(3),
        'completed_at' => null,
    ]);

    $later = Task::factory()->for($this->user)->create([
        'title' => 'Later',
        'end_datetime' => now()->addDays(10),
        'completed_at' => null,
    ]);

    $noDate = Task::factory()->for($this->user)->create([
        'title' => 'No date',
        'end_datetime' => null,
        'start_datetime' => null,
        'completed_at' => null,
    ]);

    $result = $this->service->prioritizeTasks($this->user, 10)->pluck('title')->values()->all();

    expect($result[0])->toBe('Overdue')
        ->and($result)->toContain('Today')
        ->and($result)->toContain('This week')
        ->and($result)->toContain('Later')
        ->and($result[count($result) - 1])->toBe('No date');
});
