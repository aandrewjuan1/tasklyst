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
        ->and($dto->followupSuggestions)->toBeEmpty()
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

test('build adds filter-first acknowledgement when filtering summary is present', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with Task A.',
            'reasoning' => 'It has the nearest due date.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false,
        contextFacts: [
            'filtering_summary' => [
                'applied' => true,
                'dimensions' => ['required_tag', 'task_priority'],
                'counts' => ['tasks' => 1, 'events' => 0, 'projects' => 0],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->recommendedAction)->toContain('Based on your request, I filtered your items')
        ->and($dto->recommendedAction)->toContain('using tag, priority')
        ->and($dto->recommendedAction)->not->toContain('required_tag')
        ->and($dto->recommendedAction)->toContain('I found 1 matching tasks')
        ->and($dto->reasoning)->toContain('I ranked only this filtered set');
});

test('build keeps original action when recommendation already acknowledges filtering', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Based on your request, I filtered to exam tasks and ranked them.',
            'reasoning' => 'Task A is due first.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false,
        contextFacts: [
            'filtering_summary' => [
                'applied' => true,
                'dimensions' => ['required_tag'],
                'counts' => ['tasks' => 1, 'events' => 0, 'projects' => 0],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->recommendedAction)->toBe('Based on your request, I filtered to exam tasks and ranked them.')
        ->and($dto->reasoning)->toBe('Task A is due first.');
});

test('build sanitizes internal key names in user-facing narrative globally', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'I used required_tag and task_priority from filtering_summary before ranking ranked_tasks. The top item is CS 220 Lab 5 (ID: 9).',
            'reasoning' => 'I can apply proposed_properties with start_datetime in appliable_changes. The follow-up is MATH 201 Quiz 3 with ID: 6.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 60,
        completionTokens: 40,
        usedFallback: false,
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->not->toContain('required_tag')
        ->and($dto->message)->not->toContain('task_priority')
        ->and($dto->message)->not->toContain('ranked_tasks')
        ->and($dto->message)->not->toContain('proposed_properties')
        ->and($dto->message)->not->toContain('start_datetime')
        ->and($dto->message)->not->toContain('appliable_changes')
        ->and($dto->message)->not->toMatch('/ID\s*[:#]\s*\d+/i')
        ->and($dto->message)->toContain('tag')
        ->and($dto->message)->toContain('priority')
        ->and($dto->message)->toContain('ranked tasks')
        ->and($dto->message)->toContain('suggested changes')
        ->and($dto->message)->toContain('start time');
});

