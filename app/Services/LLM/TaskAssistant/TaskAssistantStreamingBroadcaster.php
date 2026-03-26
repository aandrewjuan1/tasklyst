<?php

namespace App\Services\LLM\TaskAssistant;

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Models\TaskAssistantMessage;
use Illuminate\Support\Facades\Log;

final class TaskAssistantStreamingBroadcaster
{
    private const STREAM_CHUNK_SIZE = 200;

    private const LOG_ENVELOPE_JSON_MAX = 16000;

    /**
     * Persist structured envelope into assistant metadata and stream formatted message content.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function streamFinalAssistantJson(int $userId, TaskAssistantMessage $assistantMessage, array $envelope, ?int $chunkSize = null): void
    {
        if ($this->isMessageStopped($assistantMessage)) {
            broadcast(new TaskAssistantStreamEnd($userId, $assistantMessage->id));

            return;
        }

        $chunkSize = $chunkSize
            ?? max(8, (int) config('task-assistant.streaming.chunk_size', self::STREAM_CHUNK_SIZE));
        $interChunkDelayMs = max(0, (int) config('task-assistant.streaming.inter_chunk_delay_ms', 0));
        $maxTypingEffectMs = max(0, (int) config('task-assistant.streaming.max_typing_effect_ms', 0));
        $enableTypingEffect = (bool) config('task-assistant.streaming.enable_typing_effect', false);

        $assistantMessage->update([
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'structured' => $envelope,
                'streamed' => true,
            ]),
        ]);

        $content = $assistantMessage->content ?? '';
        $chunkCount = 0;
        $streamStartNs = hrtime(true);
        foreach (mb_str_split($content, $chunkSize) as $chunk) {
            if ($this->isCancellationRequested($assistantMessage) || $this->isMessageStopped($assistantMessage)) {
                $this->markCancelled($assistantMessage);
                break;
            }

            if ($chunk === '') {
                continue;
            }
            $chunkCount++;
            broadcast(new TaskAssistantJsonDelta($userId, $assistantMessage->id, $chunk));

            if (! $enableTypingEffect || $interChunkDelayMs <= 0) {
                continue;
            }

            if ($maxTypingEffectMs > 0) {
                $elapsedMs = (int) ((hrtime(true) - $streamStartNs) / 1_000_000);
                if ($elapsedMs >= $maxTypingEffectMs) {
                    continue;
                }
                $remainingMs = $maxTypingEffectMs - $elapsedMs;
                $sleepMs = min($interChunkDelayMs, $remainingMs);
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } else {
                usleep($interChunkDelayMs * 1000);
            }
        }

        $envelopeJson = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $envelopeJson = is_string($envelopeJson) ? $envelopeJson : '';
        $truncated = false;
        if (strlen($envelopeJson) > self::LOG_ENVELOPE_JSON_MAX) {
            $envelopeJson = substr($envelopeJson, 0, self::LOG_ENVELOPE_JSON_MAX).'…';
            $truncated = true;
        }

        Log::debug('task-assistant.broadcast', [
            'layer' => 'broadcast',
            'user_id' => $userId,
            'assistant_message_id' => $assistantMessage->id,
            'thread_id' => $envelope['meta']['thread_id'] ?? null,
            'flow' => $envelope['flow'] ?? null,
            'ok' => $envelope['ok'] ?? null,
            'type' => $envelope['type'] ?? null,
            'json_delta_chunks' => $chunkCount,
            'chunk_size' => $chunkSize,
            'typing_effect_enabled' => $enableTypingEffect,
            'inter_chunk_delay_ms' => $interChunkDelayMs,
            'max_typing_effect_ms' => $maxTypingEffectMs,
            'assistant_text_bytes' => mb_strlen($content),
            'structured_envelope_json' => $envelopeJson,
            'structured_envelope_truncated' => $truncated,
        ]);

        broadcast(new TaskAssistantStreamEnd($userId, $assistantMessage->id));
    }

    private function isCancellationRequested(TaskAssistantMessage $assistantMessage): bool
    {
        return $this->isMessageStopped($assistantMessage);
    }

    private function markCancelled(TaskAssistantMessage $assistantMessage): void
    {
        /** @var \App\Models\TaskAssistantThread|null $thread */
        $thread = $assistantMessage->thread()->first();
        if (! $thread) {
            return;
        }

        $messageMetadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        data_set($messageMetadata, 'stream.status', 'stopped');
        data_set($messageMetadata, 'stream.stopped_at', now()->toIso8601String());
        $assistantMessage->update([
            'content' => '',
            'metadata' => $messageMetadata,
        ]);

        $threadMetadata = is_array($thread->metadata ?? null) ? $thread->metadata : [];
        data_set($threadMetadata, 'stream.processing', null);
        data_set($threadMetadata, 'stream.last_completed_at', now()->toIso8601String());
        $thread->update(['metadata' => $threadMetadata]);
    }

    private function isMessageStopped(TaskAssistantMessage $assistantMessage): bool
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
}
