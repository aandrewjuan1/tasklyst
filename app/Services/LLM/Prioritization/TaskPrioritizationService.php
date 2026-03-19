<?php

namespace App\Services\LLM\Prioritization;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class TaskPrioritizationService
{
    /**
     * Build one ranked list of "focus candidates" across tasks, events, and projects.
     *
     * @param  array<string, mixed>  $snapshot  Expected keys: tasks, events, projects, today, timezone
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: 'task'|'event'|'project', id: int, title: string, score: int, reasoning: string, raw: array<string, mixed>}>
     */
    public function prioritizeFocus(array $snapshot, array $context = []): array
    {
        $today = (string) ($snapshot['today'] ?? date('Y-m-d'));
        $now = new \DateTimeImmutable($today.'T00:00:00');

        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
        $projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];

        $taskRanked = $this->prioritizeTasks($tasks, $today, $context);
        $eventRanked = $this->prioritizeEvents($events, $now, $context);
        $projectRanked = $this->prioritizeProjects($projects, $now, $context);

        $candidates = [];

        foreach (array_slice($taskRanked, 0, 10) as $task) {
            $id = (int) ($task['id'] ?? 0);
            $title = (string) ($task['title'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }
            $score = (int) (($task['deadline_score'] ?? 0) + ($task['priority_score'] ?? 0) + ($task['duration_score'] ?? 0));
            $candidates[] = [
                'type' => 'task',
                'id' => $id,
                'title' => $title,
                'score' => $score,
                'reasoning' => $this->generateReasoning($task, $today),
                'raw' => $task,
            ];
        }

        foreach (array_slice($eventRanked, 0, 10) as $event) {
            $id = (int) ($event['id'] ?? 0);
            $title = (string) ($event['title'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }
            $candidates[] = [
                'type' => 'event',
                'id' => $id,
                'title' => $title,
                'score' => (int) ($event['score'] ?? 0),
                'reasoning' => (string) ($event['reasoning'] ?? 'Upcoming event'),
                'raw' => $event,
            ];
        }

        foreach (array_slice($projectRanked, 0, 10) as $project) {
            $id = (int) ($project['id'] ?? 0);
            $title = (string) ($project['name'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }
            $candidates[] = [
                'type' => 'project',
                'id' => $id,
                'title' => $title,
                'score' => (int) ($project['score'] ?? 0),
                'reasoning' => (string) ($project['reasoning'] ?? 'Active project'),
                'raw' => $project,
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['type'] !== $b['type']) {
                return strcmp($a['type'], $b['type']);
            }

            return $a['id'] <=> $b['id'];
        });

        return $candidates;
    }

    /**
     * @return array{type: 'task'|'event'|'project', id: int, title: string, score: int, reasoning: string, raw: array<string, mixed>}|null
     */
    public function getTopFocus(array $snapshot, array $context = []): ?array
    {
        $ranked = $this->prioritizeFocus($snapshot, $context);

        return $ranked[0] ?? null;
    }

    /**
     * Prioritize tasks using deterministic rules for consistent selection.
     * Optional context filtering for user-aware prioritization.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $context  Optional context filters
     * @return array<int, array<string, mixed>>
     */
    public function prioritizeTasks(array $tasks, string $today, array $context = []): array
    {
        $collection = collect($tasks);

        // Apply context-aware filtering first
        $collection = $this->applyContextFilters($collection, $context);

        if ($collection->isEmpty()) {
            return [];
        }

        $todayDate = new \DateTime($today);

        return $collection
            ->map(function (array $task) use ($todayDate) {
                return $this->calculateTaskScore($task, $todayDate);
            })
            ->sortByDesc('priority_score')
            ->sortByDesc('deadline_score')
            ->sortBy('duration_minutes')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function prioritizeEvents(array $events, \DateTimeImmutable $now, array $context = []): array
    {
        $collection = collect($events);

        if (! empty($context['time_constraint']) && $context['time_constraint'] === 'today') {
            $collection = $collection->filter(function (array $event) use ($now): bool {
                $startsAt = $event['starts_at'] ?? null;
                if (! is_string($startsAt) || $startsAt === '') {
                    return false;
                }
                try {
                    $dt = new \DateTimeImmutable($startsAt);
                } catch (\Throwable) {
                    return false;
                }

                return $dt->format('Y-m-d') === $now->format('Y-m-d');
            });
        }

        return $collection
            ->map(function (array $event) use ($now): array {
                $score = 0;
                $reasoning = 'Upcoming event';

                $startsAt = $event['starts_at'] ?? null;
                $endsAt = $event['ends_at'] ?? null;
                $allDay = (bool) ($event['all_day'] ?? false);

                if (is_string($startsAt) && $startsAt !== '') {
                    try {
                        $start = new \DateTimeImmutable($startsAt);
                        $minutesUntil = (int) floor(($start->getTimestamp() - $now->getTimestamp()) / 60);

                        if ($minutesUntil < 0) {
                            $score += 900;
                            $reasoning = 'Event already started';
                        } elseif ($minutesUntil <= 60) {
                            $score += 850;
                            $reasoning = 'Event starts within 1 hour';
                        } elseif ($minutesUntil <= 180) {
                            $score += 750;
                            $reasoning = 'Event starts soon';
                        } elseif ($start->format('Y-m-d') === $now->format('Y-m-d')) {
                            $score += 650;
                            $reasoning = 'Event is today';
                        } else {
                            $score += 300;
                        }
                    } catch (\Throwable) {
                        $score += 100;
                    }
                }

                if ($allDay) {
                    $score += 100;
                    $reasoning = $reasoning === 'Upcoming event' ? 'All-day event' : $reasoning;
                }

                if (is_string($endsAt) && $endsAt !== '') {
                    try {
                        $end = new \DateTimeImmutable($endsAt);
                        if ($end < $now) {
                            $score -= 300;
                        }
                    } catch (\Throwable) {
                    }
                }

                $event['score'] = $score;
                $event['reasoning'] = $reasoning;

                return $event;
            })
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function prioritizeProjects(array $projects, \DateTimeImmutable $now, array $context = []): array
    {
        $collection = collect($projects);

        if (! empty($context['task_keywords'])) {
            $collection = $collection->filter(function (array $project) use ($context): bool {
                $name = strtolower((string) ($project['name'] ?? ''));
                foreach ($context['task_keywords'] as $keyword) {
                    if (str_contains($name, strtolower((string) $keyword))) {
                        return true;
                    }
                }

                return false;
            });
        }

        return $collection
            ->map(function (array $project) use ($now): array {
                $score = 250;
                $reasoning = 'Active project';

                $endAt = $project['end_at'] ?? null;
                if (is_string($endAt) && $endAt !== '') {
                    try {
                        $end = new \DateTimeImmutable($endAt);
                        $daysUntil = (int) floor(($end->getTimestamp() - $now->getTimestamp()) / 86400);
                        if ($end < $now) {
                            $score += 700;
                            $reasoning = 'Project is overdue';
                        } elseif ($daysUntil === 0) {
                            $score += 650;
                            $reasoning = 'Project ends today';
                        } elseif ($daysUntil <= 7) {
                            $score += 600 - ($daysUntil * 50);
                            $reasoning = 'Project ends soon';
                        } else {
                            $score += max(50, 400 - ($daysUntil * 10));
                            $reasoning = 'Project has an upcoming end date';
                        }
                    } catch (\Throwable) {
                        $score += 50;
                    }
                }

                $project['score'] = $score;
                $project['reasoning'] = $reasoning;

                return $project;
            })
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * Get the top priority task with detailed reasoning.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $context  Optional context filters
     * @return array<string, mixed>|null
     */
    public function getTopTask(array $tasks, string $today, array $context = []): ?array
    {
        $prioritized = $this->prioritizeTasks($tasks, $today, $context);

        if (empty($prioritized)) {
            return null;
        }

        $topTask = $prioritized[0];

        // Add reasoning for logging
        $topTask['reasoning'] = $this->generateReasoning($topTask, $today);

        return $topTask;
    }

    /**
     * Apply context-aware filters to task collection.
     */
    private function applyContextFilters(Collection $tasks, array $context): Collection
    {
        // Priority level filtering
        if (! empty($context['priority_filters'])) {
            $tasks = $tasks->filter(function (array $task) use ($context) {
                return in_array($task['priority'] ?? 'medium', $context['priority_filters']);
            });
        }

        // Task keyword filtering
        if (! empty($context['task_keywords'])) {
            $tasks = $tasks->filter(function (array $task) use ($context) {
                $title = strtolower($task['title'] ?? '');
                foreach ($context['task_keywords'] as $keyword) {
                    if (str_contains($title, strtolower($keyword))) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Time constraint filtering
        if (! empty($context['time_constraint'])) {
            $tasks = $this->applyTimeConstraintFilter($tasks, $context['time_constraint']);
        }

        return $tasks;
    }

    /**
     * Apply time-based filtering.
     */
    private function applyTimeConstraintFilter(Collection $tasks, string $constraint): Collection
    {
        $today = new \DateTime;

        return match ($constraint) {
            'today' => $tasks->filter(function (array $task) use ($today) {
                if (! isset($task['ends_at']) || $task['ends_at'] === null) {
                    return false;
                }
                try {
                    $deadline = new \DateTime($task['ends_at']);

                    return $deadline->format('Y-m-d') === $today->format('Y-m-d');
                } catch (\Exception $e) {
                    return false;
                }
            }),
            'this_week' => $tasks->filter(function (array $task) use ($today) {
                if (! isset($task['ends_at']) || $task['ends_at'] === null) {
                    return false;
                }
                try {
                    $deadline = new \DateTime($task['ends_at']);
                    $weekEnd = (clone $today)->modify('+6 days');

                    return $deadline <= $weekEnd;
                } catch (\Exception $e) {
                    return false;
                }
            }),
            default => $tasks,
        };
    }

    /**
     * Calculate comprehensive priority score for a task.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function calculateTaskScore(array $task, \DateTime $today): array
    {
        $task['deadline_score'] = $this->calculateDeadlineScore($task, $today);
        $task['priority_score'] = $this->calculatePriorityScore($task);
        $task['duration_score'] = $this->calculateDurationScore($task);

        return $task;
    }

    /**
     * Calculate deadline score based on urgency.
     * Higher score = more urgent
     */
    private function calculateDeadlineScore(array $task, \DateTime $today): int
    {
        if (! isset($task['ends_at']) || $task['ends_at'] === null) {
            return 0; // No deadline = lowest urgency
        }

        try {
            $deadline = new \DateTime($task['ends_at']);
            $interval = $today->diff($deadline);
            $daysUntil = $interval->days;

            // Scoring system:
            if ($deadline < $today) {
                return 1000; // Overdue - highest priority
            }

            if ($daysUntil === 0) {
                return 900; // Due today - very high priority
            }

            if ($daysUntil === 1) {
                return 800; // Due tomorrow - high priority
            }

            if ($daysUntil <= 7) {
                return 700 - ($daysUntil * 50); // This week - medium-high priority
            }

            return max(100, 600 - ($daysUntil * 20)); // Future - lower priority
        } catch (\Exception $e) {
            Log::warning('task-prioritization.invalid_date', [
                'task_id' => $task['id'] ?? null,
                'ends_at' => $task['ends_at'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Calculate priority score based on task priority level.
     */
    private function calculatePriorityScore(array $task): int
    {
        $priority = $task['priority'] ?? 'medium';

        return match ($priority) {
            'urgent' => 100,
            'high' => 80,
            'medium' => 60,
            'low' => 40,
            default => 50,
        };
    }

    /**
     * Calculate duration score (shorter tasks get slightly higher score).
     */
    private function calculateDurationScore(array $task): int
    {
        $duration = $task['duration_minutes'] ?? 0;

        if ($duration === 0) {
            return 50; // Unknown duration
        }

        // Shorter tasks get slightly higher score (quick wins)
        if ($duration <= 30) {
            return 90;
        }

        if ($duration <= 60) {
            return 80;
        }

        if ($duration <= 120) {
            return 70;
        }

        if ($duration <= 240) {
            return 60;
        }

        return 50; // Long tasks
    }

    /**
     * Generate human-readable reasoning for task selection.
     */
    private function generateReasoning(array $task, string $today): string
    {
        $priority = ucfirst($task['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($task['ends_at']) && $task['ends_at']) {
            try {
                $deadline = new \DateTime($task['ends_at']);
                $todayDate = new \DateTime($today);
                $interval = $todayDate->diff($deadline);

                if ($deadline < $todayDate) {
                    $deadlineText = 'overdue';
                } elseif ($interval->days === 0) {
                    $deadlineText = 'due today';
                } elseif ($interval->days === 1) {
                    $deadlineText = 'due tomorrow';
                } elseif ($interval->days <= 7) {
                    $deadlineText = 'due this week';
                } else {
                    $deadlineText = 'due later';
                }
            } catch (\Exception $e) {
                $deadlineText = 'with unknown deadline';
            }
        } else {
            $deadlineText = 'with no deadline';
        }

        return "Selected as {$priority} priority task {$deadlineText}";
    }
}
