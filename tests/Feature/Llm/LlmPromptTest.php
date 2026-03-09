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

test('schedule task prompt includes reasoning and coach instruction', function (): void {
    $result = $this->action->execute(LlmIntent::ScheduleTask);

    expect($result->systemPrompt)->toContain('concrete reason')
        ->and($result->systemPrompt)->toContain('encouraging');
});

test('schedule event prompt requires id and title so apply targets correct event', function (): void {
    $result = $this->action->execute(LlmIntent::ScheduleEvent);

    expect($result->systemPrompt)->toContain('"id"')
        ->and($result->systemPrompt)->toContain('"title"')
        ->and($result->systemPrompt)->toContain('Never reply with only "your event"');
});

test('schedule task prompt requires JSON time fields when suggesting a time', function (): void {
    $result = $this->action->execute(LlmIntent::ScheduleTask);

    expect($result->systemPrompt)->toContain('Proposed schedule')
        ->and($result->systemPrompt)->toContain('start_datetime')
        ->and($result->systemPrompt)->toContain('proposed_properties');
});

test('returns correct prompt per intent', function (LlmIntent $intent): void {
    $result = $this->action->execute($intent);

    expect($result->systemPrompt)->toBeString()
        ->and($result->version)->toBe('v1.7');
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

    expect($template->version())->toBe('v1.7');
});

test('prioritize tasks prompt enforces exact requested_top_n when possible', function (): void {
    $result = $this->action->execute(LlmIntent::PrioritizeTasks);

    expect($result->systemPrompt)
        ->toContain('requested_top_n')
        ->and($result->systemPrompt)
        ->toContain('you MUST return exactly requested_top_n items in ranked_tasks');
});
