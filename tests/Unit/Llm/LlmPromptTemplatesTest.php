<?php

use App\Enums\LlmIntent;
use App\Services\LlmPromptService;

it('includes date filter instructions for no set dates and no due date in general query prompt', function (): void {
    /** @var LlmPromptService $service */
    $service = app(LlmPromptService::class);

    $result = $service->getSystemPromptForIntent(LlmIntent::GeneralQuery);
    $prompt = $result->systemPrompt;

    expect($prompt)
        ->toContain('no set dates')
        ->and($prompt)->toContain('start_datetime and end_datetime are null or missing')
        ->and($prompt)->toContain('no due date')
        ->and($prompt)->toContain('end_datetime is null or missing')
        ->and($prompt)->toContain('exclude any task that has end_datetime set');
});

it('includes guardrails and student persona in prompts', function (): void {
    /** @var LlmPromptService $service */
    $service = app(LlmPromptService::class);

    $intents = [
        LlmIntent::GeneralQuery,
        LlmIntent::ScheduleTask,
        LlmIntent::PrioritizeTasks,
    ];

    foreach ($intents as $intent) {
        $result = $service->getSystemPromptForIntent($intent);
        $prompt = $result->systemPrompt;

        expect($prompt)
            ->toContain('You are TaskLyst Assistant, a student productivity coach')
            ->and($prompt)->toContain('Respond with only a single JSON object')
            ->and($prompt)->toContain('Start with {')
            ->and($prompt)->toContain('Use only that and the user message')
            ->and($prompt)->toContain('confidence below 0.5');
    }
});
