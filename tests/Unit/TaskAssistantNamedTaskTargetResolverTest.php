<?php

use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantNamedTaskTargetResolver;

test('resolver returns up to three resolved named targets', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $math = Task::factory()->for($user)->create(['title' => 'math assignment']);
    $run = Task::factory()->for($user)->create(['title' => '5km run']);
    $review = Task::factory()->for($user)->create(['title' => 'review notes']);

    $result = app(TaskAssistantNamedTaskTargetResolver::class)
        ->resolve($thread, 'schedule my math assignment, 5km run, and review notes today');

    expect($result['status'])->toBe('multi');
    expect($result['target_entities'])->toHaveCount(3);
    expect(array_map(
        static fn (array $row): int => (int) ($row['entity_id'] ?? 0),
        $result['target_entities']
    ))->toContain($math->id, $run->id, $review->id);
    expect($result['unresolved_phrases'])->toBe([]);
    expect($result['ambiguous_groups'])->toBe([]);
});

test('resolver returns partial status for unresolved named phrases', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $math = Task::factory()->for($user)->create(['title' => 'math assignment']);

    $result = app(TaskAssistantNamedTaskTargetResolver::class)
        ->resolve($thread, 'schedule my math assignment and impossible ghost task today');

    expect($result['status'])->toBe('partial');
    expect($result['target_entities'])->toHaveCount(1);
    expect((int) ($result['target_entities'][0]['entity_id'] ?? 0))->toBe($math->id);
    expect($result['unresolved_phrases'])->not->toBeEmpty();
});

test('resolver returns consolidated ambiguity groups for multiple ambiguous names', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create(['title' => 'morning 5km run']);
    Task::factory()->for($user)->create(['title' => 'evening 5km run']);
    Task::factory()->for($user)->create(['title' => 'morning english assignment']);
    Task::factory()->for($user)->create(['title' => 'evening english assignment']);

    $result = app(TaskAssistantNamedTaskTargetResolver::class)
        ->resolve($thread, 'schedule my 5km run and english assignment today');

    expect($result['status'])->toBe('ambiguous');
    expect($result['ambiguous_groups'])->toHaveCount(2);
    expect((string) ($result['clarification_question'] ?? ''))->toContain('5km run');
    expect((string) ($result['clarification_question'] ?? ''))->toContain('english assignment');
});
