<?php

use App\Actions\Llm\RunLlmInferenceAction;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use App\Services\LlmInferenceService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('run inference returns structured result when ollama is reachable and prisma returns valid response', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Schedule for Friday 2pm',
                'reasoning' => 'Based on deadline and availability.',
                'start_datetime' => now()->next('Friday')->setTime(14, 0)->toIso8601String(),
                'end_datetime' => now()->next('Friday')->setTime(15, 0)->toIso8601String(),
                'priority' => 'high',
            ])
            ->withUsage(new Usage(100, 50)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'Schedule my dashboard task by Friday',
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning'])
        ->and($result->structured['entity_type'])->toBe('task')
        ->and($result->promptTokens)->toBeGreaterThan(0)
        ->and($result->completionTokens)->toBeGreaterThan(0)
        ->and($result->promptVersion)->not->toBeEmpty();
});

test('run inference retries schedule_tasks once when output violates requested window', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    // Ensure context has multiple tasks.
    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $t1 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Some task',
        'status' => 'to_do',
        'completed_at' => null,
    ]);
    $t2 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Another task',
        'status' => 'to_do',
        'completed_at' => null,
    ]);
    \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Extra task',
        'status' => 'to_do',
        'completed_at' => null,
    ]);

    Prism::fake([
        // First response: schedules outside the requested tonight window → sanitizer strips → triggers retry.
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Do something later.',
                'reasoning' => 'Because.',
                'scheduled_tasks' => [
                    [
                        'id' => $t1->id,
                        'title' => $t1->title,
                        'start_datetime' => '2026-03-14T10:30:00+08:00',
                        'duration' => 30,
                    ],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
        // Second response: within window and multiple tasks.
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Plan within the window.',
                'reasoning' => 'Based on your window.',
                'scheduled_tasks' => [
                    [
                        'id' => $t1->id,
                        'title' => $t1->title,
                        'start_datetime' => '2026-03-12T19:00:00+08:00',
                        'duration' => 60,
                    ],
                    [
                        'id' => $t2->id,
                        'title' => $t2->title,
                        'start_datetime' => '2026-03-12T20:30:00+08:00',
                        'duration' => 60,
                    ],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'From 7pm to 11pm tonight, create a realistic plan using my existing tasks. Include at least one break and don’t schedule more than 3 hours of focused work.',
        LlmIntent::ScheduleTasks,
        LlmEntityType::Multiple,
        null,
        null
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKey('scheduled_tasks')
        ->and($result->structured['scheduled_tasks'])->toBeArray()
        ->and($result->structured['scheduled_tasks'])->toHaveCount(2);
});

test('run inference falls back to deterministic schedule_tasks when retry still yields invalid schedule', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $t1 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Soon task 1',
        'status' => 'to_do',
        'completed_at' => null,
        'duration' => 60,
        'end_datetime' => now()->copy()->addDay(),
    ]);
    $t2 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Soon task 2',
        'status' => 'to_do',
        'completed_at' => null,
        'duration' => 45,
        'end_datetime' => now()->copy()->addDays(2),
    ]);

    Prism::fake([
        // Initial response: empty scheduled_tasks.
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Here is a plan in text only.',
                'reasoning' => 'Because.',
                'scheduled_tasks' => [],
            ])
            ->withUsage(new Usage(10, 10)),
        // Retry response: still invalid (out of window, will be stripped -> 0).
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Still wrong.',
                'reasoning' => 'Because.',
                'scheduled_tasks' => [
                    [
                        'id' => $t1->id,
                        'title' => $t1->title,
                        'start_datetime' => '2026-03-14T10:30:00+08:00',
                        'duration' => 30,
                    ],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'From 7pm to 11pm tonight, create a realistic plan using my existing tasks. Include at least one break and don’t schedule more than 3 hours of focused work.',
        LlmIntent::ScheduleTasks,
        LlmEntityType::Multiple,
        null,
        null
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKey('scheduled_tasks')
        ->and($result->structured['scheduled_tasks'])->toBeArray()
        ->and($result->structured['scheduled_tasks'])->not->toBeEmpty()
        ->and(count($result->structured['scheduled_tasks']))->toBeGreaterThanOrEqual(2);
});

