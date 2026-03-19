<?php

namespace App\Services\LLM\TaskAssistant;

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Models\TaskAssistantMessage;

final class TaskAssistantStreamingBroadcaster
{
    private const STREAM_CHUNK_SIZE = 200;

    public function __construct(
        private readonly TaskAssistantPrismTextDeltaExtractor $deltaExtractor
    ) {}

    /**
     * Broadcast streamed Prism text (token deltas) and persist assistant content periodically.
     */
    public function streamPrismTextToAssistant(
        mixed $pending,
        int $userId,
        TaskAssistantMessage $assistantMessage,
        int $persistEveryChars = 400,
        ?int $fallbackChunkSize = null
    ): void {
        $fullText = '';
        $lastPersistedLength = 0;

        $fallbackChunkSize = $fallbackChunkSize ?? self::STREAM_CHUNK_SIZE;

        if (method_exists($pending, 'asStream')) {
            foreach ($pending->asStream() as $event) {
                $delta = $this->deltaExtractor->extractDelta($event);
                if ($delta === null) {
                    continue;
                }

                $fullText .= $delta;
                broadcast(new TaskAssistantJsonDelta($userId, $delta));

                if (strlen($fullText) - $lastPersistedLength >= $persistEveryChars) {
                    $assistantMessage->update([
                        'content' => $fullText,
                    ]);
                    $lastPersistedLength = strlen($fullText);
                }
            }
        } else {
            $textResponse = $pending->asText();
            $fullText = (string) ($textResponse->text ?? '');

            foreach (mb_str_split($fullText, $fallbackChunkSize) as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                broadcast(new TaskAssistantJsonDelta($userId, $chunk));
            }
        }

        $assistantMessage->update([
            'content' => $fullText,
            'metadata' => array_merge($assistantMessage->metadata ?? [], [
                'streamed' => true,
            ]),
        ]);

        broadcast(new TaskAssistantStreamEnd($userId));
    }

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

    // Delta extraction is centralized in TaskAssistantPrismTextDeltaExtractor.
}
