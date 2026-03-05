<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\ContextBuilder;

it('treats top 1 phrasing as referring to previous list', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();
    $thread = AssistantThread::factory()->create([
        'user_id' => $user->id,
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'You have 4 tasks matching that request.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'listed_items' => [
                        ['title' => 'Output # 1: My Light to the Society  - Due'],
                        ['title' => 'Output # 2: EMILIAN - ἀρετή - Due'],
                    ],
                ],
            ],
        ],
    ]);

    expect($assistantMessage->exists())->toBeTrue();

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTask,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: $thread,
        userMessage: 'schedule the top 1 for later'
    );

    expect($context)->toHaveKey('tasks');
});
