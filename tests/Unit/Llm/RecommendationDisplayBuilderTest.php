<?php

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\RecommendationDisplayBuilder;

test('build returns display dto with validation confidence for prioritization', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Focus on overdue first.',
            'reasoning' => 'Step 1: Check overdue. Step 2: Rank by due date.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A', 'end_datetime' => now()->addDay()->toIso8601String()],
                ['rank' => 2, 'title' => 'Task B', 'end_datetime' => null],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto)->toBeInstanceOf(RecommendationDisplayDto::class)
        ->and($dto->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($dto->followupSuggestions)->toBeArray()
        ->and($dto->followupSuggestions)->not->toBeEmpty()
        ->and($dto->entityType)->toBe(LlmEntityType::Task)
        ->and($dto->recommendedAction)->toContain('Focus on overdue first.')
        ->and($dto->reasoning)->toContain('Step 1')
        ->and($dto->message)->toContain('Focus on overdue first.')
        ->and($dto->message)->toContain('Step 1')
        ->and($dto->message)->toContain('#1')
        ->and($dto->message)->toContain('Task A')
        ->and($dto->message)->toContain('#2')
        ->and($dto->message)->toContain('Task B')
        ->and($dto->validationConfidence)->toBeGreaterThan(0)
        ->and($dto->usedFallback)->toBeFalse()
        ->and($dto->structured)->toHaveKey('ranked_tasks')
        ->and($dto->structured['ranked_tasks'])->toHaveCount(2);
});

test('build computes validation confidence for schedule task with dates and priority', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Schedule for Friday 2pm.',
            'reasoning' => 'Based on your calendar.',
            'start_datetime' => now()->next('Friday')->setTime(14, 0)->toIso8601String(),
            'duration' => 60,
            'priority' => 'high',
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTask, LlmEntityType::Task);

    expect($dto->validationConfidence)->toBeGreaterThan(0.5)
        ->and($dto->structured)->toHaveKey('start_datetime')
        ->and($dto->structured)->toHaveKey('duration')
        ->and($dto->structured)->toHaveKey('priority')
        ->and($dto->structured)->not->toHaveKey('end_datetime');
});

test('build uses fallback flag from inference result', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Rule-based order.',
            'reasoning' => 'AI unavailable.',
            'ranked_tasks' => [],
        ],
        promptVersion: '1.0',
        promptTokens: 0,
        completionTokens: 0,
        usedFallback: true
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->usedFallback)->toBeTrue();
});

test('build fills default content when recommended action or reasoning empty', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => '',
            'reasoning' => '',
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 10,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::GeneralQuery, LlmEntityType::Task);

    expect($dto->recommendedAction)->not->toBeEmpty()
        ->and($dto->reasoning)->not->toBeEmpty()
        ->and($dto->message)->not->toBeEmpty();
});

test('build for PrioritizeTasksAndEvents with Multiple includes both task and event sections in message', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task,event',
            'recommended_action' => 'Focus on tasks first, then events.',
            'reasoning' => 'Tasks have deadlines; events are time-bound.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task Alpha', 'end_datetime' => null],
                ['rank' => 2, 'title' => 'Task Beta', 'end_datetime' => null],
            ],
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Event One', 'start_datetime' => now()->addDay()->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 120,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasksAndEvents, LlmEntityType::Multiple);

    expect($dto->intent)->toBe(LlmIntent::PrioritizeTasksAndEvents)
        ->and($dto->entityType)->toBe(LlmEntityType::Multiple)
        ->and($dto->message)->toContain('Focus on tasks first')
        ->and($dto->message)->toContain('Task Alpha')
        ->and($dto->message)->toContain('Task Beta')
        ->and($dto->message)->toContain('Event One')
        ->and($dto->structured)->toHaveKey('ranked_tasks')
        ->and($dto->structured)->toHaveKey('ranked_events')
        ->and($dto->followupSuggestions)->not->toBeEmpty()
        ->and($dto->validationConfidence)->toBeGreaterThan(0);
});

test('build for PrioritizeAll with Multiple includes tasks, events and projects sections in message', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'all',
            'recommended_action' => 'Focus on tasks first, then events, then projects.',
            'reasoning' => 'Unified order across all item types.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task One', 'end_datetime' => null],
            ],
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Event One', 'start_datetime' => now()->addDay()->toIso8601String()],
            ],
            'ranked_projects' => [
                ['rank' => 1, 'name' => 'Project One', 'end_datetime' => null],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 150,
        completionTokens: 80,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeAll, LlmEntityType::Multiple);

    expect($dto->intent)->toBe(LlmIntent::PrioritizeAll)
        ->and($dto->entityType)->toBe(LlmEntityType::Multiple)
        ->and($dto->message)->toContain('Focus on tasks first')
        ->and($dto->message)->toContain('Task One')
        ->and($dto->message)->toContain('Event One')
        ->and($dto->message)->toContain('Project One')
        ->and($dto->structured)->toHaveKey('ranked_tasks')
        ->and($dto->structured)->toHaveKey('ranked_events')
        ->and($dto->structured)->toHaveKey('ranked_projects')
        ->and($dto->followupSuggestions)->not->toBeEmpty()
        ->and($dto->validationConfidence)->toBeGreaterThan(0);
});

