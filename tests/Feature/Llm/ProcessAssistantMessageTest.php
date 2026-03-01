<?php

use App\Actions\Llm\ClassifyLlmIntentAction;
use App\Actions\Llm\ProcessAssistantMessageAction;
use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Jobs\Llm\RunLlmInferenceJob;
use App\Models\AssistantThread;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\mock;

test('process assistant message appends user message and dispatches an inference job', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    mock(ClassifyLlmIntentAction::class, function ($mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->with('Prioritize my tasks', \Mockery::type(AssistantThread::class), \Mockery::type('string'))
            ->andReturn(new LlmIntentClassificationResult(
                LlmIntent::PrioritizeTasks,
                LlmEntityType::Task,
                0.95
            ));
    });

    $action = app(ProcessAssistantMessageAction::class);
    $userMessage = $action->execute($user, 'Prioritize my tasks', null);

    expect($userMessage->role)->toBe('user')
        ->and($userMessage->content)->toBe('Prioritize my tasks');

    $thread = $userMessage->assistantThread;
    $messages = $thread->messages()->orderBy('created_at')->get();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('Prioritize my tasks');

    Bus::assertDispatched(RunLlmInferenceJob::class, function (RunLlmInferenceJob $job) use ($user, $thread): bool {
        return $job->userId === $user->id
            && $job->threadId === $thread->id
            && $job->userMessage === 'Prioritize my tasks'
            && $job->intent === LlmIntent::PrioritizeTasks->value
            && $job->entityType === LlmEntityType::Task->value;
    });
});

test('process assistant message uses existing thread when thread id provided', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $thread = AssistantThread::factory()->for($user)->create();

    config([
        'tasklyst.guardrails.relevance_enabled' => false,
    ]);

    mock(ClassifyLlmIntentAction::class)->shouldReceive('execute')
        ->with(\Mockery::type('string'), \Mockery::type(AssistantThread::class), \Mockery::type('string'))
        ->andReturn(new LlmIntentClassificationResult(LlmIntent::GeneralQuery, LlmEntityType::Task, 0.8));

    $action = app(ProcessAssistantMessageAction::class);
    $userMessage = $action->execute($user, 'Help me', $thread->id);

    expect($userMessage->assistant_thread_id)->toBe($thread->id)
        ->and($thread->messages()->count())->toBe(1);

    Bus::assertDispatched(RunLlmInferenceJob::class, function (RunLlmInferenceJob $job) use ($user, $thread): bool {
        return $job->userId === $user->id && $job->threadId === $thread->id;
    });
});
