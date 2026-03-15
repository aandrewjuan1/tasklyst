<?php

namespace App\Services;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAssistantService
{
    private const MESSAGE_LIMIT = 50;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
    ) {}

    /**
     * Run a single request with tools and streaming; persist user and assistant messages.
     */
    public function streamResponse(TaskAssistantThread $thread, string $userMessageContent): StreamedResponse
    {
        $user = $thread->user;
        $userMessage = $thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $userMessageContent,
        ]);
        $assistantMessage = $thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $historyMessages = $this->loadHistoryMessages($thread, $userMessage->id);
        $prismMessages = $this->mapToPrismMessages($historyMessages);
        $prismMessages[] = new UserMessage($userMessageContent);
        $tools = $this->resolveTools($user);
        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 60);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        return Prism::text()
            ->using(Provider::Ollama, 'hermes3:3b')
            ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
            ->withMessages($prismMessages)
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withClientOptions(['timeout' => $timeout])
            ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use ($assistantMessage): void {
                try {
                    $fullText = $events
                        ->filter(fn ($e): bool => $e instanceof TextDeltaEvent)
                        ->map(fn (TextDeltaEvent $e): string => $e->delta)
                        ->join('');
                    $assistantMessage->update(['content' => $fullText]);
                } finally {
                    $this->clearTaskAssistantContext();
                }
            });
    }

    /**
     * Run the Prism stream and broadcast to Reverb; persist assistant message in callback.
     * Caller must have already created the user message and placeholder assistant message.
     */
    public function broadcastStream(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
    {
        Log::info('task-assistant.broadcastStream.start', [
            'thread_id' => $thread->id,
            'user_message_id' => $userMessageId,
            'assistant_message_id' => $assistantMessageId,
        ]);

        $userMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $userMessageId)
            ->first();
        $assistantMessage = TaskAssistantMessage::query()
            ->where('thread_id', $thread->id)
            ->where('id', $assistantMessageId)
            ->first();
        if (! $userMessage || ! $assistantMessage) {
            return;
        }

        $user = $thread->user;
        $historyMessages = $this->loadHistoryMessages($thread, $userMessageId);
        $prismMessages = $this->mapToPrismMessages($historyMessages);
        $prismMessages[] = new UserMessage($userMessage->content ?? '');
        $tools = $this->resolveTools($user);
        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);
        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);
        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 60);
        $channel = new Channel('task-assistant.user.'.$user->id);

        $this->bindTaskAssistantContext($thread->id, $assistantMessage->id);

        try {
            Log::info('task-assistant.broadcastStream.prism_start', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
                'channel' => $channel->name,
            ]);

            Prism::text()
                ->using(Provider::Ollama, 'hermes3:3b')
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($prismMessages)
                ->withTools($tools)
                ->withMaxSteps(3)
                ->withClientOptions(['timeout' => $timeout])
                ->asBroadcast($channel, function (PendingRequest $request, Collection $events) use ($assistantMessage): void {
                    try {
                        $fullText = $events
                            ->filter(fn ($e): bool => $e instanceof TextDeltaEvent)
                            ->map(fn (TextDeltaEvent $e): string => $e->delta)
                            ->join('');
                        Log::info('task-assistant.broadcastStream.prism_complete', [
                            'assistant_message_id' => $assistantMessage->id,
                            'content_length' => mb_strlen($fullText),
                        ]);

                        $assistantMessage->update(['content' => $fullText]);
                    } finally {
                        $this->clearTaskAssistantContext();
                    }
                });
        } finally {
            Log::info('task-assistant.broadcastStream.end', [
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessageId,
            ]);
            $this->clearTaskAssistantContext();
        }
    }

    /**
     * Load conversation history (messages before the given message id), bounded by MESSAGE_LIMIT.
     *
     * @return Collection<int, TaskAssistantMessage>
     */
    private function loadHistoryMessages(TaskAssistantThread $thread, int $beforeMessageId): Collection
    {
        return $thread->messages()
            ->where('id', '<', $beforeMessageId)
            ->orderBy('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->values();
    }

    /**
     * Map stored messages to Prism Message objects (UserMessage, AssistantMessage, ToolResultMessage).
     *
     * @param  Collection<int, TaskAssistantMessage>  $messages
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    private function mapToPrismMessages(Collection $messages): array
    {
        $out = [];
        $i = 0;
        $messagesCount = $messages->count();

        while ($i < $messagesCount) {
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
                    $toolResults[] = new ToolResult(
                        toolCallId: (string) ($meta['tool_call_id'] ?? ''),
                        toolName: (string) ($meta['tool_name'] ?? ''),
                        args: (array) ($meta['args'] ?? []),
                        result: $toolMsg->content
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
     * Build Prism ToolCall array from stored tool_calls (id, name, arguments).
     *
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
                // keep as string for Prism
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

    private function bindTaskAssistantContext(int $threadId, int $messageId): void
    {
        app()->instance('task_assistant.thread_id', $threadId);
        app()->instance('task_assistant.message_id', $messageId);
    }

    private function clearTaskAssistantContext(): void
    {
        app()->forgetInstance('task_assistant.thread_id');
        app()->forgetInstance('task_assistant.message_id');
    }

    /**
     * Resolve tool instances for the given user (same pattern as TaskAssistantPromptData).
     *
     * @return Tool[]
     */
    private function resolveTools(User $user): array
    {
        $tools = [];
        foreach (config('prism-tools', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $tools[] = app()->make($class, ['user' => $user]);
        }

        return $tools;
    }
}
