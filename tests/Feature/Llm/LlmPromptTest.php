<?php

use App\Actions\Llm\GetSystemPromptAction;
use App\Enums\LlmIntent;
use App\Llm\PromptTemplates\ScheduleTaskPrompt;

beforeEach(function (): void {
    $this->action = app(GetSystemPromptAction::class);
});

test('returns system prompt and version for schedule_task intent', function (): void {
    $result = $this->action->execute(LlmIntent::ScheduleTask);

    expect($result->systemPrompt)->toBeString()
        ->and($result->systemPrompt)->not->toBeEmpty()
        ->and($result->version)->toBeString()
        ->and($result->version)->not->toBeEmpty();
});

test('schedule task prompt includes recurring constraint', function (): void {
    $result = $this->action->execute(LlmIntent::ScheduleTask);

    expect($result->systemPrompt)->toContain('Do not recommend times that conflict with recurring');
});

test('returns correct prompt per intent', function (LlmIntent $intent): void {
    $result = $this->action->execute($intent);

    expect($result->systemPrompt)->toBeString()
        ->and($result->version)->toBe('v1.3');
})->with([
    LlmIntent::ScheduleEvent,
    LlmIntent::PrioritizeTasks,
    LlmIntent::GeneralQuery,
    LlmIntent::ResolveDependency,
]);

test('general query intent returns fallback prompt', function (): void {
    $result = $this->action->execute(LlmIntent::GeneralQuery);

    expect($result->systemPrompt)->toContain('TaskLyst Assistant')
        ->and($result->systemPrompt)->toContain('task');
});

test('each intent returns unique prompt content', function (): void {
    $scheduleTask = $this->action->execute(LlmIntent::ScheduleTask);
    $prioritizeTasks = $this->action->execute(LlmIntent::PrioritizeTasks);

    expect($scheduleTask->systemPrompt)->not->toBe($prioritizeTasks->systemPrompt);
});

test('template exposes version for phase 9 logging', function (): void {
    $template = app(ScheduleTaskPrompt::class);

    expect($template->version())->toBe('v1.3');
});
