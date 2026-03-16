<?php

use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('mutating flow executes suggested list_tasks tool safely', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Example task',
    ]);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'action' => 'list_tasks',
                'args' => [
                    'limit' => 10,
                ],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    $service = app(TaskAssistantService::class);

    $result = $service->handleMutatingToolSuggestion($thread, 'Show my tasks');

    expect($result['ok'])->toBeTrue();
    expect($result['tool'])->toBe('list_tasks');
    expect($result['result']['ok'] ?? null)->toBeTrue();
    expect($result['user_message'])->not->toBe('');
});
