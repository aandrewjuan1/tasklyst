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
            ->toContain('You are TaskLyst Assistant, a warm, encouraging productivity coach embedded in a student task management system.')
            ->and($prompt)->toContain('Respond with only valid JSON that matches the provided schema')
            ->and($prompt)->toContain('Use only the context provided')
            ->and($prompt)->toContain('If you cannot make a confident recommendation');
    }
});
