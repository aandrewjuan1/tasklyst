<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\MessageRole;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Intent\IntentClassificationService;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

final class TaskAssistantService
{
    private const MESSAGE_LIMIT = 50;

    private const STREAM_CHUNK_SIZE = 200;

    /** @var string[] */
    private const READ_ONLY_TOOL_KEYS = ['list_tasks'];

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantStructuredFlowGenerator $structuredFlowGenerator,
        private readonly TaskAssistantFlowExecutionEngine $flowExecutionEngine,
        private readonly TaskAssistantStreamingBroadcaster $streamingBroadcaster,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskAssistantConversationStateService $conversationState,
        private readonly IntentClassificationService $intentClassifier,
    ) {}

    public function processQueuedMessage(TaskAssistantThread $thread, int $userMessageId, int $assistantMessageId): void
    {
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

        $content = (string) ($userMessage->content ?? '');
        $route = $this->resolveRoute($content);

        if ($route === 'prioritize') {
            $this->runPrioritizeFlow($thread, $assistantMessage, $content);

            return;
        }

        if ($route === 'schedule') {
            $this->runScheduleFlow($thread, $userMessage, $assistantMessage, $content);

            return;
        }

        $this->runChatFlow($thread, $userMessage, $assistantMessage, $content);
    }

    private function resolveRoute(string $content): string
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            return 'chat';
        }

        if ($this->intentClassifier->isScheduleLikeRequest($normalized)) {
            return 'schedule';
        }

        if ($this->isPrioritizeRequest($normalized)) {
            return 'prioritize';
        }

        return 'chat';
    }

    private function isPrioritizeRequest(string $content): bool
    {
        return preg_match('/\b(top|priorit|first|next|important|focus|list.*task|show.*task|which task)\b/i', $content) === 1;
    }

    private function runPrioritizeFlow(TaskAssistantThread $thread, TaskAssistantMessage $assistantMessage, string $content): void
    {
        $snapshot = $this->snapshotService->buildForUser($thread->user, 100);
        $context = $this->constraintsExtractor->extract($content);
        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $limit = $this->extractRequestedCount($content, 3);
        $items = array_slice($ranked, 0, $limit);

        $selected = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'task');
            $id = (int) ($item['id'] ?? 0);
            $title = (string) ($item['title'] ?? 'Untitled');
            if ($id <= 0) {
                continue;
            }
            $reason = trim((string) ($item['reasoning'] ?? 'High relevance based on deadline and priority.'));
            $selected[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'reason' => $reason,
            ];
        }

        $prioritizeData = [
            'summary' => 'Here are your top '.max(1, $limit).' priorities:',
            'items' => $selected,
            'limit_used' => count($selected),
        ];
        $generationResult = [
            'valid' => $selected !== [],
            'data' => $prioritizeData,
            'errors' => [],
        ];

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'prioritize',
            metadataKey: 'prioritize',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $generationResult,
            originalUserMessage: $content,
            assistantFallbackContent: 'I could not prioritize your items yet. Please ask me to list your top tasks again.'
        );

        if ($selected !== []) {
            $selectedForState = array_map(static fn (array $entity): array => [
                'entity_type' => (string) ($entity['entity_type'] ?? ''),
                'entity_id' => (int) ($entity['entity_id'] ?? 0),
                'title' => (string) ($entity['title'] ?? ''),
            ], $selected);
            $this->conversationState->rememberPrioritizedItems($thread, $selectedForState, count($selectedForState));
        }

        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'prioritize',
            execution: $execution
        );
    }

    private function runScheduleFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content
    ): void {
        $historyMessages = collect($this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id)));
        $selected = $this->conversationState->selectedEntities($thread);
        $scheduleTargets = $this->resolveScheduleTargets($content, $selected);
        $timeWindowHint = $this->extractTimeWindowHint($content);

        $result = $this->structuredFlowGenerator->generateDailySchedule(
            thread: $thread,
            userMessageContent: $content,
            historyMessages: $historyMessages,
            tools: $this->resolveReadOnlyTools($thread->user),
            options: [
                'target_entities' => $scheduleTargets,
                'time_window_hint' => $timeWindowHint,
            ]
        );

        $execution = $this->flowExecutionEngine->executeStructuredFlow(
            flow: 'daily_schedule',
            metadataKey: 'schedule',
            thread: $thread,
            assistantMessage: $assistantMessage,
            generationResult: $result,
            originalUserMessage: $content,
            assistantFallbackContent: 'I had trouble scheduling these items. Please try again with more details.'
        );

        $this->conversationState->rememberScheduleContext($thread, $scheduleTargets, $timeWindowHint);
        $this->streamFlowEnvelope(
            thread: $thread,
            assistantMessage: $assistantMessage,
            flow: 'schedule',
            execution: $execution
        );
    }

    private function runChatFlow(
        TaskAssistantThread $thread,
        TaskAssistantMessage $userMessage,
        TaskAssistantMessage $assistantMessage,
        string $content
    ): void {
        $historyMessages = $this->mapToPrismMessages($this->loadHistoryMessages($thread, $userMessage->id));
        $historyMessages[] = new UserMessage($content);
        $promptData = $this->promptData->forUser($thread->user);
        $promptData['snapshot'] = $this->snapshotService->buildForUser($thread->user);
        $promptData['toolManifest'] = $this->buildToolManifestFromTools($this->resolveReadOnlyTools($thread->user));

        $text = Prism::text()
            ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
            ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
            ->withMessages($historyMessages)
            ->withTools($this->resolveReadOnlyTools($thread->user))
            ->withMaxSteps(3)
            ->withClientOptions(['timeout' => (int) config('prism.request_timeout', 120)])
            ->asText();

        $assistantMessage->update([
            'content' => (string) ($text->text ?? 'How can I help with prioritizing or scheduling your tasks?'),
        ]);

        $this->streamFinalAssistantJson($thread->user_id, $assistantMessage, $this->buildJsonEnvelope(
            flow: 'chat',
            data: ['message' => (string) $assistantMessage->content],
            threadId: $thread->id,
            assistantMessageId: $assistantMessage->id,
            ok: true,
        ));
    }

    private function extractRequestedCount(string $content, int $default = 3): int
    {
        $normalized = mb_strtolower($content);
        if (preg_match('/\b(top|first|only|limit)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? $default), 10));
        }

        return $default;
    }

    private function extractTimeWindowHint(string $content): ?string
    {
        $normalized = mb_strtolower($content);
        if (str_contains($normalized, 'later afternoon') || str_contains($normalized, 'afternoon')) {
            return 'later_afternoon';
        }
        if (str_contains($normalized, 'morning')) {
            return 'morning';
        }
        if (str_contains($normalized, 'evening') || str_contains($normalized, 'night')) {
            return 'evening';
        }

        return null;
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $selected
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function resolveScheduleTargets(string $content, array $selected): array
    {
        if (preg_match('/\b(those|them|those\s+\d+|the\s+above)\b/i', $content) === 1) {
            return $selected;
        }

        return [];
    }

    /**
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
     * @param  Collection<int, TaskAssistantMessage>  $messages
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function mapToPrismMessages(Collection $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if ($msg->role === MessageRole::User) {
                $out[] = new UserMessage($msg->content ?? '');

                continue;
            }
            if ($msg->role === MessageRole::Assistant) {
                $out[] = new AssistantMessage($msg->content ?? '');
            }
        }

        return $out;
    }

    /**
     * @return Tool[]
     */
    private function resolveReadOnlyTools(User $user): array
    {
        $tools = [];
        $config = config('prism-tools', []);
        foreach (self::READ_ONLY_TOOL_KEYS as $key) {
            $class = $config[$key] ?? null;
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $tools[] = app()->make($class, ['user' => $user]);
        }

        return $tools;
    }

    /**
     * @param  Tool[]  $tools
     * @return list<array{name: string, description: string}>
     */
    private function buildToolManifestFromTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];
        }

        return $out;
    }

    private function buildJsonEnvelope(string $flow, array $data, int $threadId, int $assistantMessageId, bool $ok = true): array
    {
        return [
            'type' => 'task_assistant',
            'ok' => $ok,
            'flow' => $flow,
            'data' => $data,
            'meta' => [
                'thread_id' => $threadId,
                'assistant_message_id' => $assistantMessageId,
            ],
        ];
    }

    private function streamFinalAssistantJson(int $userId, TaskAssistantMessage $assistantMessage, array $envelope): void
    {
        $this->streamingBroadcaster->streamFinalAssistantJson(
            userId: $userId,
            assistantMessage: $assistantMessage,
            envelope: $envelope,
            chunkSize: self::STREAM_CHUNK_SIZE
        );
    }

    /**
     * @param  array{structured_data?: array<string, mixed>, final_valid?: bool}  $execution
     */
    private function streamFlowEnvelope(
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        string $flow,
        array $execution
    ): void {
        $this->streamFinalAssistantJson(
            $thread->user_id,
            $assistantMessage,
            $this->buildJsonEnvelope(
                flow: $flow,
                data: is_array($execution['structured_data'] ?? null) ? $execution['structured_data'] : [],
                threadId: $thread->id,
                assistantMessageId: $assistantMessage->id,
                ok: (bool) ($execution['final_valid'] ?? false),
            )
        );
    }
}
