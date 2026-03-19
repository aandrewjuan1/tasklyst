<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskAssistantIntent;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

final class TaskAssistantRequestContext
{
    /** @var array<int, UserMessage|AssistantMessage|ToolResultMessage>|null */
    private ?array $historyPrismMessages = null;

    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /** @var array<string, mixed>|null */
    private ?array $userContext = null;

    /**
     * @param  array<int, UserMessage|AssistantMessage|ToolResultMessage>|null  $historyPrismMessages
     * @param  array<string, mixed>|null  $snapshot
     * @param  array<string, mixed>|null  $userContext
     */
    public function __construct(
        public readonly TaskAssistantThread $thread,
        public readonly TaskAssistantMessage $userMessage,
        public readonly TaskAssistantMessage $assistantMessage,
        public readonly User $user,
        public readonly string $userMessageContent,
        public readonly TaskAssistantIntent $intent,
        public readonly string $flow,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantContextAnalyzer $contextAnalyzer,
        private readonly TaskAssistantHistoryPrismMessageBuilder $historyBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        return $this->snapshot = $this->snapshotService->buildForUser($this->user);
    }

    /**
     * @return array<string, mixed>
     */
    public function userContext(): array
    {
        if ($this->userContext !== null) {
            return $this->userContext;
        }

        return $this->userContext = $this->contextAnalyzer->analyzeUserContext(
            $this->userMessageContent,
            $this->snapshot()
        );
    }

    /**
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    public function historyPrismMessages(): array
    {
        if ($this->historyPrismMessages !== null) {
            return $this->historyPrismMessages;
        }

        return $this->historyPrismMessages = $this->historyBuilder->build(
            $this->thread,
            $this->userMessage->id
        );
    }
}
