<?php

namespace App\DataTransferObjects\Llm;

final readonly class EventsAndProjectsPrioritizationDto
{
    /**
     * @param  array<int, array{rank:int,title:string,start_datetime?:string|null,end_datetime?:string|null}>  $rankedEvents
     * @param  array<int, array{rank:int,name:string,end_datetime?:string|null}>  $rankedProjects
     */
    public function __construct(
        public array $rankedEvents,
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

        $rankedEvents = $structured['ranked_events'] ?? [];
        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (! is_array($rankedEvents) || ! is_array($rankedProjects)) {
            return null;
        }

        $normalizedEvents = [];
        foreach ($rankedEvents as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rank = isset($item['rank']) && is_numeric($item['rank']) ? (int) $item['rank'] : 0;
            $title = trim((string) ($item['title'] ?? ''));
            if ($rank <= 0 || $title === '') {
                continue;
            }
            $normalizedEvents[] = [
                'rank' => $rank,
                'title' => $title,
                'start_datetime' => $item['start_datetime'] ?? null,
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
            rankedEvents: $normalizedEvents,
            rankedProjects: $normalizedProjects,
            reasoning: $reasoning,
        );
    }
}
