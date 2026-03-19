<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

final class TaskAssistantHistoryPrismMessageBuilder
{
    private const MESSAGE_LIMIT = 50;

    /**
     * Build Prism message history from stored assistant thread messages.
     *
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    public function build(TaskAssistantThread $thread, int $beforeMessageId): array
    {
        $messages = $thread->messages()
            ->where('id', '<', $beforeMessageId)
            ->orderBy('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->values();

        $out = [];
        $i = 0;
        $messagesCount = $messages->count();

        while ($i < $messagesCount) {
            /** @var TaskAssistantMessage $msg */
            $msg = $messages->get($i);

            if ($msg->role === MessageRole::User) {
                $out[] = new UserMessage($msg->content ?? '');
                $i++;

                continue;
            }

            if ($msg->role === MessageRole::Assistant) {
                $toolCalls = $this->parseToolCalls($msg->tool_calls);
                $out[] = new AssistantMessage($msg->content ?? '', $toolCalls);
                $i++;

                $toolResults = [];
                while ($i < $messagesCount && $messages->get($i)->role === MessageRole::Tool) {
                    $toolMsg = $messages->get($i);
                    $meta = $toolMsg->metadata ?? [];

                    $toolResult = $toolMsg->content;
                    if (is_string($toolResult)) {
                        $decoded = json_decode($toolResult, true);
                        $toolResult = is_array($decoded) ? $decoded : ['raw' => $toolResult];
                    }

                    if (! is_array($toolResult)) {
                        $toolResult = ['raw' => $toolResult];
                    }

                    $toolResults[] = new ToolResult(
                        toolCallId: (string) ($meta['tool_call_id'] ?? ''),
                        toolName: (string) ($meta['tool_name'] ?? ''),
                        args: (array) ($meta['args'] ?? []),
                        result: $toolResult
                    );

                    $i++;
                }

                if ($toolResults !== []) {
                    $out[] = new ToolResultMessage($toolResults);
                }

                continue;
            }

            if ($msg->role === MessageRole::System) {
                $i++;

                continue;
            }

            $i++;
        }

        return $out;
    }

    /**
     * @param  array<int, array{id?: string, name?: string, arguments?: string|array}>|null  $toolCalls
     * @return ToolCall[]
     */
    private function parseToolCalls(?array $toolCalls): array
    {
        if ($toolCalls === null || $toolCalls === []) {
            return [];
        }

        $result = [];
        foreach ($toolCalls as $tc) {
            $id = (string) ($tc['id'] ?? '');
            $name = (string) ($tc['name'] ?? '');
            $args = $tc['arguments'] ?? [];

            if (is_string($args)) {
                // Keep as string for Prism.
            } else {
                $args = is_array($args) ? $args : [];
            }

            $result[] = new ToolCall(
                id: $id,
                name: $name,
                arguments: $args,
                resultId: null,
                reasoningId: null,
                reasoningSummary: null
            );
        }

        return $result;
    }
}