test('run inference exposes filtering summary in context facts for filtered prioritize requests', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    $examTag = Tag::factory()->for($this->user)->create(['name' => 'Exam']);
    $task = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'MATH 201 - Problem Set 4',
        'status' => 'to_do',
        'completed_at' => null,
    ]);
    $task->tags()->sync([$examTag->id]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Start with MATH 201 - Problem Set 4.',
                'reasoning' => 'It is your top exam task.',
                'ranked_tasks' => [
                    ['rank' => 1, 'title' => 'MATH 201 - Problem Set 4'],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'Look at everything tagged as "Exam" and prioritize it from most to least urgent.',
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->contextFacts)->toBeArray()
        ->and($result->contextFacts)->toHaveKey('filtering_summary')
        ->and($result->contextFacts['filtering_summary']['applied'] ?? null)->toBeTrue()
        ->and($result->contextFacts['filtering_summary']['dimensions'] ?? [])->toContain('required_tag')
        ->and($result->contextFacts['filtering_summary']['counts']['tasks'] ?? null)->toBe(1);
});

test('run inference routes plan_time_block through canonical schedule_tasks orchestration', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $t1 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Window task 1',
        'status' => 'to_do',
        'completed_at' => null,
        'duration' => 60,
    ]);
    $t2 = \App\Models\Task::factory()->for($this->user)->create([
        'title' => 'Window task 2',
        'status' => 'to_do',
        'completed_at' => null,
        'duration' => 45,
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Here is a focused evening plan.',
                'reasoning' => 'Both tasks fit your requested window.',
                'scheduled_tasks' => [
                    [
                        'id' => $t1->id,
                        'title' => $t1->title,
                        'start_datetime' => '2026-03-12T19:00:00+08:00',
                        'duration' => 60,
                    ],
                    [
                        'id' => $t2->id,
                        'title' => $t2->title,
                        'start_datetime' => '2026-03-12T20:30:00+08:00',
                        'duration' => 45,
                    ],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'From 7pm to 11pm tonight, create a realistic plan using my existing tasks.',
        LlmIntent::PlanTimeBlock,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKey('scheduled_tasks')
        ->and($result->structured['scheduled_tasks'])->toHaveCount(2);
});

test('schedule_tasks followup for tomorrow morning to afternoon schedules all previous list tasks via deterministic fallback when model under-schedules', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-03-12 10:00:00', config('app.timezone')));

    $taskTitles = [
        'ENG 105 – Reading Response #3',
        'ITCS 101 – Midterm Project Checkpoint',
        'Practice coding interview problems',
        'Finish CS 220 report and slides',
    ];

    $tasks = [];
    foreach ($taskTitles as $title) {
        $tasks[] = Task::factory()->for($this->user)->create([
            'title' => $title,
            'status' => 'to_do',
            'completed_at' => null,
        ]);
    }

    $thread = AssistantThread::factory()->for($this->user)->create();

    AssistantMessage::factory()->for($thread, 'assistantThread')->create([
        'role' => 'user',
        'content' => 'List my top 5 tasks for today that are school-related, not chores.',
    ]);

    AssistantMessage::factory()->for($thread, 'assistantThread')->create([
        'role' => 'assistant',
        'content' => 'Here are your top tasks.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'ranked_tasks' => [
                        ['rank' => 1, 'title' => $taskTitles[0]],
                        ['rank' => 2, 'title' => $taskTitles[1]],
                        ['rank' => 3, 'title' => $taskTitles[2]],
                        ['rank' => 4, 'title' => $taskTitles[3]],
                    ],
                ],
            ],
        ],
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Schedule your ENG 105 – Reading Response #3 and ITCS 101 – Midterm Project Checkpoint.',
                'reasoning' => 'Based on your request.',
                'scheduled_tasks' => [
                    [
                        'id' => $tasks[0]->id,
                        'title' => $tasks[0]->title,
                        'start_datetime' => '2026-03-13T09:00:00+08:00',
                        'duration' => 240,
                    ],
                    [
                        'id' => $tasks[1]->id,
                        'title' => $tasks[1]->title,
                        'start_datetime' => '2026-03-13T09:01:00+08:00',
                        'duration' => 40,
                    ],
                ],
            ])
            ->withUsage(new Usage(10, 10)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'schedule those tasks for tomorrow morning to afternoon',
        LlmIntent::ScheduleTasks,
        LlmEntityType::Multiple,
        null,
        $thread
    );

    $scheduled = $result->structured['scheduled_tasks'] ?? [];

    expect($scheduled)->toBeArray()
        ->and(count($scheduled))->toBeGreaterThanOrEqual(2);

    $startLower = \Carbon\CarbonImmutable::parse('2026-03-13T08:00:00', config('app.timezone'));
    $startUpper = \Carbon\CarbonImmutable::parse('2026-03-13T17:00:00', config('app.timezone'));

    $starts = [];
    foreach ($scheduled as $item) {
        $start = \Carbon\CarbonImmutable::parse($item['start_datetime'], config('app.timezone'));
        $starts[] = $start;
        expect($start->gte($startLower))->toBeTrue()
            ->and($start->lte($startUpper))->toBeTrue();
    }

    usort($starts, static fn (\Carbon\CarbonImmutable $a, \Carbon\CarbonImmutable $b): int => $a->lt($b) ? -1 : 1);
    for ($i = 1, $n = count($starts); $i < $n; $i++) {
        expect($starts[$i - 1]->diffInMinutes($starts[$i], false))->toBeGreaterThanOrEqual(10);
    }
});

