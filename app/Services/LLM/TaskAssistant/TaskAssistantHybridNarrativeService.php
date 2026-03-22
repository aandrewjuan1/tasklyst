<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Structured LLM calls that only narrate already-computed plans (prioritize items or schedule blocks).
 */
final class TaskAssistantHybridNarrativeService
{
    /**
     * Narrative refinement for daily schedule (deterministic proposals already fixed).
     *
     * @param  Collection<int, \Prism\Prism\ValueObjects\Messages\UserMessage|\Prism\Prism\ValueObjects\Messages\AssistantMessage>  $historyMessages
     * @param  array<string, mixed>  $promptData
     * @return array{
     *   summary: string,
     *   assistant_note: string|null,
     *   reasoning: string|null,
     *   strategy_points: list<string>,
     *   suggested_next_steps: list<string>,
     *   assumptions: list<string>
     * }
     */
    public function refineDailySchedule(
        Collection $historyMessages,
        array $promptData,
        string $userMessageContent,
        string $blocksJson,
        string $deterministicSummary,
        int $threadId,
        int $userId,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::hybridNarrativeSchema();

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));
        $messages->push(new UserMessage(
            'Here are the proposed schedule blocks (task_id/event_id values are internal and must not be mentioned). '.
            'Refine narrative fields to sound natural, supportive, and practical. Return JSON only.'."\n\n".
            'Write concise, human-sounding guidance:'."\n".
            '- summary: clear day overview'."\n".
            '- reasoning: why this order and timing make sense today'."\n".
            '- strategy_points: 2-4 practical rationale points'."\n".
            '- suggested_next_steps: 2-4 actionable execution steps'."\n".
            '- assumptions: optional, only if relevant'."\n".
            '- assistant_note: friendly one-liner with encouraging tone'."\n\n".
            'BLOCKS_JSON: '.$blocksJson
        ));

        $summary = $deterministicSummary;
        $assistantNote = null;
        $reasoning = null;
        $strategyPoints = [];
        $suggestedNextSteps = [];
        $assumptions = [];

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                'schedule'
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['summary']) && is_string($payload['summary'])) {
                $summary = $payload['summary'] !== '' ? $payload['summary'] : $deterministicSummary;
            }

            if (isset($payload['assistant_note']) && is_string($payload['assistant_note'])) {
                $assistantNote = $payload['assistant_note'] !== '' ? $payload['assistant_note'] : null;
            }
            if (isset($payload['reasoning']) && is_string($payload['reasoning'])) {
                $reasoning = $payload['reasoning'] !== '' ? $payload['reasoning'] : null;
            }
            if (is_array($payload['strategy_points'] ?? null)) {
                $strategyPoints = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['strategy_points']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['suggested_next_steps'] ?? null)) {
                $suggestedNextSteps = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['suggested_next_steps']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['assumptions'] ?? null)) {
                $assumptions = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['assumptions']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.daily-schedule.refinement_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'summary' => $summary,
            'assistant_note' => $assistantNote,
            'reasoning' => $reasoning,
            'strategy_points' => $strategyPoints,
            'suggested_next_steps' => $suggestedNextSteps,
            'assumptions' => $assumptions,
        ];
    }

    /**
     * Natural-language explanation for a deterministic prioritize result.
     *
     * @param  array<string, mixed>  $promptData
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   summary: string,
     *   assistant_note: string|null,
     *   reasoning: string|null,
     *   strategy_points: list<string>,
     *   suggested_next_steps: list<string>,
     *   assumptions: list<string>
     * }
     */
    public function refinePrioritize(
        array $promptData,
        string $userMessage,
        array $items,
        string $deterministicSummary,
        int $threadId,
        int $userId,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::hybridNarrativeSchema();
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                'The following prioritized items were selected by deterministic backend rules. '.
                'Do NOT change ordering or selection. Only write supportive narrative fields in JSON.'."\n\n".
                'ITEMS_JSON: '.$itemsJson
            ),
        ]);

        $summary = $deterministicSummary;
        $assistantNote = null;
        $reasoning = null;
        $strategyPoints = [];
        $suggestedNextSteps = [];
        $assumptions = [];

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                'prioritize_narrative'
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['summary']) && is_string($payload['summary'])) {
                $summary = $payload['summary'] !== '' ? $payload['summary'] : $deterministicSummary;
            }

            if (isset($payload['assistant_note']) && is_string($payload['assistant_note'])) {
                $assistantNote = $payload['assistant_note'] !== '' ? $payload['assistant_note'] : null;
            }
            if (isset($payload['reasoning']) && is_string($payload['reasoning'])) {
                $reasoning = $payload['reasoning'] !== '' ? $payload['reasoning'] : null;
            }
            if (is_array($payload['strategy_points'] ?? null)) {
                $strategyPoints = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['strategy_points']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['suggested_next_steps'] ?? null)) {
                $suggestedNextSteps = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['suggested_next_steps']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['assumptions'] ?? null)) {
                $assumptions = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['assumptions']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.prioritize.narrative_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'summary' => $summary,
            'assistant_note' => $assistantNote,
            'reasoning' => $reasoning,
            'strategy_points' => $strategyPoints,
            'suggested_next_steps' => $suggestedNextSteps,
            'assumptions' => $assumptions,
        ];
    }

    /**
     * Natural-language explanation for a deterministic browse/listing result.
     *
     * @param  array<string, mixed>  $promptData
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   summary: string,
     *   assistant_note: string|null,
     *   reasoning: string|null,
     *   strategy_points: list<string>,
     *   suggested_next_steps: list<string>,
     *   assumptions: list<string>
     * }
     */
    public function refineBrowseListing(
        array $promptData,
        string $userMessage,
        array $items,
        string $deterministicSummary,
        string $filterDescription,
        bool $ambiguous,
        int $threadId,
        int $userId,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::browseNarrativeSchema();
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $listLabel = $ambiguous
            ? 'The user asked for a general list; the backend returned a short ranked slice (see FILTER_CONTEXT).'
            : 'The user asked with filters; the backend applied FILTER_CONTEXT and ranking.';

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                'The following tasks were selected by backend filtering and ranking. '.
                'Do NOT change ordering or membership. Only fill narrative JSON fields in the schema.'."\n\n".
                $listLabel."\n\n".
                'Answer in the user\'s voice: mirror their request (what they asked to see) in the summary and reasoning. '.
                'Do not claim all tasks are due on one day unless the items support that. '.
                'Do not invent tasks, deadlines, or priorities. '.
                'Suggested next steps must match what they asked (e.g. refine filters, pick a task), not generic calendar scheduling unless they asked about time.'."\n\n".
                'FILTER_CONTEXT: '.$filterDescription."\n\n".
                'ITEMS_JSON: '.$itemsJson
            ),
        ]);

        $summary = $deterministicSummary;
        $assistantNote = null;
        $reasoning = null;
        $strategyPoints = [];
        $suggestedNextSteps = [];

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                'browse_narrative'
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['summary']) && is_string($payload['summary'])) {
                $summary = $payload['summary'] !== '' ? $payload['summary'] : $deterministicSummary;
            }

            if (isset($payload['assistant_note']) && is_string($payload['assistant_note'])) {
                $assistantNote = $payload['assistant_note'] !== '' ? $payload['assistant_note'] : null;
            }
            if (isset($payload['reasoning']) && is_string($payload['reasoning'])) {
                $reasoning = $payload['reasoning'] !== '' ? $payload['reasoning'] : null;
            }
            if (is_array($payload['strategy_points'] ?? null)) {
                $strategyPoints = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['strategy_points']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['suggested_next_steps'] ?? null)) {
                $suggestedNextSteps = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['suggested_next_steps']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.browse.narrative_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'summary' => $summary,
            'assistant_note' => $assistantNote,
            'reasoning' => $reasoning,
            'strategy_points' => $strategyPoints,
            'suggested_next_steps' => $suggestedNextSteps,
            'assumptions' => [],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $messages
     */
    private function attemptStructured(
        Collection $messages,
        array $promptData,
        mixed $refinementSchema,
        int $maxRetries,
        string $generationRoute,
    ): mixed {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($messages->all())
                    ->withTools([])
                    ->withSchema($refinementSchema)
                    ->withClientOptions($this->resolveClientOptionsForRoute($generationRoute))
                    ->asStructured();
            } catch (\Throwable $exception) {
                if ($attempt === $maxRetries) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable hybrid narrative retry state.');
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => $this->fallbackProvider($provider),
        };
    }

    private function fallbackProvider(string $provider): Provider
    {
        Log::warning('task-assistant.provider.fallback', [
            'layer' => 'llm_narrative',
            'requested_provider' => $provider,
            'fallback_provider' => 'ollama',
        ]);

        return Provider::Ollama;
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveClientOptionsForRoute(string $route): array
    {
        $temperature = config('task-assistant.generation.'.$route.'.temperature');
        $maxTokens = config('task-assistant.generation.'.$route.'.max_tokens');
        $topP = config('task-assistant.generation.'.$route.'.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : (float) config('task-assistant.generation.temperature', 0.3),
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : (int) config('task-assistant.generation.max_tokens', 1200),
            'top_p' => is_numeric($topP) ? (float) $topP : (float) config('task-assistant.generation.top_p', 0.9),
        ];
    }
}
