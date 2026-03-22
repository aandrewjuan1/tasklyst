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
        $chunkSize = $chunkSize ?? self::STREAM_CHUNK_SIZE;

        $assistantMessage->update([
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'structured' => $envelope,
                'streamed' => true,
            ]),
        ]);

        $content = $assistantMessage->content ?? '';
        $chunkCount = 0;
        foreach (mb_str_split($content, $chunkSize) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $chunkCount++;
            broadcast(new TaskAssistantJsonDelta($userId, $chunk));
        }

        $envelopeJson = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $envelopeJson = is_string($envelopeJson) ? $envelopeJson : '';
        $truncated = false;
        if (strlen($envelopeJson) > self::LOG_ENVELOPE_JSON_MAX) {
            $envelopeJson = substr($envelopeJson, 0, self::LOG_ENVELOPE_JSON_MAX).'…';
            $truncated = true;
        }

        Log::info('task-assistant.broadcast', [
            'layer' => 'broadcast',
            'user_id' => $userId,
            'assistant_message_id' => $assistantMessage->id,
            'thread_id' => $envelope['meta']['thread_id'] ?? null,
            'flow' => $envelope['flow'] ?? null,
            'ok' => $envelope['ok'] ?? null,
            'type' => $envelope['type'] ?? null,
            'json_delta_chunks' => $chunkCount,
            'assistant_text_bytes' => mb_strlen($content),
            'structured_envelope_json' => $envelopeJson,
            'structured_envelope_truncated' => $truncated,
        ]);

        broadcast(new TaskAssistantStreamEnd($userId));
    }
}
