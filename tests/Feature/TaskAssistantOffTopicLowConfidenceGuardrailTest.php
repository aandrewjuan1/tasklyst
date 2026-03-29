<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('off-topic low-confidence still forces out_of_scope guardrail and blocks recommendations', function (): void {
    Prism::fake([
        // Intent inference: off-topic with low confidence (matches the log case).
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'off_topic',
                'confidence' => 0.15,
                'rationale' => 'Product recommendation request',
            ])
            ->withUsage(new Usage(1, 2)),
        // General guidance generation: model "leaks" a recommendation + mislabels as unclear.
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'unclear',
                'acknowledgement' => "I understand you're looking for the perfect keyboard.",
                'message' => 'As of now, the Cooledown mechanical keyboard stands out as one of the top choices.',
                'suggested_next_actions' => [
                    'Rephrase what you mean in one short sentence.',
                    'Prioritize my tasks.',
                    'Schedule time blocks for my tasks.',
                ],
                'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar for what matters most.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'whats the best keyboard right now',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('general_guidance');
    expect(data_get($assistantMessage->metadata, 'general_guidance.intent'))->toBe('out_of_scope');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain("can't help");
    expect(mb_strtolower((string) $assistantMessage->content))->not->toContain('cooledown');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('tackle');
    expect(mb_strtolower((string) $assistantMessage->content))->toContain('calendar');
});
