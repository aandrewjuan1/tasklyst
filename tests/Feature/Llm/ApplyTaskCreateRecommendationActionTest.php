<?php

use App\Actions\Llm\ApplyTaskCreateRecommendationAction;
use App\DataTransferObjects\Llm\TaskCreateRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

it('creates a task from accepted LLM create recommendation', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $start = Carbon::now()->addDay()->setTime(9, 0)->toImmutable();
    $end = Carbon::now()->addDay()->setTime(10, 0)->toImmutable();

    $dto = new TaskCreateRecommendationDto(
        title: 'Read chapter 3',
        description: 'Focus on sections 3.1 and 3.2.',
        startDatetime: Carbon::instance($start),
        endDatetime: Carbon::instance($end),
        durationMinutes: 60,
        priority: 'high',
        tagNames: ['reading'],
        reasoning: 'This will keep you on track for the upcoming quiz.',
    );

    $action = app(ApplyTaskCreateRecommendationAction::class);
    $action->execute(
        user: $user,
        recommendation: $dto,
        intent: LlmIntent::CreateTask,
        userAction: 'accept',
    );

    /** @var Task|null $task */
    $task = Task::query()->where('user_id', $user->id)->where('title', 'Read chapter 3')->first();

    expect($task)->not->toBeNull()
        ->and((string) $task->priority?->value)->toBe('high')
        ->and($task->start_datetime)->not->toBeNull()
        ->and($task->end_datetime)->not->toBeNull();
});

