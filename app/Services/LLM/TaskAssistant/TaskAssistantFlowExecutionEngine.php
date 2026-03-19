<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;

final class TaskAssistantFlowExecutionEngine
{
    public function __construct(
        private readonly TaskAssistantResponseProcessor $responseProcessor,
        private readonly TaskAssistantSnapshotService $snapshotService,
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
        string $originalUserMessage,
        string $assistantFallbackContent
    ): array {
        $snapshot = $this->snapshotService->buildForUser($thread->user);

        $payload = $generationResult['data'] ?? [];
        $generationValid = (bool) ($generationResult['valid'] ?? false);
        $generationErrors = $generationResult['errors'] ?? [];

        $processedResponse = $this->responseProcessor->processResponse(
            flow: $flow,
            data: $payload,
            snapshot: $snapshot,
            thread: $thread,
            originalUserMessage: $originalUserMessage,
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

        return [
            'final_valid' => $finalValid,
            'assistant_content' => $assistantContent,
            'structured_data' => $processedResponse['structured_data'] ?? [],
            'merged_errors' => $mergedErrors,
            'processed_valid' => $processedValid,
            'processed_errors' => $processedResponse['errors'] ?? [],
        ];
    }
}