test('build for ScheduleTasksAndEvents with Multiple and one scheduled task produces appliable_changes and target_task_title', function (): void {
    $start = now()->addDay()->setTime(22, 0);
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task,event',
            'recommended_action' => 'Work on your most important task tonight at 10pm.',
            'reasoning' => 'Your highest priority task fits well tonight.',
            'scheduled_tasks' => [
                ['title' => 'My Light to the Society', 'start_datetime' => $start->toIso8601String(), 'duration' => 60],
            ],
            'scheduled_events' => [],
        ],
        promptVersion: '1.0',
        promptTokens: 120,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTasksAndEvents, LlmEntityType::Multiple);

    expect($dto->appliableChanges)->not->toBeEmpty()
        ->and($dto->appliableChanges['entity_type'])->toBe('task')
        ->and($dto->appliableChanges['properties'])->toHaveKey('startDatetime')
        ->and($dto->appliableChanges['properties'])->toHaveKey('duration')
        ->and($dto->appliableChanges['properties']['duration'])->toBe(60)
        ->and($dto->structured)->toHaveKey('target_task_title')
        ->and($dto->structured['target_task_title'])->toBe('My Light to the Society');
});

test('build for ScheduleTasksAndEvents with Multiple and two scheduled tasks uses first task and top-level schedule for appliable_changes', function (): void {
    $start = now()->addDay()->setTime(22, 0);
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task,event',
            'recommended_action' => 'Work on your most important tasks tonight at 10pm for 2 hours.',
            'reasoning' => 'Starting with the two urgent tasks due soon.',
            'start_datetime' => $start->toIso8601String(),
            'duration' => 120,
            'scheduled_tasks' => [
                ['title' => 'Antas/Teorya ng wika'],
                ['title' => 'Output # 1: My Light to the Society'],
            ],
            'scheduled_events' => [],
        ],
        promptVersion: '1.0',
        promptTokens: 150,
        completionTokens: 80,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTasksAndEvents, LlmEntityType::Multiple);

    expect($dto->appliableChanges)->not->toBeEmpty()
        ->and($dto->appliableChanges['entity_type'])->toBe('task')
        ->and($dto->appliableChanges['properties'])->toHaveKey('startDatetime')
        ->and($dto->appliableChanges['properties'])->toHaveKey('duration')
        ->and($dto->appliableChanges['properties']['duration'])->toBe(120)
        ->and($dto->structured)->toHaveKey('target_task_title')
        ->and($dto->structured['target_task_title'])->toBe('Antas/Teorya ng wika');
});

test('build for ScheduleAll with Multiple includes scheduled tasks, events and projects sections in message', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'all',
            'recommended_action' => 'Here are suggested times for your items.',
            'reasoning' => 'Based on your availability.',
            'scheduled_tasks' => [
                ['title' => 'Task One', 'start_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(), 'duration' => 60],
            ],
            'scheduled_events' => [
                ['title' => 'Event One', 'start_datetime' => now()->addDays(2)->setTime(14, 0)->toIso8601String(), 'end_datetime' => now()->addDays(2)->setTime(15, 0)->toIso8601String()],
            ],
            'scheduled_projects' => [
                ['name' => 'Project One', 'start_datetime' => now()->addDays(3)->setTime(9, 0)->toIso8601String(), 'end_datetime' => now()->addDays(5)->setTime(17, 0)->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 150,
        completionTokens: 80,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleAll, LlmEntityType::Multiple);

    expect($dto->intent)->toBe(LlmIntent::ScheduleAll)
        ->and($dto->entityType)->toBe(LlmEntityType::Multiple)
        ->and($dto->message)->toContain('Here are suggested times')
        ->and($dto->message)->toContain('Task One')
        ->and($dto->message)->toContain('Event One')
        ->and($dto->message)->toContain('Project One')
        ->and($dto->structured)->toHaveKey('scheduled_tasks')
        ->and($dto->structured)->toHaveKey('scheduled_events')
        ->and($dto->structured)->toHaveKey('scheduled_projects')
        ->and($dto->followupSuggestions)->not->toBeEmpty()
        ->and($dto->validationConfidence)->toBeGreaterThan(0);
});

test('build includes next_steps for resolve_dependency', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with the blocker first.',
            'reasoning' => 'Unblocks everything else.',
            'next_steps' => [
                'Email your tutor for feedback.',
                'Update the outline based on feedback.',
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ResolveDependency, LlmEntityType::Task);

    expect($dto->structured)->toHaveKey('next_steps')
        ->and($dto->structured['next_steps'])->toHaveCount(2)
        ->and($dto->validationConfidence)->toBeGreaterThan(0.5)
        ->and($dto->message)->toContain('Start with the blocker first.')
        ->and($dto->message)->toContain('Unblocks everything else.')
        ->and($dto->message)->toContain('1.')
        ->and($dto->message)->toContain('Email your tutor for feedback.')
        ->and($dto->message)->toContain('2.')
        ->and($dto->message)->toContain('Update the outline based on feedback.');
});

