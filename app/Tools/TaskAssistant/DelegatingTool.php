<?php

namespace App\Tools\TaskAssistant;

use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\User;
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

        Log::info('task_assistant.tool_call', [
            'thread_id' => $threadId,
            'operation_token' => $operationToken,
            'tool_name' => $toolName,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        try {
            $result = ($this->action)($params);
            $call->update([
                'result_json' => $result,
                'status' => LlmToolCallStatus::Success,
            ]);

            Log::info('task_assistant.tool_call', [
                'thread_id' => $threadId,
                'operation_token' => $operationToken,
                'tool_name' => $toolName,
                'user_id' => $this->user->id,
                'status' => 'success',
            ]);

            return json_encode($result);
        } catch (\Throwable $e) {
            $call->update([
                'result_json' => ['ok' => false, 'message' => 'Tool execution failed', 'error' => $e->getMessage()],
                'status' => LlmToolCallStatus::Failed,
            ]);

            Log::info('task_assistant.tool_call', [
                'thread_id' => $threadId,
                'operation_token' => $operationToken,
                'tool_name' => $toolName,
                'user_id' => $this->user->id,
                'status' => 'failed',
            ]);

            return json_encode([
                'ok' => false,
                'message' => 'Tool execution failed',
                'error' => $e->getMessage(),
            ]);
        }
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
