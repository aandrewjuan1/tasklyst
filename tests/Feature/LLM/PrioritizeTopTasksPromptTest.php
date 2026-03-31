<?php

use App\Enums\MessageRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;

test('real llm prioritize flow (top tasks) returns coherent narrative - soft schema checks', function (): void {
    $runLlmTests = filter_var(getenv('RUN_LLM_TESTS') ?: false, FILTER_VALIDATE_BOOLEAN);
    if (! $runLlmTests) {
        $this->markTestSkipped('Set RUN_LLM_TESTS=1 to run the real LLM integration test.');
    }

    // Safety: ensure we actually go through prioritize flow (and allow narrative generation to use LLM).
    config(['task-assistant.intent.use_llm' => true]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    // Seed a small task set so the prioritization slice is non-empty.
    Task::factory()->for($user)->create([
        'title' => 'Review lecture notes for tomorrow',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'start_datetime' => null,
        'end_datetime' => now()->addDay(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Finish math homework set',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(2),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Plan study session outline',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Low,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(5),
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'hi what are my top tasks',
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    dispatch(new BroadcastTaskAssistantStreamJob(
        threadId: $thread->id,
        userMessageId: $userMessage->id,
        assistantMessageId: $assistantMessage->id,
        userId: $user->id,
    ));

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('prioritize');

    $prioritize = is_array($assistantMessage->metadata['prioritize'] ?? null)
        ? $assistantMessage->metadata['prioritize']
        : [];

    expect($prioritize)->not->toBe([]);

    $items = is_array($prioritize['items'] ?? null) ? $prioritize['items'] : [];
    expect($items)->not->toBeEmpty();
    expect($items)->toBeArray();

    foreach ($items as $idx => $item) {
        expect($item)->toBeArray();

        // Required fields for student-visible list rendering + narrative grounding.
        expect(array_key_exists('entity_type', $item))->toBeTrue();
        expect(in_array($item['entity_type'], ['task', 'event', 'project'], true))->toBeTrue();

        expect(array_key_exists('entity_id', $item))->toBeTrue();
        expect($item['entity_id'])->toBeGreaterThan(0);

        expect(array_key_exists('title', $item))->toBeTrue();
        expect((string) ($item['title'] ?? ''))->not->toBeEmpty();

        expect(array_key_exists('due_phrase', $item))->toBeTrue();
        expect((string) ($item['due_phrase'] ?? ''))->not->toBeEmpty();

        // `due_on` may be '—' depending on due_phrase; still required for consistent formatting.
        expect(array_key_exists('due_on', $item))->toBeTrue();
        expect(is_string($item['due_on'] ?? null))->toBeTrue();

        expect(array_key_exists('complexity_label', $item))->toBeTrue();
        expect(is_string($item['complexity_label'] ?? null))->toBeTrue();

        // Soft limit: keep item count reasonable to avoid very long outputs.
        expect($idx)->toBeLessThan(10);
    }

    expect(isset($prioritize['next_options']))->toBeTrue();
    $nextOptions = (string) $prioritize['next_options'];
    expect(trim($nextOptions))->not->toBeEmpty();
    expect($nextOptions)->not->toContain('[');
    expect($nextOptions)->not->toContain(']');

    $chips = is_array($prioritize['next_options_chip_texts'] ?? null)
        ? $prioritize['next_options_chip_texts']
        : [];
    expect($chips)->toBeArray();
    expect(count($chips))->toBeGreaterThanOrEqual(1);
    expect(count($chips))->toBeLessThanOrEqual(3);
    foreach ($chips as $chip) {
        expect(is_string($chip))->toBeTrue();
        expect(trim($chip))->not->toBeEmpty();
        expect($chip)->not->toContain('?');
    }

    $reasoning = (string) ($prioritize['reasoning'] ?? '');
    expect(trim($reasoning))->not->toBeEmpty();

    // Coherence / grounding: first ranked title should appear in narrative output.
    $firstItem = $items[0] ?? [];
    $firstTitle = trim((string) ($firstItem['title'] ?? ''));
    expect($firstTitle)->not->toBeEmpty();

    $assistantText = (string) $assistantMessage->content;
    $assistantTextLower = mb_strtolower($assistantText);
    $reasoningLower = mb_strtolower($reasoning);
    $firstTitleLower = mb_strtolower($firstTitle);
    $titleMentioned = mb_strpos($reasoningLower, $firstTitleLower) !== false
        || mb_strpos($assistantTextLower, $firstTitleLower) !== false;
    expect($titleMentioned)->toBeTrue();

    // Additional soft checks: avoid internal terminology in student-facing output.
    $internalTerms = ['snapshot', 'json', 'backend', 'database'];
    foreach ($internalTerms as $term) {
        expect(mb_stripos($assistantTextLower, $term) === false)->toBeTrue();
    }

    $framing = $prioritize['framing'] ?? null;
    if ($framing !== null) {
        expect(is_string($framing))->toBeTrue();
        // framing can be optional; just ensure it isn't pathological.
        expect(mb_strlen($framing))->toBeLessThan(500);
    }

    // Print the narrative for manual inspection in the terminal.
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "=== Real LLM Prioritize Integration Test ===\n");
    fwrite(STDOUT, "Prompt: hi what are my top tasks\n\n");
    fwrite(STDOUT, "Assistant content:\n");
    fwrite(STDOUT, trim($assistantText)."\n\n");
    fwrite(STDOUT, "Structured fields:\n");
    fwrite(STDOUT, 'framing: '.($framing === null ? 'null' : trim((string) $framing))."\n");
    fwrite(STDOUT, 'reasoning: '.trim($reasoning)."\n");
    fwrite(STDOUT, 'next_options: '.$nextOptions."\n");
    fwrite(STDOUT, 'next_options_chip_texts: '.json_encode($chips, JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, 'Top item titles (for grounding check): '.json_encode(array_map(
        static fn (array $i): string => (string) ($i['title'] ?? ''),
        array_slice($items, 0, 5),
    ), JSON_UNESCAPED_SLASHES)."\n");

    // Keep at least one deterministic sanity check: content should have some list-like structure.
    // We don't assert exact text, just that it likely includes a numbered list.
    expect((bool) preg_match('/\b1\.\s+/u', $assistantText))->toBeTrue();
})->group('llm');
