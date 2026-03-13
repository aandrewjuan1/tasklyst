<?php

namespace App\Services\Llm;

use App\Actions\Llm\CreateEventFromLlmAction;
use App\Actions\Llm\CreateTaskFromLlmAction;
use App\Actions\Llm\UpdateTaskFromLlmAction;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Enums\ToolCallStatus;
use App\Exceptions\Llm\ToolExecutionException;
use App\Exceptions\Llm\UnknownEntityException;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ToolExecutorService
{
    public function __construct(
        private readonly CreateTaskFromLlmAction $createTask,
        private readonly UpdateTaskFromLlmAction $updateTask,
        private readonly CreateEventFromLlmAction $createEvent,
    ) {}

    public function execute(ToolCallDto $toolCall, User $user): ToolResultDto
    {
        if (! in_array($toolCall->tool, config('llm.allowed_tools'), true)) {
            throw new ToolExecutionException(
                "Tool [{$toolCall->tool}] is not whitelisted.",
                $toolCall->tool,
            );
        }

        if ($existing = LlmToolCall::findByRequestId($toolCall->clientRequestId)) {
            return ToolResultDto::fromStoredPayload($existing->tool_result_payload);
        }

        if (in_array($toolCall->tool, ['update_task', 'create_event'], true)) {
            $rawId = $toolCall->args['id'] ?? null;
            $numericId = (int) str_replace('task_', '', (string) $rawId);
            $task = Task::find($numericId)
                ?? throw new UnknownEntityException('task', $rawId ?? 'null');

            Gate::authorize('executeLlmTool', $task);
        }

        /** @var ToolResultDto $result */
        $result = DB::transaction(function () use ($toolCall, $user): ToolResultDto {
            $toolResult = match ($toolCall->tool) {
                'create_task' => ($this->createTask)($toolCall->args, $user),
                'update_task' => ($this->updateTask)($toolCall->args, $user),
                'create_event' => ($this->createEvent)($toolCall->args, $user),
                default => throw new ToolExecutionException(
                    "Unhandled tool: {$toolCall->tool}",
                    $toolCall->tool,
                ),
            };

            LlmToolCall::create([
                'client_request_id' => $toolCall->clientRequestId,
                'user_id' => $user->id,
                'thread_id' => $toolCall->args['thread_id'] ?? null,
                'tool' => $toolCall->tool,
                'args_hash' => md5(json_encode($toolCall->args)),
                'tool_result_payload' => $toolResult->toArray(),
                'status' => ToolCallStatus::Success,
            ]);

            return $toolResult;
        });

        return $result;
    }
}
