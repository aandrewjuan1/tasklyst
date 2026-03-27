<?php

use App\Enums\TaskAssistantUserIntent;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Intent\TaskAssistantIntentInferenceResult;
use App\Services\LLM\Intent\TaskAssistantIntentResolutionService;

test('strong prioritize signal overrides llm general guidance intent', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::GeneralGuidance,
        confidence: 0.9,
        failed: false,
        rationale: 'Model misclassification.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'prioritize my tasks',
        $inference,
        ['prioritization' => 0.8, 'scheduling' => 0.2],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('intent_general_guidance_overridden_by_signal_prioritize');
});
