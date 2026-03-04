<?php

use App\Actions\Llm\BuildLlmContextAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

it('filters GeneralQuery task context to upcoming week tasks', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', config('app.timezone')));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Due soon',
        'status' => 'to_do',
        'completed_at' => null,
        'end_datetime' => CarbonImmutable::now()->addDays(3),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Due later',
        'status' => 'to_do',
        'completed_at' => null,
        'end_datetime' => CarbonImmutable::now()->addDays(15),
    ]);

    /** @var BuildLlmContextAction $build */
    $build = app(BuildLlmContextAction::class);

    $context = $build->execute(
        user: $user,
        intent: LlmIntent::GeneralQuery,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: null,
        userMessage: 'how many tasks do i need to do for this upcoming week?'
    );

    expect($context)->toHaveKey('tasks')
        ->and($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('Due soon');
});
