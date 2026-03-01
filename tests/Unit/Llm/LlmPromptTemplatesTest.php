<?php

use App\Enums\LlmIntent;
use App\Services\LlmPromptService;

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
