<?php

namespace App\DataTransferObjects\Llm;

final readonly class ScheduleEventsAndProjectsDto
{
    /**
     * @param  array<int, array{title:string,start_datetime?:string|null,end_datetime?:string|null}>  $scheduledEvents
     * @param  array<int, array{name:string,start_datetime?:string|null,end_datetime?:string|null}>  $scheduledProjects
     */
    public function __construct(
        public array $scheduledEvents,
        public array $scheduledProjects,
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

        $scheduledEvents = $structured['scheduled_events'] ?? [];
        $scheduledProjects = $structured['scheduled_projects'] ?? [];
        if (! is_array($scheduledEvents) || ! is_array($scheduledProjects)) {
            return null;
        }

        $normalizedEvents = [];
        foreach ($scheduledEvents as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalizedEvents[] = [
                'title' => $title,
                'start_datetime' => isset($item['start_datetime']) && is_string($item['start_datetime']) ? $item['start_datetime'] : null,
                'end_datetime' => isset($item['end_datetime']) && is_string($item['end_datetime']) ? $item['end_datetime'] : null,
            ];
        }

        $normalizedProjects = [];
        foreach ($scheduledProjects as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalizedProjects[] = [
                'name' => $name,
                'start_datetime' => isset($item['start_datetime']) && is_string($item['start_datetime']) ? $item['start_datetime'] : null,
                'end_datetime' => isset($item['end_datetime']) && is_string($item['end_datetime']) ? $item['end_datetime'] : null,
            ];
        }

        return new self(
            scheduledEvents: $normalizedEvents,
            scheduledProjects: $normalizedProjects,
            reasoning: $reasoning,
        );
    }
}
