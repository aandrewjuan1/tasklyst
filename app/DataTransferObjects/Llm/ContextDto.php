<?php

namespace App\DataTransferObjects\Llm;

final class ContextDto
{
    /**
     * @param  list<TaskContextItem>  $tasks
     * @param  list<EventContextItem>  $events
     * @param  list<ConversationTurn>  $recentMessages
     * @param  array<string, mixed>  $userPreferences
     */
    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly array $tasks,
        public readonly array $events,
        public readonly array $recentMessages,
        public readonly array $userPreferences = [],
        public readonly ?string $fingerprint = null,
        public readonly bool $isSummaryMode = false,
    ) {}

    /**
     * @return array<int, int>
     */
    public function taskIds(): array
    {
        return array_map(fn (TaskContextItem $t) => $t->id, $this->tasks);
    }
}
