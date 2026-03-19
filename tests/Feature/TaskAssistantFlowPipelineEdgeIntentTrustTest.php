<?php

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Intent\IntentClassificationService;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceRunner;

use function Pest\Laravel\mock;

test('processQueuedMessage trusts edge intent and does not re-run intent classification', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Read chapter 1',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => now()->addHours(3),
    ]);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Help me choose what to work on next.',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    $intentClassifierMock = mock(IntentClassificationService::class);
    $intentClassifierMock->shouldNotReceive('classify');
    $intentClassifierMock
        ->shouldReceive('getFlowForIntent')
        ->with(TaskAssistantIntent::TaskPrioritization)
        ->andReturn('task_choice');

    $runnerMock = mock(TaskAssistantTaskChoiceRunner::class);
    $runnerMock
        ->shouldReceive('run')
        ->andReturn([
            'valid' => true,
            'data' => [
                'chosen_type' => null,
                'chosen_id' => null,
                'chosen_title' => null,
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'suggestion' => 'Focus on your next reading task.',
                'reason' => 'It is the most urgent and actionable item right now.',
                'steps' => [
                    'Skim what you will read next',
                    'Read actively for 20 minutes',
                    'Summarize the key points in your own words',
                ],
            ],
            'errors' => [],
        ]);

    try {
        $service = app(TaskAssistantService::class);

        $service->processQueuedMessage(
            thread: $thread,
            userMessageId: $userMessage->id,
            assistantMessageId: $assistantMessage->id,
            intent: TaskAssistantIntent::TaskPrioritization
        );

        $assistantMessage->refresh();
        expect($assistantMessage->content)->not->toBe('');
        expect($assistantMessage->metadata['task_choice'] ?? null)->not->toBeNull();
    } finally {
        // Ensure this container binding doesn't leak into other tests.
        app()->forgetInstance(IntentClassificationService::class);
        app()->forgetInstance(TaskAssistantTaskChoiceRunner::class);
    }
});
