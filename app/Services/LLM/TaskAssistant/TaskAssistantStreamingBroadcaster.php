<?php

namespace App\Services\LLM\TaskAssistant;

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Models\TaskAssistantMessage;

final class TaskAssistantStreamingBroadcaster
{
    private const STREAM_CHUNK_SIZE = 200;

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
        foreach (mb_str_split($content, $chunkSize) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            broadcast(new TaskAssistantJsonDelta($userId, $chunk));
        }

        broadcast(new TaskAssistantStreamEnd($userId));
    }
}
