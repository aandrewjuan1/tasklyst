<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Enums\LlmToolCallStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Events\TaskAssistantToolCall;
use App\Events\TaskAssistantToolResult;
use App\Models\LlmToolCall;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcherService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

/**
 * Base for task-assistant tools that delegate to app Actions.
 * Tools must validate/sanitize params (e.g. cast numeric ids to int, trim strings) and delegate to actions for business rules.
 */
abstract class DelegatingTool extends Tool
{
    /** @var callable */
    protected $action;

    public function __construct(protected readonly User $user) {}

    /**
     * @param  array<string, mixed>  $params
     */
    protected function runDelegatedAction(array $params, string $toolName, ?string $operationToken = null): string
    {
        $operationToken ??= $this->buildDefaultOperationToken($toolName, $params);

        if ($operationToken !== null && $operationToken !== '') {
            $existing = LlmToolCall::query()
                ->where('operation_token', $operationToken)
                ->where('tool_name', $toolName)
                ->where('user_id', $this->user->id)
                ->first();

            if ($existing && $existing->status === LlmToolCallStatus::Success) {
                return json_encode($existing->result_json ?? ['ok' => true, 'message' => 'Already executed']);
            }
        }

        $threadId = app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null;
        $messageId = app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null;

        $call = LlmToolCall::create([
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_name' => $toolName,
            'params_json' => $params,
            'status' => LlmToolCallStatus::Pending,
            'operation_token' => $operationToken,
            'user_id' => $this->user->id,
        ]);

        Log::info('task-assistant.tool_call', [
            'layer' => 'tool',
            'thread_id' => $threadId,
            'operation_token' => $operationToken,
            'tool_name' => $toolName,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        broadcast(new TaskAssistantToolCall(
            userId: $this->user->id,
            toolCallId: (string) $call->id,
            toolName: $toolName,
            arguments: []
        ));

        try {
            $result = ($this->action)($params);
            $call->update([
                'result_json' => $result,
                'status' => LlmToolCallStatus::Success,
            ]);

            Log::info('task-assistant.tool_call', [
                'layer' => 'tool',
                'thread_id' => $threadId,
                'operation_token' => $operationToken,
                'tool_name' => $toolName,
                'user_id' => $this->user->id,
                'status' => 'success',
            ]);

            broadcast(new TaskAssistantToolResult(
                userId: $this->user->id,
                toolCallId: (string) $call->id,
                toolName: $toolName,
                result: is_string($result) ? $result : json_encode($result),
                success: true
            ));

            return json_encode($result);
        } catch (\Throwable $e) {
            $call->update([
                'result_json' => ['ok' => false, 'message' => 'Tool execution failed', 'error' => $e->getMessage()],
                'status' => LlmToolCallStatus::Failed,
            ]);

            Log::info('task-assistant.tool_call', [
                'layer' => 'tool',
                'thread_id' => $threadId,
                'operation_token' => $operationToken,
                'tool_name' => $toolName,
                'user_id' => $this->user->id,
                'status' => 'failed',
            ]);

            $existingReminder = $operationToken !== null && $operationToken !== ''
                ? Reminder::query()
                    ->where('user_id', $this->user->id)
                    ->where('type', ReminderType::AssistantToolCallFailed->value)
                    ->where('payload->operation_token', $operationToken)
                    ->exists()
                : false;

            if (! $existingReminder) {
                Reminder::query()->create([
                    'user_id' => $this->user->id,
                    'remindable_type' => $call->getMorphClass(),
                    'remindable_id' => $call->id,
                    'type' => ReminderType::AssistantToolCallFailed,
                    'scheduled_at' => now(),
                    'status' => ReminderStatus::Pending,
                    'payload' => [
                        'tool_call_id' => $call->id,
                        'tool_name' => $toolName,
                        'operation_token' => $operationToken,
                        'thread_id' => $threadId,
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ],
                ]);

                app(ReminderDispatcherService::class)->queueProcessDueForRemindable($call);
            }

            broadcast(new TaskAssistantToolResult(
                userId: $this->user->id,
                toolCallId: (string) $call->id,
                toolName: $toolName,
                result: '',
                success: false,
                error: $e->getMessage()
            ));

            return json_encode([
                'ok' => false,
                'message' => 'Tool execution failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildDefaultOperationToken(string $toolName, array $params): string
    {
        $threadId = app()->bound('task_assistant.thread_id') ? (string) app('task_assistant.thread_id') : 'none';
        $messageId = app()->bound('task_assistant.message_id') ? (string) app('task_assistant.message_id') : 'none';
        $payload = $toolName.'|'.$this->user->id.'|'.$threadId.'|'.$messageId.'|'.json_encode($params);

        return hash('sha256', $payload);
    }

    /**
     * Normalize Prism tool call args (associative array or list) to one params array.
     *
     * @return array<string, mixed>
     */
    protected function normalizeParams(mixed ...$args): array
    {
        if (count($args) === 1 && is_array($args[0]) && ! array_is_list($args[0])) {
            return $args[0];
        }

        if (count($args) === 1 && is_array($args[0])) {
            return $args[0];
        }

        $params = [];
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $params[$key] = $value;
        }

        return $params;
    }
}
