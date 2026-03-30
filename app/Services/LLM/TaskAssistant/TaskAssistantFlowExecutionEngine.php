<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class TaskAssistantFlowExecutionEngine
{
    public function __construct(
        private readonly TaskAssistantResponseProcessor $responseProcessor,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantToolEventPersister $toolEventPersister,
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

        if ($this->isStopped($assistantMessage)) {
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

        $toolCalls = $generationResult['tool_calls'] ?? [];
        $toolResults = $generationResult['tool_results'] ?? [];

        if ($toolCalls !== [] || $toolResults !== []) {
            $toolCalls = $toolCalls instanceof \Illuminate\Support\Collection
                ? $toolCalls->all()
                : (is_array($toolCalls) ? $toolCalls : iterator_to_array($toolCalls));

            $toolResults = $toolResults instanceof \Illuminate\Support\Collection
                ? $toolResults->all()
                : (is_array($toolResults) ? $toolResults : iterator_to_array($toolResults));

            $this->toolEventPersister->persistToolCallsAndResults(
                assistantMessage: $assistantMessage,
                toolCalls: $toolCalls,
                toolResults: $toolResults
            );
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

        $assistantContent = $finalValid
            ? (string) ($processedResponse['formatted_content'] ?? '')
            : $assistantFallbackContent;

        if ($this->isStopped($assistantMessage)) {
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

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                $metadataKey => $payload,
                'processed' => $processedValid,
                'validation_errors' => $processedResponse['errors'],
            ]),
        ]);

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
            'merged_errors' => $mergedErrors,
            'generation_payload_summary' => self::summarizeGenerationPayload($flow, $payload),
            'assistant_content_length' => mb_strlen($assistantContent),
            'elapsed_ms' => $elapsedMs,
        ]);

        return [
            'final_valid' => $finalValid,
            'assistant_content' => $assistantContent,
            'structured_data' => $processedResponse['structured_data'] ?? [],
            'merged_errors' => $mergedErrors,
            'processed_valid' => $processedValid,
            'processed_errors' => $processedResponse['errors'] ?? [],
        ];
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
            return $this->snapshotService->buildForUser($user);
        }

        $proposals = is_array($payload['proposals'] ?? null) ? $payload['proposals'] : [];
        if ($proposals === []) {
            return $this->snapshotService->buildForUser($user);
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

    private function isStopped(TaskAssistantMessage $assistantMessage): bool
    {
        $fresh = TaskAssistantMessage::query()
            ->whereKey($assistantMessage->id)
            ->where('role', \App\Enums\MessageRole::Assistant)
            ->first();

        if (! $fresh) {
            return false;
        }

        return data_get($fresh->metadata, 'stream.status') === 'stopped';
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

        return $summary;
    }
}
