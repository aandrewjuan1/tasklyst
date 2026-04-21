<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('varies repeated schedule clarification copy after the first repeat', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [],
    ]);

    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod(TaskAssistantService::class, 'applyScheduleClarificationVariation');
    $method->setAccessible(true);

    $base = 'Please tell me which item to edit and the exact change.';
    $first = $method->invoke($service, $thread, $base);
    $second = $method->invoke($service, $thread, $base);

    expect($first)->toBe($base);
    expect($second)->toContain('move #2 to 8 pm tomorrow');
});