test('build aligns first focus narrative with top ranked task title for prioritize tasks', function (): void {
    $topEnd = now()->addDay()->toIso8601String();

    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'First, focus on completing Task B as soon as possible today.',
            'reasoning' => 'Task B looks important.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A', 'end_datetime' => $topEnd],
                ['rank' => 2, 'title' => 'Task B', 'end_datetime' => now()->addDays(2)->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 60,
        completionTokens: 40,
        usedFallback: false,
        contextFacts: [
            'timezone' => config('app.timezone'),
            'task_facts_by_title' => [
                'Task A' => [
                    'end_datetime' => $topEnd,
                ],
                'Task B' => [
                    'end_datetime' => now()->addDays(2)->toIso8601String(),
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->recommendedAction)->toContain('Task A')
        ->and($dto->recommendedAction)->not->toContain('Task B as soon as possible today');
});

test('build aligns first focus when narrative uses paraphrased non-top task label', function (): void {
    $topEnd = now()->addDay()->toIso8601String();

    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'First, focus on completing Lab 5: Linked Lists for CS 220 immediately.',
            'reasoning' => 'Lab 5 is important.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => $topEnd],
                ['rank' => 2, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => now()->addDays(2)->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 60,
        completionTokens: 40,
        usedFallback: false,
        contextFacts: [
            'timezone' => config('app.timezone'),
            'task_facts_by_title' => [
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => $topEnd,
                ],
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => now()->addDays(2)->toIso8601String(),
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->recommendedAction)
        ->toContain('First, focus on completing MATH 201 – Problem Set 4: Relations.')
        ->and($dto->recommendedAction)->not->toContain('Lab 5: Linked Lists for CS 220');
});

test('build aligns first focus narrative with top ranked event title for prioritize events', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'First, focus on Conference call.',
            'reasoning' => 'It is important.',
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Math exam review session', 'start_datetime' => now()->addDay()->toIso8601String()],
                ['rank' => 2, 'title' => 'Conference call', 'start_datetime' => now()->addDays(2)->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 40,
        completionTokens: 30,
        usedFallback: false,
        contextFacts: [
            'timezone' => config('app.timezone'),
            'event_facts_by_title' => [
                'Math exam review session' => ['start_datetime' => now()->addDay()->toIso8601String()],
                'Conference call' => ['start_datetime' => now()->addDays(2)->toIso8601String()],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeEvents, LlmEntityType::Event);

    expect($dto->recommendedAction)->toContain('Math exam review session')
        ->and($dto->recommendedAction)->not->toContain('focus on Conference call');
});

test('build aligns first focus narrative with top ranked project name for prioritize projects', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'project',
            'recommended_action' => 'First, focus on Thesis Presentation.',
            'reasoning' => 'That one sounds urgent.',
            'ranked_projects' => [
                ['rank' => 1, 'name' => 'Capstone Final Report', 'end_datetime' => now()->addDays(2)->toIso8601String()],
                ['rank' => 2, 'name' => 'Thesis Presentation', 'end_datetime' => now()->addDays(4)->toIso8601String()],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 40,
        completionTokens: 30,
        usedFallback: false,
        contextFacts: [
            'timezone' => config('app.timezone'),
            'project_facts_by_name' => [
                'Capstone Final Report' => ['end_datetime' => now()->addDays(2)->toIso8601String()],
                'Thesis Presentation' => ['end_datetime' => now()->addDays(4)->toIso8601String()],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeProjects, LlmEntityType::Project);

    expect($dto->recommendedAction)->toContain('Capstone Final Report')
        ->and($dto->recommendedAction)->not->toContain('focus on Thesis Presentation');
});

test('build infers schedule for adjust_event_time from narrative when JSON omits times', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'Reschedule your exam to 9pm.',
            'reasoning' => 'Later this evening works best.',
            // no explicit start_datetime/end_datetime in JSON
        ],
        promptVersion: '1.0',
        promptTokens: 60,
        completionTokens: 30,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::AdjustEventTime, LlmEntityType::Event);

    expect($dto->appliableChanges)->toBeArray()
        ->and($dto->appliableChanges['entity_type'] ?? null)->toBe('event')
        ->and($dto->appliableChanges)->toHaveKey('properties')
        ->and($dto->appliableChanges['properties'])->not->toBeEmpty();
});

test('build for ScheduleTask preserves recommended action text without prefixing target title', function (): void {
    $start = now()->addDay()->setTime(19, 0);
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'id' => 123,
            'title' => 'PRELIM DEPT EXAM: Komunikasyon Sa Akademikong Filipino',
            'recommended_action' => 'Schedule for tonight at 7pm for 60 minutes.',
            'reasoning' => 'I chose 7pm because your evening is free.',
            'start_datetime' => $start->toIso8601String(),
            'duration' => 60,
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTask, LlmEntityType::Task);

    expect($dto->recommendedAction)->toBe('Schedule for tonight at 7pm for 60 minutes.')
        ->and($dto->message)->toContain('Schedule for tonight at 7pm for 60 minutes.');
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
        ->and($dto->followupSuggestions)->toBeEmpty()
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
        ->and($dto->followupSuggestions)->toBeEmpty()
        ->and($dto->validationConfidence)->toBeGreaterThan(0);
});

test('build for ScheduleTasksAndEvents with Multiple stays readonly for appliable changes', function (): void {
    $start = now()->addDay()->setTime(22, 0);
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task,event',
            'recommended_action' => 'Work on your most important task tonight at 10pm.',
            'reasoning' => 'Your highest priority task fits well tonight.',
            'scheduled_tasks' => [
                ['id' => 42, 'title' => 'My Light to the Society', 'start_datetime' => $start->toIso8601String(), 'duration' => 60],
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

    expect($dto->appliableChanges)->toBeEmpty()
        ->and($dto->structured)->not->toHaveKey('target_task_title')
        ->and($dto->structured)->not->toHaveKey('target_task_id');
});

test('build for ScheduleTask without id does not set target_task_id but still builds appliable_changes from schedule', function (): void {
    $start = now()->addDay()->setTime(17, 0);
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Schedule your "My Light to the Society" output for later today at 5pm for 1 hour.',
            'reasoning' => 'I chose 5pm because your afternoon is free.',
            'start_datetime' => $start->toIso8601String(),
            'duration' => 60,
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTask, LlmEntityType::Task);

    expect($dto->appliableChanges)->toBeArray()
        ->and($dto->appliableChanges['entity_type'] ?? null)->toBe('task')
        ->and($dto->appliableChanges)->toHaveKey('properties')
        ->and($dto->appliableChanges)->not->toHaveKey('target_task_id')
        ->and($dto->structured)->not->toHaveKey('target_task_id')
        ->and($dto->structured)->not->toHaveKey('target_task_title');
});

test('build for ScheduleTasksAndEvents with Multiple and two tasks remains readonly', function (): void {
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

    expect($dto->appliableChanges)->toBeEmpty()
        ->and($dto->structured)->not->toHaveKey('target_task_title')
        ->and($dto->structured)->not->toHaveKey('target_task_id');
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
        ->and($dto->followupSuggestions)->toBeEmpty()
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

test('build enforces narrative consistency for prioritize_tasks when relative due wording contradicts context', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'First, do Lab 5. It is due tomorrow at 23:59.',
            'reasoning' => 'You have a high-priority lab that is due today, so finish it before the end of today.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 150,
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->recommendedAction)->toContain('Lab 5')
        ->and($dto->message)->toContain('due Fri, Mar 13, 2026')
        ->and(mb_strtolower($dto->message))->not->toContain('due today')
        ->and(mb_strtolower($dto->message))->not->toContain('tomorrow')
        ->and($dto->message)->toContain('due Fri, Mar 13, 2026 11:59 PM');
});

test('build enforces narrative consistency for prioritize_tasks when narrative mentions a concrete duration', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Finish the lab first.',
            'reasoning' => 'After finishing Lab 5, which will take about 2 hours, do the problem set.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('~210 min')
        ->and($dto->message)->not->toContain('2 hours');
});

