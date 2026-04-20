<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantMetadataKeys;

final class TaskAssistantProcessingGuard
{
    public function isMessageStopped(TaskAssistantMessage $assistantMessage): bool
    {
        $fresh = TaskAssistantMessage::query()
            ->whereKey($assistantMessage->id)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $fresh) {
            return false;
        }

        return data_get($fresh->metadata, TaskAssistantMetadataKeys::STREAM_STATUS) === 'stopped';
    }

    public function isCancellationRequested(TaskAssistantThread $thread, int $assistantMessageId): bool
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return false;
        }

        $thread->refresh();

        $threadCancelRequested = (bool) data_get($thread->metadata, 'stream.processing.cancel_requested', false);
        $messageStopped = data_get($assistantMessage->metadata, TaskAssistantMetadataKeys::STREAM_STATUS) === 'stopped';

        return $threadCancelRequested || $messageStopped;
    }

    public function alreadyProcessed(int $threadId, int $assistantMessageId): bool
    {
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $threadId)
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return false;
        }

        $phase = (string) data_get($assistantMessage->metadata, TaskAssistantMetadataKeys::STREAM_PHASE, '');
        $hasStructuredEnvelope = is_array(data_get($assistantMessage->metadata, TaskAssistantMetadataKeys::STRUCTURED, null));

        return $phase === 'stream_end' || $hasStructuredEnvelope;
    }
}
