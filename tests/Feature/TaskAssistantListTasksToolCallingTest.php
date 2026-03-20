<?php

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('task_list executes and persists list_tasks tool when Prism toolResults are missing', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    Task::factory()->for($user)->create([
        'title' => 'Urgent task A',
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'start_datetime' => null,
        'end_datetime' => now()->format('Y-m-d 23:59:00'),
        'duration' => 60,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Medium task B',
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => now()->format('Y-m-d 23:59:00'),
        'duration' => 30,
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Show me my tasks.',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    // Simulate a Prism structured call that returns no tool results.
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantService::class);
    $service->processQueuedMessage(
        $thread,
        $userMessage->id,
        $assistantMessage->id,
        TaskAssistantIntent::TaskPrioritization
    );

    $assistantMessage->refresh();

    expect($assistantMessage->content)->toContain('Urgent task A');

    $toolMessage = TaskAssistantMessage::query()
        ->where('thread_id', $thread->id)
        ->where('role', MessageRole::Tool)
        ->where('metadata', 'LIKE', '%"tool_name":"list_tasks"%')
        ->first();

    expect($toolMessage)->not->toBeNull();
});
