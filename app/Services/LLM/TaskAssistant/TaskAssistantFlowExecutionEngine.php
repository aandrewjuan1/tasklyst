<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
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
        $snapshot = $this->snapshotService->buildForUser($thread->user);

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

        $payload = $generationResult['data'] ?? [];
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

        $assistantMessage->update([
            'content' => $assistantContent,
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                $metadataKey => $payload,
                'processed' => $processedValid,
                'validation_errors' => $processedResponse['errors'],
            ]),
        ]);

        $mergedErrors = array_values(array_unique(array_merge($generationErrors, $processedResponse['errors'] ?? [])));

        Log::info('task-assistant.flow_execution', [
            'layer' => 'flow_execution',
            'flow' => $flow,
            'metadata_key' => $metadataKey,
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'generation_valid' => $generationValid,
            'processed_valid' => $processedValid,
            'final_valid' => $finalValid,
            'merged_errors' => $mergedErrors,
            'generation_payload_summary' => self::summarizeGenerationPayload($flow, $payload),
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function summarizeGenerationPayload(string $flow, array $payload): array
    {
        $summary = ['flow' => $flow];

        if ($flow === 'prioritize' || $flow === 'browse') {
            $items = $payload['items'] ?? [];
            $summary['items_count'] = is_array($items) ? count($items) : 0;
            $summary['limit_used'] = $payload['limit_used'] ?? null;
        }

        if ($flow === 'daily_schedule') {
            $proposals = $payload['proposals'] ?? [];
            $blocks = $payload['blocks'] ?? [];
            $summary['proposals_count'] = is_array($proposals) ? count($proposals) : 0;
            $summary['blocks_count'] = is_array($blocks) ? count($blocks) : 0;
        }

        return $summary;
    }
}
