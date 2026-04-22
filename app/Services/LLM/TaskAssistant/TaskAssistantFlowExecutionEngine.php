<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use Illuminate\Support\Facades\Log;

final class TaskAssistantFlowExecutionEngine
{
    public function __construct(
        private readonly TaskAssistantResponseProcessor $responseProcessor,
        private readonly AssistantCandidateProvider $candidateProvider,
        private readonly AssistantMetadataGateway $metadataGateway,
        private readonly TaskAssistantProcessingGuard $processingGuard,
    ) {}

    /**
     * Convert a generation-only structured payload into validated + formatted content,
     * persist it to the assistant message, and return the final execution results.
     *
     * @param  array{
     *   valid: bool,
     *   data: array<string, mixed>,
     *   errors?: array<int, string>
     * }  $generationResult
     * @return array{
     *   final_valid: bool,
     *   assistant_content: string,
     *   structured_data: array<string, mixed>,
     *   merged_errors: array<int, string>,
     *   processed_valid: bool,
     *   processed_errors: array<int, string>
     * }
     */
    public function executeStructuredFlow(
        string $flow,
        string $metadataKey,
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $generationResult,
        string $assistantFallbackContent
    ): array {
        $runId = app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null;
        $startNs = hrtime(true);

        Log::info('task-assistant.flow_execution.begin', [
            'layer' => 'flow_execution',
            'run_id' => $runId,
            'flow' => $flow,
            'metadata_key' => $metadataKey,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'generation_valid' => (bool) ($generationResult['valid'] ?? false),
            'generation_data_keys' => array_keys(is_array($generationResult['data'] ?? null) ? $generationResult['data'] : []),
        ]);

        if ($this->processingGuard->isMessageStopped($assistantMessage)) {
            Log::info('task-assistant.flow_execution', [
                'layer' => 'flow_execution',
                'run_id' => $runId,
                'flow' => $flow,
                'metadata_key' => $metadataKey,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'skipped_due_to_stopped' => true,
            ]);

            return [
                'final_valid' => false,
                'assistant_content' => '',
                'structured_data' => [],
                'merged_errors' => ['cancelled'],
                'processed_valid' => false,
                'processed_errors' => ['cancelled'],
            ];
        }

        $payload = $generationResult['data'] ?? [];
        $snapshot = $this->buildSnapshotForFlow($flow, $thread->user, $payload);
        if ($flow === 'daily_schedule' && is_array($payload['proposals'] ?? null)) {
            // Snapshot is already built for validation in buildSnapshotForFlow().
        }

        $generationValid = (bool) ($generationResult['valid'] ?? false);
        $generationErrors = $generationResult['errors'] ?? [];

        $processedResponse = $this->responseProcessor->processResponse(
            flow: $flow,
            data: $payload,
            snapshot: $snapshot,
        );

        $processedValid = (bool) ($processedResponse['valid'] ?? false);
        $finalValid = $generationValid && $processedValid;
        $structuredData = $finalValid
            ? (is_array($processedResponse['structured_data'] ?? null) ? $processedResponse['structured_data'] : [])
            : $this->minimalStructuredDataForInvalidFlow($flow, $payload);

        $assistantContent = $finalValid
            ? (string) ($processedResponse['formatted_content'] ?? '')
            : $this->buildInvalidFlowFallbackContent($flow, $payload, $assistantFallbackContent);

        if ($this->processingGuard->isMessageStopped($assistantMessage)) {
            Log::info('task-assistant.flow_execution', [
                'layer' => 'flow_execution',
                'run_id' => $runId,
                'flow' => $flow,
                'metadata_key' => $metadataKey,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'skipped_write_due_to_stopped' => true,
            ]);

            return [
                'final_valid' => false,
                'assistant_content' => '',
                'structured_data' => [],
                'merged_errors' => ['cancelled'],
                'processed_valid' => false,
                'processed_errors' => ['cancelled'],
            ];
        }

        $assistantMessage->update(['content' => $assistantContent]);
        $this->metadataGateway->updateProcessedPayload(
            assistantMessage: $assistantMessage,
            metadataKey: $metadataKey,
            payload: $payload,
            processed: $processedValid,
            errors: is_array($processedResponse['errors'] ?? null) ? $processedResponse['errors'] : [],
        );

        $mergedErrors = array_values(array_unique(array_merge($generationErrors, $processedResponse['errors'] ?? [])));
        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);

