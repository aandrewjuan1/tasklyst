<?php

use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantGeneralGuidanceService;

test('crud manage forced mode returns boundary with workspace redirect and role reminder', function (): void {
    $user = User::factory()->create();

    $payload = app(TaskAssistantGeneralGuidanceService::class)->generateGeneralGuidance(
        user: $user,
        userMessage: 'delete that task',
        forcedMode: 'crud_manage_out_of_scope',
    );

    expect((string) ($payload['intent'] ?? ''))->toBe('out_of_scope');

    $message = mb_strtolower((string) ($payload['message'] ?? ''));
    expect($message)->toContain('workspace');
    expect($message)->toContain('prioritize');
    expect($message)->toContain('schedule');
    expect($message)->toContain("can't");
});

test('crud manage forced mode remains deterministic for same user and prompt', function (): void {
    $user = User::factory()->create();
    $service = app(TaskAssistantGeneralGuidanceService::class);

    $first = $service->generateGeneralGuidance(
        user: $user,
        userMessage: 'update the task',
        forcedMode: 'crud_manage_out_of_scope',
    );
    $second = $service->generateGeneralGuidance(
        user: $user,
        userMessage: 'update the task',
        forcedMode: 'crud_manage_out_of_scope',
    );

    expect((string) ($first['message'] ?? ''))->toBe((string) ($second['message'] ?? ''));
    expect((string) ($first['next_options'] ?? ''))->toBe((string) ($second['next_options'] ?? ''));
});
