<?php

use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Jobs\Llm\RunLlmInferenceJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('dispatches RunLlmInferenceJob when LLM queue is enabled', function (): void {
    config([
        'tasklyst.llm.use_queue' => true,
    ]);

    Bus::fake();

    $user = User::factory()->create();

    /** @var ProcessAssistantMessageAction $action */
    $action = app(ProcessAssistantMessageAction::class);

    $assistantMessage = $action->execute($user, 'What tasks should I focus on today?', null);

    Bus::assertDispatched(RunLlmInferenceJob::class, function (RunLlmInferenceJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->userMessage === 'What tasks should I focus on today?';
    });

    expect($assistantMessage->role)->toBe('user')
        ->and($assistantMessage->content)->toBe('What tasks should I focus on today?');
});