        Log::info('task-assistant.flow_execution', [
            'layer' => 'flow_execution',
            'run_id' => $runId,
            'flow' => $flow,
            'metadata_key' => $metadataKey,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'generation_valid' => $generationValid,
            'processed_valid' => $processedValid,
            'final_valid' => $finalValid,
            'structured_data_mode' => $finalValid ? 'full' : 'safe_minimal_invalid',
            'merged_errors' => $mergedErrors,
            'generation_payload_summary' => self::summarizeGenerationPayload($flow, $payload),
            'assistant_content_length' => mb_strlen($assistantContent),
            'elapsed_ms' => $elapsedMs,
        ]);

        return [
            'final_valid' => $finalValid,
            'assistant_content' => $assistantContent,
            'structured_data' => $structuredData,
            'merged_errors' => $mergedErrors,
            'processed_valid' => $processedValid,
            'processed_errors' => $processedResponse['errors'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function minimalStructuredDataForInvalidFlow(string $flow, array $payload): array
    {
        return match ($flow) {
            'prioritize' => [
                'items' => [],
                'limit_used' => 0,
                'focus' => [
                    'main_task' => 'Unable to generate ranked items',
                    'secondary_tasks' => [],
                ],
                'framing' => null,
                'reasoning' => 'I could not validate a ranked list this time.',
                'next_options' => 'If you want, ask me to prioritize again or schedule one task.',
                'next_options_chip_texts' => [],
                'ranking_method_summary' => 'I could not verify a full ranking explanation for this response.',
                'ordering_rationale' => [],
                'filter_interpretation' => null,
                'count_mismatch_explanation' => null,
            ],
            'daily_schedule' => [
                'proposals' => [],
                'items' => [],
                'blocks' => [],
                'schedule_variant' => (string) ($payload['schedule_variant'] ?? 'daily'),
                'window_selection_explanation' => '',
                'ordering_rationale' => [],
                'blocking_reasons' => [],
                'fallback_choice_explanation' => null,
                'confirmation_required' => false,
                'awaiting_user_decision' => false,
                'confirmation_context' => null,
                'fallback_preview' => null,
            ],
            'listing_followup' => [
                'verdict' => 'partial',
                'compared_items' => [],
                'more_urgent_alternatives' => [],
                'framing' => 'I could not validate that follow-up comparison this time.',
                'rationale' => 'Please ask for your top tasks again, then I can compare clearly.',
                'caveats' => null,
                'next_options' => 'If you want, I can show a prioritized list or help you schedule.',
                'next_options_chip_texts' => [
                    'What should I do first',
                    'Plan my day tomorrow',
                ],
            ],
            'general_guidance' => [
                'intent' => 'task',
                'acknowledgement' => 'I hear you.',
                'message' => 'I had trouble validating that response, but I can still help with your tasks.',
                'suggested_next_actions' => [
                    'Tell me what to prioritize first',
                    'Share when you want to schedule work',
                ],
                'next_options' => (string) config('task-assistant.general_guidance.default_next_options', 'If you want, I can help you decide what to do first or schedule time for your work.'),
                'next_options_chip_texts' => $this->generalGuidanceDefaultChips(),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildInvalidFlowFallbackContent(string $flow, array $payload, string $assistantFallbackContent): string
    {
        $segments = match ($flow) {
            'daily_schedule' => $this->collectDailyScheduleFallbackSegments($payload),
            'prioritize' => $this->collectPrioritizeFallbackSegments($payload),
            default => [],
        };

        if ($segments === []) {
            return $assistantFallbackContent;
        }

        $combined = trim(implode("\n\n", $segments));
        if ($combined === '') {
            return $assistantFallbackContent;
        }

        if (mb_strlen($combined) > 1800) {
            return trim(mb_substr($combined, 0, 1799)).'…';
        }

        return $combined;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function collectDailyScheduleFallbackSegments(array $payload): array
    {
        $segments = [];
        foreach (['framing', 'reasoning', 'confirmation'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                $segments[] = $value;
            }
        }

        if ($segments === []) {
            $prompt = trim((string) data_get($payload, 'confirmation_context.prompt', ''));
            if ($prompt !== '') {
                $segments[] = $prompt;
            }
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function collectPrioritizeFallbackSegments(array $payload): array
    {
        $segments = [];
        foreach (['acknowledgment', 'framing', 'reasoning', 'next_options'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                $segments[] = $value;
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function generalGuidanceDefaultChips(): array
    {
        $raw = config('task-assistant.general_guidance.next_options_chip_texts', []);
        if (! is_array($raw) || $raw === []) {
            return ['What should I do first', 'Schedule my most important task'];
        }

        $chips = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $raw
        ), static fn (string $value): bool => $value !== ''));

        if ($chips === []) {
            return ['What should I do first', 'Schedule my most important task'];
        }

        if (count($chips) === 1) {
            return [$chips[0], 'Schedule my most important task'];
        }

        return array_slice($chips, 0, 2);
    }

    /**
     * Build the validation snapshot used by TaskAssistantResponseProcessor.
     *
     * For `daily_schedule` we must not rely on a limited assistant snapshot payload.
     * Instead we build allowed-ID lists directly from the proposals and verify
     * existence via DB queries.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildSnapshotForFlow(string $flow, User $user, array $payload): array
    {
        if ($flow !== 'daily_schedule') {
            return $this->candidateProvider->candidatesForUser(
                user: $user,
                taskLimit: 20,
                eventHoursAhead: 168,
                eventHoursBack: 24,
                eventLimit: 30,
                projectLimit: 20,
            );
        }

        $proposals = is_array($payload['proposals'] ?? null) ? $payload['proposals'] : [];
        if ($proposals === []) {
            return $this->candidateProvider->candidatesForUser(
                user: $user,
                taskLimit: 20,
                eventHoursAhead: 168,
                eventHoursBack: 24,
                eventLimit: 30,
                projectLimit: 20,
            );
        }

        $taskIds = [];
        $eventIds = [];
        $projectIds = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $entityType = (string) ($proposal['entity_type'] ?? '');
            $entityId = $proposal['entity_id'] ?? null;
            $id = is_numeric($entityId) ? (int) $entityId : 0;
            if ($id <= 0) {
                continue;
            }

            if ($entityType === 'task') {
                $taskIds[$id] = true;

                continue;
            }
            if ($entityType === 'event') {
                $eventIds[$id] = true;

                continue;
            }
            if ($entityType === 'project') {
                $projectIds[$id] = true;
            }
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $now = now()->setTimezone($timezone);

        $tasks = $taskIds === []
            ? []
            : Task::query()
                ->forUser($user->id)
                ->whereIn('id', array_keys($taskIds))
                ->get(['id'])
                ->map(static fn (Task $task): array => ['id' => (int) $task->id])
                ->values()
                ->all();

        $events = $eventIds === []
            ? []
            : Event::query()
                ->forUser($user->id)
                ->whereIn('id', array_keys($eventIds))
                ->get(['id'])
                ->map(static fn (Event $event): array => ['id' => (int) $event->id])
                ->values()
                ->all();

        $projects = $projectIds === []
            ? []
            : Project::query()
                ->forUser($user->id)
                ->whereIn('id', array_keys($projectIds))
                ->get(['id'])
                ->map(static fn (Project $project): array => ['id' => (int) $project->id])
                ->values()
                ->all();

        return [
            'today' => $now->toDateString(),
            'timezone' => $timezone,
            'tasks' => $tasks,
            'events' => $events,
            'projects' => $projects,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function summarizeGenerationPayload(string $flow, array $payload): array
    {
        $summary = ['flow' => $flow];

        if ($flow === 'prioritize') {
            $items = $payload['items'] ?? [];
            $summary['items_count'] = is_array($items) ? count($items) : 0;
            $summary['limit_used'] = $payload['limit_used'] ?? null;
            $summary['prioritize_variant'] = $payload['prioritize_variant'] ?? null;
        }

        if ($flow === 'daily_schedule') {
            $proposals = $payload['proposals'] ?? [];
            $blocks = $payload['blocks'] ?? [];
            $items = $payload['items'] ?? [];
            $summary['proposals_count'] = is_array($proposals) ? count($proposals) : 0;
            $summary['blocks_count'] = is_array($blocks) ? count($blocks) : 0;
            $summary['items_count'] = is_array($items) ? count($items) : 0;
        }

        if ($flow === 'listing_followup') {
            $summary['verdict'] = $payload['verdict'] ?? null;
            $compared = $payload['compared_items'] ?? [];
            $summary['compared_items_count'] = is_array($compared) ? count($compared) : 0;
        }

        return $summary;
    }
}