test('run inference returns fallback when ollama is not reachable', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'What should I focus on today?',
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeTrue()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning'])
        ->and($result->promptTokens)->toBe(0)
        ->and($result->completionTokens)->toBe(0);
});

test('prioritize_tasks fallback includes rule-based ranked tasks when user provided', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'What should I focus on?',
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeTrue()
        ->and($result->structured)->toHaveKey('entity_type')
        ->and($result->structured['entity_type'])->toBe('task');
});

test('inference accepts LLM response with leading spaces in JSON keys and trims them', function (): void {
    $structuredWithSpacedKeys = [
        ' entity_type' => 'task',
        ' recommended_action' => 'Focus on Write chapter 1.',
        ' reasoning' => 'Soonest deadline.',
        ' ranked_tasks' => [
            [' rank' => 1, ' title' => 'Write chapter 1'],
            [' rank' => 2, ' title' => 'Get contractor quotes', ' end_datetime' => '2026-03-16T23:59:56+08:00'],
        ],
    ];

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured($structuredWithSpacedKeys)
            ->withUsage(new Usage(10, 20)),
    ]);

    $promptResult = new LlmSystemPromptResult(systemPrompt: 'You are a helpful assistant.', version: 'v1.1');
    $service = app(LlmInferenceService::class);
    $result = $service->infer(
        'You are a helpful assistant.',
        'Prioritize my tasks.',
        LlmIntent::PrioritizeTasks,
        $promptResult,
        $this->user
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning', 'ranked_tasks'])
        ->and($result->structured['entity_type'])->toBe('task')
        ->and($result->structured['ranked_tasks'])->toHaveCount(2)
        ->and($result->structured['ranked_tasks'][0])->toHaveKeys(['rank', 'title'])
        ->and($result->structured['ranked_tasks'][0]['title'])->toBe('Write chapter 1')
        ->and($result->promptTokens)->toBe(10)
        ->and($result->completionTokens)->toBe(20);
});

test('inference result toArray returns expected keys', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'Hello',
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        null
    );

    $arr = $result->toArray();

    expect($arr)->toHaveKeys(['structured', 'prompt_version', 'prompt_tokens', 'completion_tokens', 'used_fallback']);
});
