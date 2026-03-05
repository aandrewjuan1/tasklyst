<?php

namespace App\DataTransferObjects\Llm;

final readonly class ScheduleTasksAndEventsDto
{
    /**
     * @param  array<int, array{title:string,start_datetime?:string|null,end_datetime?:string|null,sessions?:array<int, array{start_datetime?:string,end_datetime?:string}>|null}>  $scheduledTasks
     * @param  array<int, array{title:string,start_datetime?:string|null,end_datetime?:string|null}>  $scheduledEvents
     */
    public function __construct(
        public array $scheduledTasks,
        public array $scheduledEvents,
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

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        $scheduledEvents = $structured['scheduled_events'] ?? [];
        if (! is_array($scheduledTasks) || ! is_array($scheduledEvents)) {
            return null;
        }

        $normalizedTasks = [];
        foreach ($scheduledTasks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $sessions = null;
            if (isset($item['sessions']) && is_array($item['sessions'])) {
                $sessions = [];
                foreach ($item['sessions'] as $s) {
                    if (is_array($s)) {
                        $sessions[] = [
                            'start_datetime' => $s['start_datetime'] ?? '',
                            'end_datetime' => $s['end_datetime'] ?? '',
                        ];
                    }
                }
            }
            $normalizedTasks[] = [
                'title' => $title,
                'start_datetime' => isset($item['start_datetime']) && is_string($item['start_datetime']) ? $item['start_datetime'] : null,
                'end_datetime' => isset($item['end_datetime']) && is_string($item['end_datetime']) ? $item['end_datetime'] : null,
                'sessions' => $sessions,
            ];
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

        return new self(
            scheduledTasks: $normalizedTasks,
            scheduledEvents: $normalizedEvents,
            reasoning: $reasoning,
        );
    }
}
