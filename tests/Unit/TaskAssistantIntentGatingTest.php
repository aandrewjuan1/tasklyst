<?php

use App\Enums\TaskAssistantIntent;
use App\Models\User;
use App\Services\TaskAssistantService;

test('resolveToolsForIntent disables tools for advisory flows', function (): void {
    $user = User::factory()->create();
    $service = app(TaskAssistantService::class);

    $resolveTools = new ReflectionMethod(TaskAssistantService::class, 'resolveTools');
    $resolveTools->setAccessible(true);
    $allTools = $resolveTools->invoke($service, $user);

    $resolveForIntent = new ReflectionMethod(TaskAssistantService::class, 'resolveToolsForIntent');
    $resolveForIntent->setAccessible(true);

    $advisoryTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::GeneralAdvice);
    $planningTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::PlanNextTask);
    $mutatingTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::MutatingAction);

    expect($advisoryTools)->toBeArray()->toBeEmpty();
    expect($planningTools)->toBeArray()->toBeEmpty();
    expect($mutatingTools)->toBeArray();
    expect($mutatingTools)->not->toBeEmpty();
    expect(count($mutatingTools))->toBe(count($allTools));
});
