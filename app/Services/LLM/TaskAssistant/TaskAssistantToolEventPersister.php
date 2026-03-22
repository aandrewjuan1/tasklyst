<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

final class TaskAssistantToolEventPersister
{
    /**
     * Persist a Prism tool call into the assistant message (`task_assistant_messages.tool_calls`).
     *
     * Used by {@see TaskAssistantService} (chat) and {@see TaskAssistantFlowExecutionEngine} (structured flows).
     *
     * @param  array<string, true>  $seenToolCallIds
     */
    public function persistToolCall(
        TaskAssistantMessage $assistantMessage,
        ToolCall $toolCall,
        array &$seenToolCallIds
    ): void {
        $toolCallId = $toolCall->id;
        if (isset($seenToolCallIds[$toolCallId])) {
            return;
        }

        $seenToolCallIds[$toolCallId] = true;

        $toolCalls = $assistantMessage->tool_calls ?? [];
        $toolCalls[] = [
            'id' => $toolCall->id,
            'name' => $toolCall->name,
            'arguments' => $toolCall->arguments(),
        ];

        $assistantMessage->update([
            'tool_calls' => $toolCalls,
        ]);
    }

    /**
     * Persist a Prism tool result as a `role=tool` message.
     *
     * History replay expects these metadata keys:
     * - `tool_call_id`
     * - `tool_name`
     * - `args`
     *
     * @param  array<string, true>  $seenToolResultCallIds
     */
    public function persistToolResult(
        TaskAssistantMessage $assistantMessage,
        ToolResult $toolResult,
        array &$seenToolResultCallIds,
        bool $success = true,
        ?string $error = null
    ): void {
        $toolCallId = $toolResult->toolCallId;
        if (isset($seenToolResultCallIds[$toolCallId])) {
            return;
        }

        $seenToolResultCallIds[$toolCallId] = true;

        $result = $toolResult->result;
        if (is_string($result)) {
            $content = $result;
        } else {
            // Store tool result in a JSON string so history replay can decode it.
            $content = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        TaskAssistantMessage::query()->create([
            'thread_id' => $assistantMessage->thread_id,
            'role' => MessageRole::Tool,
            'content' => $content,
            'metadata' => [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolResult->toolName,
                'args' => $toolResult->args,
                'success' => $success,
                'error' => $error,
            ],
        ]);
    }

    /**
     * Convenience method for non-streaming structured responses.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     */
    public function persistToolCallsAndResults(
        TaskAssistantMessage $assistantMessage,
        array $toolCalls,
        array $toolResults
    ): void {
        $seenToolCallIds = [];
        $seenToolResultCallIds = [];

        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof ToolCall) {
                continue;
            }

            $this->persistToolCall(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                seenToolCallIds: $seenToolCallIds
            );
        }

        foreach ($toolResults as $toolResult) {
            if (! $toolResult instanceof ToolResult) {
                continue;
            }

            $this->persistToolResult(
                assistantMessage: $assistantMessage,
                toolResult: $toolResult,
                seenToolResultCallIds: $seenToolResultCallIds,
            );
        }
    }
}
