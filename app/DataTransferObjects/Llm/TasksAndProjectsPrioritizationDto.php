<?php

namespace App\DataTransferObjects\Llm;

final readonly class TasksAndProjectsPrioritizationDto
{
    /**
     * @param  array<int, array{rank:int,title:string,end_datetime?:string|null}>  $rankedTasks
     * @param  array<int, array{rank:int,name:string,end_datetime?:string|null}>  $rankedProjects
     */
    public function __construct(
        public array $rankedTasks,
        public array $rankedProjects,
        public string $reasoning
    ) {}

    /**
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        $rankedTasks = $structured['ranked_tasks'] ?? [];
        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (! is_array($rankedTasks) || ! is_array($rankedProjects)) {
            return null;
        }

        $normalizedTasks = [];
        foreach ($rankedTasks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rank = isset($item['rank']) && is_numeric($item['rank']) ? (int) $item['rank'] : 0;
            $title = trim((string) ($item['title'] ?? ''));
            if ($rank <= 0 || $title === '') {
                continue;
            }
            $normalizedTasks[] = [
                'rank' => $rank,
                'title' => $title,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        $normalizedProjects = [];
        foreach ($rankedProjects as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rank = isset($item['rank']) && is_numeric($item['rank']) ? (int) $item['rank'] : 0;
            $name = trim((string) ($item['name'] ?? ''));
            if ($rank <= 0 || $name === '') {
                continue;
            }
            $normalizedProjects[] = [
                'rank' => $rank,
                'name' => $name,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        return new self(
            rankedTasks: $normalizedTasks,
            rankedProjects: $normalizedProjects,
            reasoning: $reasoning,
        );
    }
}