test('build uses ranked due datetimes for rewritten prioritize narrative when context facts disagree', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Do the lab first because it is due tomorrow.',
            'reasoning' => 'Finish it today and then do the problem set.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                // Ranked says Mar 14 (Saturday)
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-14T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                // Facts incorrectly say Mar 13 (Friday) for the problem set
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 150,
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('Sat, Mar 14, 2026 11:00 PM')
        ->and($dto->message)->not->toContain('Fri, Mar 13, 2026 11:00 PM');
});

test('build corrects relative wording for prioritize_events without replacing the whole narrative', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'Start with the review session today.',
            'reasoning' => 'It is scheduled for tomorrow so you should not miss it.',
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Math exam review session', 'start_datetime' => '2026-03-14T16:00:00+08:00', 'end_datetime' => '2026-03-14T18:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'event_facts_by_title' => [
                'Math exam review session' => [
                    'start_datetime' => '2026-03-14T16:00:00+08:00',
                    'end_datetime' => '2026-03-14T18:00:00+08:00',
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeEvents, LlmEntityType::Event);

    expect(mb_strtolower($dto->message))->not->toContain('today')
        ->and(mb_strtolower($dto->message))->not->toContain('tomorrow')
        ->and($dto->message)->toContain('Sat, Mar 14, 2026');
});

