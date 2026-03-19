<?php

use App\Enums\TaskAssistantIntent;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;

test('resolveToolsForIntent disables tools for advisory flows', function (): void {
    $user = User::factory()->create();
    $service = app(TaskAssistantService::class);

    $resolveTools = new ReflectionMethod(TaskAssistantService::class, 'resolveTools');
    $resolveTools->setAccessible(true);
    $allTools = $resolveTools->invoke($service, $user);

    $resolveForIntent = new ReflectionMethod(TaskAssistantService::class, 'resolveToolsForIntent');
    $resolveForIntent->setAccessible(true);

    $coachingTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::ProductivityCoaching);
    $prioritizationTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::TaskPrioritization);
    $mutatingTools = $resolveForIntent->invoke($service, $user, TaskAssistantIntent::TaskManagement);

    expect($coachingTools)->toBeArray();
    expect($prioritizationTools)->toBeArray();
    expect($mutatingTools)->toBeArray();
    expect($mutatingTools)->not->toBeEmpty();
    expect(count($coachingTools))->toBeLessThan(count($mutatingTools));
    expect(count($prioritizationTools))->toBeLessThan(count($mutatingTools));
    expect(count($mutatingTools))->toBe(count($allTools));
});