test('build includes ranked_events in message for prioritize_events', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'Prioritize these upcoming events you have scheduled:',
            'reasoning' => 'To effectively manage your time, focus on the soonest first.',
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Conference call'],
                ['rank' => 2, 'title' => 'Dentist appointment'],
                ['rank' => 3, 'title' => '23 BDAY'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeEvents, LlmEntityType::Event);

    expect($dto->message)->toContain('Prioritize these upcoming events you have scheduled:')
        ->and($dto->message)->toContain('#1')
        ->and($dto->message)->toContain('Conference call')
        ->and($dto->message)->toContain('#2')
        ->and($dto->message)->toContain('Dentist appointment')
        ->and($dto->message)->toContain('#3')
        ->and($dto->message)->toContain('23 BDAY')
        ->and($dto->message)->toContain('To effectively manage your time');
});

test('build combined message puts action first then reasoning with natural connector', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Focus on the essay due tomorrow.',
            'reasoning' => 'It has the nearest deadline and is high priority.',
            'ranked_tasks' => [['rank' => 1, 'title' => 'Essay', 'end_datetime' => null]],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('Focus on the essay due tomorrow.')
        ->and($dto->message)->toContain('It has the nearest deadline')
        ->and($dto->message)->not->toContain('Recommended action:')
        ->and($dto->message)->not->toContain('Reasoning:');
});

test('RecommendationDisplayDto toArray and fromArray include message', function (): void {
    $dto = RecommendationDisplayDto::fromArray([
        'intent' => 'prioritize_tasks',
        'entity_type' => 'task',
        'recommended_action' => 'Do A first.',
        'reasoning' => 'Because it is urgent.',
        'message' => 'Do A first. Here\'s why: Because it is urgent.',
        'validation_confidence' => 0.9,
        'used_fallback' => false,
        'fallback_reason' => null,
        'structured' => [],
        'followup_suggestions' => [
            'Schedule the top task for today.',
            'Show my tasks with no due date.',
        ],
    ]);

    $arr = $dto->toArray();
    expect($arr)->toHaveKey('message')
        ->and($arr['message'])->toBe('Do A first. Here\'s why: Because it is urgent.')
        ->and($arr)->toHaveKey('followup_suggestions')
        ->and($arr['followup_suggestions'])->toBeArray()
        ->and($arr['followup_suggestions'])->toHaveCount(2);

    $restored = RecommendationDisplayDto::fromArray($arr);
    expect($restored->message)->toBe($dto->message)
        ->and($restored->followupSuggestions)->toBe($dto->followupSuggestions);
});

test('build formats message with listed_items as summary then bullet list then reasoning', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Here are your tasks with no due date.',
            'reasoning' => 'These three tasks have end_datetime null in context.',
            'listed_items' => [
                ['title' => 'Task A'],
                ['title' => 'Task B', 'priority' => 'low'],
                ['title' => 'Task C', 'end_datetime' => null],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::GeneralQuery, LlmEntityType::Task);

    expect($dto->recommendedAction)->toBe('You have 3 tasks matching that request.')
        ->and($dto->message)->toContain('You have 3 tasks matching that request.')
        ->and($dto->message)->toContain('Task A')
        ->and($dto->message)->toContain('Task B')
        ->and($dto->message)->toContain('Task C')
        ->and($dto->message)->toContain('These three tasks')
        ->and($dto->structured)->toHaveKey('listed_items')
        ->and($dto->structured['listed_items'])->toHaveCount(3);
});

test('RecommendationDisplayDto fromArray builds message from action and reasoning when message missing', function (): void {
    $dto = RecommendationDisplayDto::fromArray([
        'intent' => 'general_query',
        'entity_type' => 'task',
        'recommended_action' => 'Add a due date to the task.',
        'reasoning' => 'That will help you prioritize.',
        'validation_confidence' => 0.8,
        'used_fallback' => false,
        'structured' => [],
    ]);

    expect($dto->message)->toContain('Add a due date to the task.')
        ->and($dto->message)->toContain('That will help you prioritize.')
        ->and($dto->message)->toContain("\n\n");
});
