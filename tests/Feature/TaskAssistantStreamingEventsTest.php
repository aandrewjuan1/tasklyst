<?php

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('queued structured flows broadcast json_delta and stream_end', function (): void {
    Event::fake([
        TaskAssistantJsonDelta::class,
        TaskAssistantStreamEnd::class,
    ]);

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Read chapter 1',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'end_datetime' => now()->addDay(),
    ]);

    $contextAnalysisFake = StructuredResponseFake::make()
        ->withStructured([
            'intent_type' => 'general',
            'priority_filters' => [],
            'task_keywords' => [],
            'time_constraint' => null,
            'comparison_focus' => null,
        ])
        ->withUsage(new Usage(5, 10));

    $service = app(TaskAssistantService::class);

    $flows = [
        'task_choice' => [
            'intent' => TaskAssistantIntent::TaskPrioritization,
            'metadata_key' => 'task_choice',
            'generation_fake' => fn (): StructuredResponseFake => StructuredResponseFake::make()
                ->withStructured([
                    'suggestion' => 'Focus on reading next.',
                    'reason' => 'It will move you forward quickly.',
                    'steps' => [
                        'Skim the headings first.',
                        'Read actively for 20 minutes.',
                        'Write down 3 key points.',
                    ],
                ])
                ->withUsage(new Usage(5, 10)),
        ],
        'daily_schedule' => [
            'intent' => TaskAssistantIntent::TimeManagement,
            'metadata_key' => 'daily_schedule',
            'generation_fake' => fn (): StructuredResponseFake => StructuredResponseFake::make()
                ->withStructured([
                    'summary' => 'A refined focused schedule for your day.',
                    'assistant_note' => 'Consider starting with the most urgent block first.',
                ])
                ->withUsage(new Usage(5, 10)),
        ],
        'study_plan' => [
            'intent' => TaskAssistantIntent::StudyPlanning,
            'metadata_key' => 'study_plan',
            'generation_fake' => fn (): StructuredResponseFake => StructuredResponseFake::make()
                ->withStructured([
                    'summary' => 'A short study plan to keep momentum.',
                    'items' => [
                        [
                            'label' => 'Review the most important notes',
                            'minutes' => 30,
                            'reason' => 'Refresh key concepts quickly.',
                        ],
                        [
                            'label' => 'Practice with examples',
                            'minutes' => 25,
                            'reason' => 'Turn understanding into performance.',
                        ],
                    ],
                ])
                ->withUsage(new Usage(5, 10)),
        ],
        'review_summary' => [
            'intent' => TaskAssistantIntent::ProgressReview,
            'metadata_key' => 'review_summary',
            'generation_fake' => fn () => StructuredResponseFake::make()
                ->withStructured([
                    'completed' => [
                        [
                            'task_id' => $task->id,
                            'title' => $task->title,
                        ],
                    ],
                    'remaining' => [
                        [
                            'task_id' => $task->id,
                            'title' => $task->title,
                        ],
                    ],
                    'summary' => 'You made steady progress and have one focused item remaining.',
                    'next_steps' => [
                        'Break down the remaining work into small steps',
                        'Schedule a dedicated session',
                    ],
                ])
                ->withUsage(new Usage(5, 10)),
        ],
    ];

    foreach ($flows as $expectedFlow => $flowConfig) {
        // Prevent Prism fakes from leaking between flow iterations.
        Prism::fake([]);

        Prism::fake([
            $contextAnalysisFake,
            ($flowConfig['generation_fake'])(),
        ]);

        $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Help me with my tasks.',
        ]);

        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $service->processQueuedMessage(
            thread: $thread,
            userMessageId: $userMessage->id,
            assistantMessageId: $assistantMessage->id,
            intent: $flowConfig['intent'],
        );

        $assistantMessage->refresh();

        expect(
            $assistantMessage->metadata[$flowConfig['metadata_key']] ?? null,
            "Missing metadata key '{$flowConfig['metadata_key']}' for flow '{$expectedFlow}'."
        )->not->toBeNull();
        expect(
            $assistantMessage->metadata['processed'] ?? null,
            "Expected metadata['processed'] to be true for flow '{$expectedFlow}'."
        )->toBeTrue();
        expect(
            $assistantMessage->metadata['structured']['flow'] ?? null,
            "Missing metadata['structured']['flow'] for flow '{$expectedFlow}'."
        )->toBe($expectedFlow);
    }

    Event::assertDispatched(TaskAssistantJsonDelta::class);
    Event::assertDispatched(TaskAssistantStreamEnd::class);
});