test('build strips malformed 24h+AM/PM time after due date correction and fixes priority summary', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'It is due tomorrow at 23:59 PM.',
            'reasoning' => 'The other two are both medium priority tasks.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
                ['rank' => 3, 'title' => 'MATH 201 – Quiz 3: Graph Theory', 'end_datetime' => '2026-03-14T10:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 150,
                    'priority' => 'medium',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Quiz 3: Graph Theory' => [
                    'end_datetime' => '2026-03-14T10:00:00+08:00',
                    'duration' => 30,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->not->toContain('23:59 PM')
        ->and($dto->message)->toContain('due Fri, Mar 13, 2026 11:59 PM')
        ->and(mb_strtolower($dto->message))->toContain('medium-priority and high-priority');
});

test('build anchors duration correction to the task mentioned in narrative, not always rank #1', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with CS 220 – Lab 5: Linked Lists. It will take about 2 hours.',
            'reasoning' => 'Then do the quiz.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'MATH 201 – Quiz 3: Graph Theory', 'end_datetime' => '2026-03-14T10:00:00+08:00'],
                ['rank' => 2, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'MATH 201 – Quiz 3: Graph Theory' => [
                    'end_datetime' => '2026-03-14T10:00:00+08:00',
                    'duration' => 30,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('MATH 201 – Quiz 3: Graph Theory')
        ->and($dto->message)->toContain('~210 min')
        ->and($dto->message)->not->toContain('Start with CS 220 – Lab 5: Linked Lists. It will take about 2 hours.');
});

test('build replaces wrong explicit due date stated for the mentioned task title', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Do CS 220 – Lab 5: Linked Lists first. It is due Sat, Mar 14, 2026.',
            'reasoning' => 'CS 220 – Lab 5: Linked Lists is due Sat, Mar 14, 2026 so start now.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Quiz 3: Graph Theory', 'end_datetime' => '2026-03-14T10:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Quiz 3: Graph Theory' => [
                    'end_datetime' => '2026-03-14T10:00:00+08:00',
                    'duration' => 30,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('due Fri, Mar 13, 2026 11:59 PM')
        ->and($dto->message)->not->toContain('due Sat, Mar 14, 2026.');
});

test('build preserves recommended_action even when it recommends a different ranked task', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'First, focus on completing the CS 220 – Lab 5: Linked Lists.',
            'reasoning' => 'After that, take the quiz.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'MATH 201 – Quiz 3: Graph Theory', 'end_datetime' => '2026-03-14T10:00:00+08:00'],
                ['rank' => 2, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'MATH 201 – Quiz 3: Graph Theory' => [
                    'end_datetime' => '2026-03-14T10:00:00+08:00',
                    'duration' => 30,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('MATH 201 – Quiz 3: Graph Theory')
        ->and($dto->message)->toContain('First, focus on completing MATH 201 – Quiz 3: Graph Theory.');
});

test('build replaces weekday-only due phrasing like "11:59 PM Friday" with canonical due datetime', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Do CS 220 – Lab 5: Linked Lists before its deadline at 11:59 PM Friday.',
            'reasoning' => 'It’s due first.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('Fri, Mar 13, 2026 11:59 PM')
        ->and($dto->message)->not->toContain('11:59 PM Friday');
});

test('build replaces next friday with actual #2 due date and removes stray punctuation', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'The lab is due tomorrow at 23:59 PM.',
            'reasoning' => 'The problem set is not due until next Friday.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 150,
                    'priority' => 'medium',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('Fri, Mar 13, 2026 11:00 PM')
        ->and($dto->message)->not->toContain('next Friday')
        ->and($dto->message)->not->toContain('  .');
});

test('build replaces \"has no deadline yet\" with actual #2 due date for prioritize_tasks', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with the lab.',
            'reasoning' => 'The problem set has no deadline yet, so it can wait.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
                'MATH 201 – Problem Set 4: Relations' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 150,
                    'priority' => 'medium',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect(mb_strtolower($dto->message))->not->toContain('no deadline yet')
        ->and($dto->message)->toContain('Fri, Mar 13, 2026 11:00 PM');
});

