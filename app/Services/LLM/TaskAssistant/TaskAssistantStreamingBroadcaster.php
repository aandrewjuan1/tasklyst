<?php

namespace App\Services\LLM\TaskAssistant;

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Events\TaskAssistantToolCall;
use App\Events\TaskAssistantToolResult;
use App\Models\TaskAssistantMessage;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

final class TaskAssistantStreamingBroadcaster
{
    private const STREAM_CHUNK_SIZE = 200;

    public function __construct(
        private readonly TaskAssistantPrismTextDeltaExtractor $deltaExtractor,
        private readonly TaskAssistantToolEventPersister $toolEventPersister,
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
        /** @var array<string, true> */
        $seenToolCallIds = [];
        /** @var array<string, true> */
        $seenToolResultCallIds = [];

        if (method_exists($pending, 'asStream')) {
            foreach ($pending->asStream() as $event) {
                if ($event instanceof ToolCallEvent) {
                    $toolCall = $event->toolCall;
                    $this->toolEventPersister->persistToolCall(
                        assistantMessage: $assistantMessage,
                        toolCall: $toolCall,
                        seenToolCallIds: $seenToolCallIds
                    );

                    try {
                        broadcast(new TaskAssistantToolCall(
                            userId: $userId,
                            toolCallId: $toolCall->id,
                            toolName: $toolCall->name,
                            arguments: $toolCall->arguments(),
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('task-assistant.broadcast.tool_call_failed', [
                            'user_id' => $userId,
                            'tool_call_id' => $toolCall->id,
                            'tool_name' => $toolCall->name,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    continue;
                }

                if ($event instanceof ToolResultEvent) {
                    $toolResult = $event->toolResult;
                    $this->toolEventPersister->persistToolResult(
                        assistantMessage: $assistantMessage,
                        toolResult: $toolResult,
                        seenToolResultCallIds: $seenToolResultCallIds,
                        success: $event->success,
                        error: $event->error,
                    );
                    try {
                        broadcast(new TaskAssistantToolResult(
                            userId: $userId,
                            toolCallId: $toolResult->toolCallId,
                            toolName: $toolResult->toolName,
                            result: '',
                            success: $event->success,
                            error: $event->error,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('task-assistant.broadcast.tool_result_failed', [
                            'user_id' => $userId,
                            'tool_call_id' => $toolResult->toolCallId,
                            'tool_name' => $toolResult->toolName,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    continue;
                }

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
