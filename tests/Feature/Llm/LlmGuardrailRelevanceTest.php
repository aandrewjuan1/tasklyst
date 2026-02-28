<?php

use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Actions\Llm\RunLlmInferenceAction;
use App\Jobs\Llm\RunLlmInferenceJob;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('social closings receive a friendly reply without LLM inference', function (): void {
    Bus::fake();

    config([
        'tasklyst.guardrails.relevance_enabled' => true,
    ]);

    /** @var ProcessAssistantMessageAction $action */
    $action = app(ProcessAssistantMessageAction::class);

    $assistantMessage = $action->execute($this->user, 'okay thank you', null);

    expect($assistantMessage)->toBeInstanceOf(AssistantMessage::class)
        ->and($assistantMessage->role)->toBe('assistant')
        ->and($assistantMessage->content)->toContain('You\'re welcome')
        ->and($assistantMessage->content)->toContain('Good luck');

    $meta = $assistantMessage->metadata;

    expect($meta)->toHaveKey('recommendation_snapshot')
        ->and($meta['recommendation_snapshot']['reasoning'])->toBe('social_closing');

    Bus::assertNotDispatched(RunLlmInferenceJob::class);
});

test('off-topic queries receive a guardrail deflection without LLM inference', function (): void {
    config([
        'tasklyst.guardrails.relevance_enabled' => true,
        'tasklyst.intent.use_llm_fallback' => false,
    ]);

    $this->mock(RunLlmInferenceAction::class)->shouldNotReceive('execute');

    /** @var ProcessAssistantMessageAction $action */
    $action = app(ProcessAssistantMessageAction::class);

    $assistantMessage = $action->execute(
        $this->user,
        'Who is the current president of the Philippines?',
        null
    );

    expect($assistantMessage)->toBeInstanceOf(AssistantMessage::class)
        ->and($assistantMessage->role)->toBe('assistant')
        ->and($assistantMessage->content)->toContain('tasks, events, and projects')
        ->and($assistantMessage->content)->toContain('What should I focus on today?');

    $meta = $assistantMessage->metadata;

    expect($meta)->toHaveKey('intent')
        ->and($meta['intent'])->toBe('general_query')
        ->and($meta)->toHaveKey('entity_type')
        ->and($meta['entity_type'])->toBe('task')
        ->and($meta)->toHaveKey('recommendation_snapshot')
        ->and($meta['recommendation_snapshot'])->toHaveKey('used_guardrail')
        ->and($meta['recommendation_snapshot']['used_guardrail'])->toBeTrue()
        ->and($meta['recommendation_snapshot']['reasoning'])->toBe('off_topic_query');

    $thread = $assistantMessage->assistantThread;
    $messages = $thread->messages()->orderBy('created_at')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->role)->toBe('assistant');
});
