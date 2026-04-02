<?php

namespace App\Services\LLM\Scheduling;

use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Optional structured LLM fallback when deterministic schedule-refinement parsing fails.
 */
final class ScheduleRefinementStructuredOpExtractor
{
    /** @var list<string> */
    private const ALLOWED_OPS = [
        'shift_minutes',
        'set_duration_minutes',
        'set_local_time_hhmm',
        'set_local_date_ymd',
        'move_to_position',
        'reorder_before',
        'reorder_after',
        'none',
    ];

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly ScheduleEditUnderstandingPipeline $understandingPipeline,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{ok: true, operations: list<array<string, mixed>>}|array{ok: false, error: string}
     */
    public function tryExtract(User $user, string $userMessage, array $proposals): array
    {
        if (! (bool) config('task-assistant.schedule.refinement.llm_fallback_enabled', true)) {
            return ['ok' => false, 'error' => 'llm_fallback_disabled'];
        }

        $trimmed = trim($userMessage);
        if ($trimmed === '' || $proposals === []) {
            return ['ok' => false, 'error' => 'empty_input'];
        }

        $promptData = $this->promptData->forUser($user);
        $lines = [];
        foreach ($proposals as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $uuid = trim((string) ($row['proposal_uuid'] ?? $row['proposal_id'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $start = trim((string) ($row['start_datetime'] ?? ''));
            $lines[] = sprintf('%d | %s | %s | %s', $i, $uuid !== '' ? $uuid : '-', $title, $start);
        }
        $summary = implode("\n", $lines);

        $messages = [
            new UserMessage($trimmed),
            new UserMessage(
                'Draft schedule rows (index | uuid | title | start_datetime ISO). Server times are authoritative.'."\n"
                .$summary."\n\n"
                .'Return only structured JSON matching the schema. operations must be ordered. '
                .'Use proposal_index 0..'.(count($proposals) - 1).'. HH:MM must be 24-hour two-digit hours and minutes.'
            ),
        ];

        try {
            $pending = Prism::structured()
                ->using($this->resolveProvider(), $this->resolveModel())
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($messages)
                ->withTools([])
                ->withSchema(TaskAssistantSchemas::scheduleRefinementOperationsSchema());

            $pending = $this->applyStructuredOptions($pending);
            $structuredResponse = $pending->asStructured();
        } catch (\Throwable $e) {
            Log::warning('task-assistant.schedule_refinement.llm_ops_failed', [
                'layer' => 'schedule_refinement',
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'llm_request_failed'];
        }

        $payload = $structuredResponse->structured ?? [];
        if (! is_array($payload)) {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }

        $rawOps = $payload['operations'] ?? [];
        if (! is_array($rawOps) || $rawOps === []) {
            return ['ok' => false, 'error' => 'no_operations'];
        }

        $validated = $this->validateAndNormalizeOperations($rawOps, $proposals);
        if ($validated === []) {
            return ['ok' => false, 'error' => 'validation_failed'];
        }

        $enriched = $this->understandingPipeline->enrichOperationsWithProposalUuids($validated, $proposals);

        return ['ok' => true, 'operations' => $enriched];
    }

    /**
     * @param  list<mixed>  $rawOps
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    private function validateAndNormalizeOperations(array $rawOps, array $proposals): array
    {
        $count = count($proposals);
        $out = [];
        foreach ($rawOps as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $op = strtolower(trim((string) ($raw['op'] ?? '')));
            if ($op === '' || $op === 'none') {
                continue;
            }
            if (! in_array($op, self::ALLOWED_OPS, true)) {
                Log::debug('task-assistant.schedule_refinement.llm_ops_skip_unknown', ['op' => $op]);

                continue;
            }
            $row = $this->normalizeOneOperation($op, $raw, $count);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalizeOneOperation(string $op, array $raw, int $proposalCount): ?array
    {
        if (in_array($op, ['reorder_before', 'reorder_after', 'move_to_position'], true)) {
            $idx = isset($raw['proposal_index']) ? (int) $raw['proposal_index'] : -1;
            if ($idx < 0 || $idx >= $proposalCount) {
                return null;
            }
            $base = [
                'op' => $op,
                'proposal_index' => $idx,
                'proposal_uuid' => trim((string) ($raw['proposal_uuid'] ?? '')) ?: null,
            ];
            if ($op === 'move_to_position') {
                $t = isset($raw['target_index']) ? (int) $raw['target_index'] : -1;
                if ($t < 0 || $t >= $proposalCount) {
                    return null;
                }
                $base['target_index'] = $t;

                return $base;
            }
            $anchor = isset($raw['anchor_index']) ? (int) $raw['anchor_index'] : -1;
            if ($anchor < 0 || $anchor >= $proposalCount) {
                return null;
            }
            $base['anchor_index'] = $anchor;
            $au = trim((string) ($raw['anchor_proposal_uuid'] ?? ''));
            if ($au !== '') {
                $base['anchor_proposal_uuid'] = $au;
            }

            return $base;
        }

        $idx = isset($raw['proposal_index']) ? (int) $raw['proposal_index'] : -1;
        if ($idx < 0 || $idx >= $proposalCount) {
            return null;
        }

        $row = [
            'op' => $op,
            'proposal_index' => $idx,
            'proposal_uuid' => trim((string) ($raw['proposal_uuid'] ?? '')) ?: null,
        ];

        if ($op === 'shift_minutes') {
            if (! isset($raw['delta_minutes'])) {
                return null;
            }
            $row['delta_minutes'] = (int) $raw['delta_minutes'];
        } elseif ($op === 'set_duration_minutes') {
            $dm = isset($raw['duration_minutes']) ? (int) $raw['duration_minutes'] : 0;
            if ($dm < 1) {
                return null;
            }
            $row['duration_minutes'] = $dm;
        } elseif ($op === 'set_local_time_hhmm') {
            $t = trim((string) ($raw['local_time_hhmm'] ?? ''));
            if (! preg_match('/^\d{2}:\d{2}$/', $t)) {
                return null;
            }
            $row['local_time_hhmm'] = $t;
        } elseif ($op === 'set_local_date_ymd') {
            $d = trim((string) ($raw['local_date_ymd'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return null;
            }
            $row['local_date_ymd'] = $d;
        } else {
            return null;
        }

        return $row;
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    private function applyStructuredOptions(StructuredPendingRequest $pending): StructuredPendingRequest
    {
        $timeout = (int) config('prism.request_timeout', 120);
        $pending = $pending->withClientOptions(['timeout' => $timeout]);

        $routeKey = 'task-assistant.generation.schedule_refinement_ops';
        $temperature = config($routeKey.'.temperature');
        $maxTokens = config($routeKey.'.max_tokens');
        $topP = config($routeKey.'.top_p');

        if (! is_numeric($temperature)) {
            $temperature = config('task-assistant.generation.temperature');
        }
        if (! is_numeric($maxTokens)) {
            $maxTokens = config('task-assistant.generation.max_tokens');
        }
        if (! is_numeric($topP)) {
            $topP = config('task-assistant.generation.top_p');
        }

        if (is_numeric($temperature)) {
            $pending = $pending->usingTemperature((float) $temperature);
        }
        if (is_numeric($maxTokens)) {
            $pending = $pending->withMaxTokens((int) $maxTokens);
        }
        if (is_numeric($topP)) {
            $pending = $pending->usingTopP((float) $topP);
        }

        return $pending;
    }
}
