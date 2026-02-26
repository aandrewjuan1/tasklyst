<?php

use App\Actions\Llm\ClassifyLlmIntentAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;

beforeEach(function (): void {
    $this->action = app(ClassifyLlmIntentAction::class);
});

test('classifies schedule task intent', function (): void {
    $result = $this->action->execute('Schedule my dashboard task by Friday');

    expect($result->intent)->toBe(LlmIntent::ScheduleTask)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->confidence)->toBeGreaterThan(0.5);
});

test('classifies schedule event intent', function (): void {
    $result = $this->action->execute('Schedule a team meeting for next Tuesday');

    expect($result->intent)->toBe(LlmIntent::ScheduleEvent)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

test('classifies schedule project intent', function (): void {
    $result = $this->action->execute('Schedule the website redesign project');

    expect($result->intent)->toBe(LlmIntent::ScheduleProject)
        ->and($result->entityType)->toBe(LlmEntityType::Project);
});

test('classifies prioritize tasks intent', function (): void {
    $result = $this->action->execute('What tasks should I focus on today?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

test('classifies prioritize events intent', function (): void {
    $result = $this->action->execute('Which events are most important this week?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

test('classifies prioritize projects intent', function (): void {
    $result = $this->action->execute('What projects should I prioritize?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeProjects)
        ->and($result->entityType)->toBe(LlmEntityType::Project);
});

test('classifies resolve dependency intent', function (): void {
    $result = $this->action->execute('I\'m blocked on the API integration task');

    expect($result->intent)->toBe(LlmIntent::ResolveDependency)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

test('classifies adjust task deadline intent', function (): void {
    $result = $this->action->execute('Can we push the dashboard task deadline to next week?');

    expect($result->intent)->toBe(LlmIntent::AdjustTaskDeadline)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

test('classifies adjust event time intent', function (): void {
    $result = $this->action->execute('Can we move the team meeting to Thursday?');

    expect($result->intent)->toBe(LlmIntent::AdjustEventTime)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

test('classifies adjust project timeline intent', function (): void {
    $result = $this->action->execute('Can we extend the website project timeline?');

    expect($result->intent)->toBe(LlmIntent::AdjustProjectTimeline)
        ->and($result->entityType)->toBe(LlmEntityType::Project);
});

test('classifies general query when no intent keywords match', function (): void {
    $result = $this->action->execute('What is the weather tomorrow?');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->confidence)->toBe(0.5);
});

test('result toArray returns intent entity_type and confidence', function (): void {
    $result = $this->action->execute('Schedule my task by Friday');

    $arr = $result->toArray();

    expect($arr)->toHaveKeys(['intent', 'entity_type', 'confidence'])
        ->and($arr['intent'])->toBe('schedule_task')
        ->and($arr['entity_type'])->toBe('task')
        ->and($arr['confidence'])->toBeNumeric();
});

test('prioritize_events and prioritize_projects are readonly', function (): void {
    $eventsResult = $this->action->execute('Which events are most important?');
    $projectsResult = $this->action->execute('What projects should I prioritize?');

    expect(LlmIntent::PrioritizeEvents->isReadonly())->toBeTrue()
        ->and(LlmIntent::PrioritizeProjects->isReadonly())->toBeTrue()
        ->and(LlmIntent::PrioritizeTasks->isReadonly())->toBeFalse();
});

test('schedule and adjust intents are actionable', function (): void {
    expect(LlmIntent::ScheduleTask->isActionable())->toBeTrue()
        ->and(LlmIntent::AdjustEventTime->isActionable())->toBeTrue()
        ->and(LlmIntent::GeneralQuery->isActionable())->toBeFalse();
});

test('entity detection prefers event when meeting is mentioned', function (): void {
    $result = $this->action->execute('Reschedule the meeting to next week');

    expect($result->entityType)->toBe(LlmEntityType::Event)
        ->and($result->intent)->toBe(LlmIntent::AdjustEventTime);
});

test('entity detection prefers project when project keyword is strong', function (): void {
    $result = $this->action->execute('What project should I work on first?');

    expect($result->entityType)->toBe(LlmEntityType::Project)
        ->and($result->intent)->toBe(LlmIntent::PrioritizeProjects);
});

test('normalizes whitespace and case', function (): void {
    $result = $this->action->execute('  PRIORITY   TASKS   TODAY  ');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});
