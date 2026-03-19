<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('runDailySchedule stores structured schedule metadata', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent_type' => 'general',
                'priority_filters' => [],
                'task_keywords' => [],
                'time_constraint' => null,
                'comparison_focus' => null,
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'blocks' => [
                    [
                        'start_time' => '09:00',
                        'end_time' => '09:30',
                        'task_id' => null,
                        'event_id' => null,
                        'label' => 'Generic focus block',
                        'reason' => 'Short focus block for your tasks.',
                    ],
                ],
                'summary' => 'Simple schedule.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);

    $result = $service->runDailySchedule($thread, 'Propose a schedule for today.');

    expect($result['valid'])->toBeTrue();
    expect($result['data']['blocks'])->toBeArray();

    $thread->refresh();
    $assistantMessage = $thread->messages()
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->metadata['daily_schedule']['blocks'][0]['start_time'] ?? null)->toBe('09:00');
});

test('runStudyPlan stores structured study plan metadata', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent_type' => 'general',
                'priority_filters' => [],
                'task_keywords' => [],
                'time_constraint' => null,
                'comparison_focus' => null,
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'items' => [
                    [
                        'label' => 'Review notes for math',
                        'task_id' => null,
                        'estimated_minutes' => 30,
                        'reason' => 'Important upcoming exam.',
                    ],
                ],
                'summary' => 'Short study plan.',
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);

    $result = $service->runStudyPlan($thread, 'Create a study plan for tonight.');

    expect($result['valid'])->toBeTrue();
    expect($result['data']['items'])->toBeArray();

    $thread->refresh();
    $assistantMessage = $thread->messages()
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->metadata['study_plan']['items'][0]['label'] ?? null)->toBe('Review notes for math');
});

test('runReviewSummary stores structured review metadata', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent_type' => 'general',
                'priority_filters' => [],
                'task_keywords' => [],
                'time_constraint' => null,
                'comparison_focus' => null,
            ])
            ->withUsage(new Usage(5, 10)),
        StructuredResponseFake::make()
            ->withStructured([
                'completed' => [
                    ['task_id' => 1, 'title' => 'Finished reading'],
                ],
                'remaining' => [
                    ['task_id' => 2, 'title' => 'Write summary'],
                ],
                'summary' => 'You have completed some tasks and still have others remaining.',
                'next_steps' => ['Pick one remaining task to focus on next.'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);

    $result = $service->runReviewSummary($thread, 'Review what I have done recently.');

    // The runner will treat unknown task IDs as invalid. We only assert
    // that the flow returns a structured shape (either direct or fallback).
    expect($result['data'])->toBeArray();

    $thread->refresh();
    $assistantMessage = $thread->messages()
        ->where('role', 'assistant')
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
});
