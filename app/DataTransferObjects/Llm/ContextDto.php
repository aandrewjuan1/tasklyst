<?php

namespace App\DataTransferObjects\Llm;

final class ContextDto
{
    /**
     * @param  list<TaskContextItem>  $tasks
     * @param  list<EventContextItem>  $events
     * @param  list<ConversationTurn>  $recentMessages
     * @param  array<string, mixed>  $userPreferences
     * @param  array<string, mixed>  $taskSummary
     * @param  list<ProjectContextItem>  $projects
     * @param  array<string, mixed>  $projectSummary
     */
    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly array $tasks,
        public readonly array $events,
        public readonly array $recentMessages,
        public readonly array $userPreferences = [],
        public readonly ?string $fingerprint = null,
        public readonly bool $isSummaryMode = false,
        public readonly array $taskSummary = [],
        public readonly array $projects = [],
        public readonly array $projectSummary = [],
        public readonly ?string $lastUserMessage = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function taskIds(): array
    {
        return array_map(fn (TaskContextItem $t) => $t->id, $this->tasks);
    }
}
