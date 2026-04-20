<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantMessage;
use App\Support\LLM\TaskAssistantMetadataKeys;

final class AssistantMetadataGateway
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function setStreamPhase(TaskAssistantMessage $assistantMessage, string $phase, array $extra = []): void
    {
        $metadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        data_set($metadata, TaskAssistantMetadataKeys::STREAM_PHASE, $phase);
        data_set($metadata, TaskAssistantMetadataKeys::STREAM_PHASE_AT, now()->toIso8601String());

        foreach ($extra as $key => $value) {
            data_set($metadata, 'stream.'.$key, $value);
        }

        $assistantMessage->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateProcessedPayload(
        TaskAssistantMessage $assistantMessage,
        string $metadataKey,
        array $payload,
        bool $processed,
        array $errors,
    ): void {
        $metadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        $metadata[$metadataKey] = $payload;
        $metadata[TaskAssistantMetadataKeys::PROCESSED] = $processed;
        $metadata[TaskAssistantMetadataKeys::VALIDATION_ERRORS] = $errors;

        $assistantMessage->update(['metadata' => $metadata]);
    }
}