test('build applies narrative corrections for multi-entity prioritize_all', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'all',
            'recommended_action' => 'Do tasks today and then the rest tomorrow.',
            'reasoning' => 'Your top task is overdue.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Event A', 'start_datetime' => '2026-03-14T16:00:00+08:00'],
            ],
            'ranked_projects' => [
                ['rank' => 1, 'name' => 'Project A', 'end_datetime' => '2026-03-20T17:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'Task A' => [
                    'end_datetime' => '2026-03-13T23:00:00+08:00',
                    'duration' => 60,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
            'event_facts_by_title' => [
                'Event A' => [
                    'start_datetime' => '2026-03-14T16:00:00+08:00',
                    'end_datetime' => '2026-03-14T18:00:00+08:00',
                ],
            ],
            'project_facts_by_name' => [
                'Project A' => [
                    'end_datetime' => '2026-03-20T17:00:00+08:00',
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeAll, LlmEntityType::Multiple);

    expect(mb_strtolower($dto->message))->not->toContain('today')
        ->and(mb_strtolower($dto->message))->not->toContain('tomorrow')
        ->and(mb_strtolower($dto->message))->not->toContain('overdue');
});

test('stored snapshot structured should match canonical structured (not raw) for prioritize_tasks', function (): void {
    $raw = [
        'entity_type' => 'task',
        'recommended_action' => 'Raw',
        'reasoning' => 'Raw',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Task A'],
        ],
    ];
    $sanitized = [
        'entity_type' => 'task',
        'recommended_action' => 'Sanitized',
        'reasoning' => 'Sanitized',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Task A'],
            ['rank' => 2, 'title' => 'Task B'],
        ],
    ];

    $result = new \App\DataTransferObjects\Llm\LlmInferenceResult(
        structured: $sanitized,
        promptVersion: '1.0',
        promptTokens: 10,
        completionTokens: 10,
        usedFallback: false,
        fallbackReason: null,
        rawStructured: $raw,
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);
    $snapshot = $dto->toArray();

    // Canonical: snapshot structured should be what UI renders (DTO structured), not raw.
    expect($snapshot['structured']['ranked_tasks'])->toHaveCount(2);
});

test('build persists corrected narrative into structured for prioritize_tasks', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'It is due tomorrow at 23:59 PM.',
            'reasoning' => 'It is overdue and due today.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'CS 220 – Lab 5: Linked Lists', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
                ['rank' => 2, 'title' => 'MATH 201 – Problem Set 4: Relations', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false,
        contextFacts: [
            'timezone' => 'Asia/Manila',
            'current_time' => '2026-03-11T12:00:00+08:00',
            'current_date' => '2026-03-11',
            'task_facts_by_title' => [
                'CS 220 – Lab 5: Linked Lists' => [
                    'end_datetime' => '2026-03-13T23:59:00+08:00',
                    'duration' => 210,
                    'priority' => 'high',
                    'due_today' => false,
                    'is_overdue' => false,
                ],
            ],
        ],
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->structured)->toHaveKey('recommended_action')
        ->and($dto->structured)->toHaveKey('reasoning')
        ->and($dto->structured['recommended_action'])->not->toContain('tomorrow')
        ->and($dto->structured['recommended_action'])->not->toContain('23:59 PM')
        ->and(mb_strtolower($dto->structured['reasoning']))->not->toContain('overdue')
        ->and(mb_strtolower($dto->structured['reasoning']))->not->toContain('due today');
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
        ->and($arr)->not->toHaveKey('followup_suggestions')
        ->and($arr)->not->toHaveKey('recommended_action')
        ->and($arr)->not->toHaveKey('reasoning');

    $restored = RecommendationDisplayDto::fromArray($arr);
    expect($restored->message)->toBe($dto->message)
        ->and($restored->followupSuggestions)->toBeEmpty();
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
