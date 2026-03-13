<?php

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\ConversationTurn;
use App\DataTransferObjects\Llm\EventContextItem;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Services\Llm\PromptManagerService;

function makePromptManager(): PromptManagerService
{
    config()->set('llm.prompt.default_style', 'supportive');
    config()->set('llm.prompt.message.min_sentences', 3);
    config()->set('llm.prompt.message.max_sentences', 8);
    config()->set('llm.prompt.reasoning_word_limit', 50);
    config()->set('llm.prompt.reasoning_word_limit_for_prioritize', 80);
    config()->set('llm.prompt.prioritize_default_limit', 5);
    config()->set('llm.prompt.include_next_steps', true);
    config()->set('llm.prompt.require_clarification_for_ambiguous_time', true);
    config()->set('llm.prompt.use_titles_in_message', true);
    config()->set('llm.prompt.show_rank_numbers', true);
    config()->set('llm.prompt.show_ids_in_message', false);

    return new PromptManagerService(
        schemaVersion: '2026-03-01.v1',
        timezone: 'Asia/Manila',
        allowedTools: ['create_task', 'update_task', 'create_event'],
    );
}

function makePromptContext(): ContextDto
{
    return new ContextDto(
        now: new DateTimeImmutable('2026-03-13T10:00:00+08:00'),
        tasks: [
            new TaskContextItem(31, 'Physics assignment', '2026-03-14', 'high', 60),
        ],
        events: [
            new EventContextItem(2, 'Chemistry class', '2026-03-13T14:00:00+08:00', 90),
        ],
        recentMessages: [
            new ConversationTurn('user', 'in my tasks what should i do first?', null, new DateTimeImmutable('-5 minutes')),
            new ConversationTurn('assistant', 'Start with task_31.', null, new DateTimeImmutable('-4 minutes')),
            new ConversationTurn('user', 'schedule that task for later evening', null, new DateTimeImmutable('-1 minutes')),
        ],
        userPreferences: ['tone' => 'friendly'],
        fingerprint: 'task-fp-123',
        isSummaryMode: false,
    );
}

test('prompt manager builds request payload with adaptive guidance keys', function (): void {
    $request = makePromptManager()->buildRequest('give me my top 5 most urgent tasks', makePromptContext());
    $payload = json_decode($request->userPayloadJson, true);

    expect($payload)->toBeArray()
        ->and($payload['intent_hint'] ?? null)->toBe('prioritize')
        ->and($payload['response_preferences']['style'] ?? null)->toBe('supportive')
        ->and($payload['response_preferences']['prioritize_default_limit'] ?? null)->toBe(5)
        ->and($payload['response_preferences']['message_min_sentences'] ?? null)->toBe(3)
        ->and($payload['response_preferences']['message_max_sentences'] ?? null)->toBe(8)
        ->and($payload['response_preferences']['reasoning_word_limit_for_prioritize'] ?? null)->toBe(80)
        ->and($payload['response_preferences']['use_titles_in_message'] ?? null)->toBeTrue()
        ->and($payload['response_preferences']['show_rank_numbers'] ?? null)->toBeTrue()
        ->and($payload['response_preferences']['show_ids_in_message'] ?? null)->toBeFalse()
        ->and($payload['context_fingerprint'] ?? null)->toBe('task-fp-123')
        ->and($payload['user_preferences']['tone'] ?? null)->toBe('friendly')
        ->and($payload['tasks'][0]['id'] ?? null)->toBe('task_31')
        ->and($payload['tasks'][0]['relevance_tag'] ?? null)->toBe('due_soon')
        ->and($payload['tasks'][0]['matches_query'] ?? null)->toBeFalse()
        ->and($payload['upcoming_events'][0]['time_bucket'] ?? null)->toBe('today');
});

test('prompt manager highlights tasks and events that match query keywords', function (): void {
    $request = makePromptManager()->buildRequest('schedule my physics assignment after chemistry class', makePromptContext());
    $payload = json_decode($request->userPayloadJson, true);

    expect($payload['intent_hint'] ?? null)->toBe('schedule');
    expect($payload['tasks'][0]['id'] ?? null)->toBe('task_31');
    expect($payload['tasks'][0]['matches_query'] ?? null)->toBeTrue();
    expect($payload['upcoming_events'][0]['time_bucket'] ?? null)->toBe('today');
});

test('prompt manager system prompt contains balanced adaptive rules', function (): void {
    $request = makePromptManager()->buildRequest('hello', makePromptContext());
    $systemPrompt = $request->systemPrompt;

    expect($systemPrompt)->toContain('warm, practical Task Assistant')
        ->toContain('message" should be 3-8 sentences')
        ->toContain('Default style is "supportive"')
        ->toContain('When the user says they feel overwhelmed, stressed, stuck, or anxious')
        ->toContain('In user-facing "message", always refer to tasks and events by their titles')
        ->toContain('For intent:"prioritize" and intent:"list":')
        ->toContain('The user payload may include an "intent_hint" field')
        ->toContain('Output CONTRACT (must follow exactly)')
        ->toContain('IMPORTANT TOOL-CALLING RULES');
});

test('prompt manager builds contextual follow-up request after tool execution', function (): void {
    $toolCall = new ToolCallDto(
        tool: 'create_event',
        args: [
            'title' => 'Physics task block',
            'start_datetime' => '2026-03-13T19:00:00+08:00',
            'end_datetime' => '2026-03-13T20:00:00+08:00',
        ],
        clientRequestId: 'req-123',
    );
    $toolResult = new ToolResultDto(
        tool: 'create_event',
        success: true,
        payload: ['event_id' => 99],
    );

    $request = makePromptManager()->buildToolFollowUpRequest($toolCall, $toolResult, makePromptContext());
    $payload = json_decode($request->userPayloadJson, true);

    expect($payload)->toBeArray()
        ->and($payload['tone'] ?? null)->toBe('balanced')
        ->and($payload['last_user_message'] ?? null)->toBe('schedule that task for later evening')
        ->and($payload['response_preferences']['include_next_step'] ?? null)->toBeTrue()
        ->and($request->maxTokens)->toBe(256);
});
