<?php

namespace App\Services\LLM\Prioritization;

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class TaskPrioritizationService
{
    private const TASK_DOING_BOOST = 75;

    private const EVENT_ONGOING_BOOST = 50;

    /**
     * Build one ranked list of "focus candidates" across tasks, events, and projects.
     *
     * @param  array<string, mixed>  $snapshot  Expected keys: tasks, events, projects, today, timezone
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: 'task'|'event'|'project', id: int, title: string, score: int, reasoning: string, raw: array<string, mixed>}>
     */
    public function prioritizeFocus(array $snapshot, array $context = []): array
    {
        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $now = CarbonImmutable::now($timezone);
        $today = $now->toDateString();

        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
        $projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];

        $taskRanked = $this->prioritizeTasks($tasks, $now, $context);
        $eventRanked = $this->prioritizeEvents($events, $now, $context);
        $projectRanked = $this->prioritizeProjects($projects, $now, $context);

        $candidates = [];

        foreach ($taskRanked as $task) {
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
                'reasoning' => $this->generateReasoning($task, $now),
                'raw' => $task,
            ];
        }

        foreach ($eventRanked as $event) {
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

        foreach ($projectRanked as $project) {
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
                $typeOrder = [
                    'task' => 3,
                    'event' => 2,
                    'project' => 1,
                ];

                $aRank = (int) ($typeOrder[$a['type']] ?? 0);
                $bRank = (int) ($typeOrder[$b['type']] ?? 0);

                if ($aRank !== $bRank) {
                    return $bRank <=> $aRank;
                }

                return $a['id'] <=> $b['id'];
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
        $top = $ranked[0] ?? null;

        if ($top) {
            return $top;
        }

        // Fallback: if cross-type candidate building ended up with an empty list,
        // still choose deterministically from the available task/event/project sets.
        // This prevents "no tasks available" surprises in production + tests.
        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $now = CarbonImmutable::now($timezone);

        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        $taskRanked = $this->prioritizeTasks($tasks, $now, $context);
        if (! empty($taskRanked[0] ?? null)) {
            $task = $taskRanked[0];

            return [
                'type' => 'task',
                'id' => (int) ($task['id'] ?? 0),
                'title' => (string) ($task['title'] ?? ''),
                'score' => (int) (($task['deadline_score'] ?? 0) + ($task['priority_score'] ?? 0) + ($task['duration_score'] ?? 0)),
                'reasoning' => $this->generateReasoning($task, $now),
                'raw' => $task,
            ];
        }

        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];
        $eventRanked = $this->prioritizeEvents($events, $now, $context);
        if (! empty($eventRanked[0] ?? null)) {
            $event = $eventRanked[0];

            return [
                'type' => 'event',
                'id' => (int) ($event['id'] ?? 0),
                'title' => (string) ($event['title'] ?? ''),
                'score' => (int) ($event['score'] ?? 0),
                'reasoning' => (string) ($event['reasoning'] ?? 'Upcoming event'),
                'raw' => $event,
            ];
        }

        $projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];
        $projectRanked = $this->prioritizeProjects($projects, $now, $context);
        if (! empty($projectRanked[0] ?? null)) {
            $project = $projectRanked[0];

            return [
                'type' => 'project',
                'id' => (int) ($project['id'] ?? 0),
                'title' => (string) ($project['name'] ?? ''),
                'score' => (int) ($project['score'] ?? 0),
                'reasoning' => (string) ($project['reasoning'] ?? 'Active project'),
                'raw' => $project,
            ];
        }

        return null;
    }

    /**
     * Prioritize tasks using deterministic rules for consistent selection.
     * Optional context filtering for user-aware prioritization.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $context  Optional context filters
     * @return array<int, array<string, mixed>>
     */
    public function prioritizeTasks(array $tasks, \DateTimeImmutable $now, array $context = []): array
    {
        $collection = collect($tasks);

        // Apply context-aware filtering first
        $collection = $this->applyContextFilters($collection, $context, $now);

        if ($collection->isEmpty()) {
            return [];
        }

        $todayDate = $now;

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

                if ($score > 0 && ($event['status'] ?? null) === EventStatus::Ongoing->value) {
                    // Momentum boost, but intentionally small.
                    $score += self::EVENT_ONGOING_BOOST;
                    if ($reasoning === 'Upcoming event') {
                        $reasoning = 'Ongoing event';
                    } else {
                        $reasoning .= ' (ongoing)';
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
        $timezone = config('app.timezone', 'UTC');
        $now = CarbonImmutable::createFromFormat('Y-m-d', $today, $timezone)->startOfDay();
        $prioritized = $this->prioritizeTasks($tasks, $now, $context);

        if (empty($prioritized)) {
            return null;
        }

        $topTask = $prioritized[0];

        // Add reasoning for logging
        $topTask['reasoning'] = $this->generateReasoning($topTask, $now);

        return $topTask;
    }

    /**
     * Apply context-aware filters to task collection.
     */
    private function applyContextFilters(Collection $tasks, array $context, \DateTimeImmutable $now): Collection
    {
        // Soft filtering: apply a filter only if it keeps at least one candidate.
        // This avoids "no tasks available" outcomes from slightly-wrong extracted context.
        $filtered = $tasks;

        if (! empty($context['priority_filters'])) {
            $tmp = $filtered->filter(function (array $task) use ($context) {
                return in_array($task['priority'] ?? 'medium', $context['priority_filters'], true);
            });

            if ($tmp->isNotEmpty()) {
                $filtered = $tmp;
            }
        }

        if (! empty($context['task_keywords'])) {
            $tmp = $filtered->filter(function (array $task) use ($context) {
                $title = strtolower((string) ($task['title'] ?? ''));

                foreach ($context['task_keywords'] as $keyword) {
                    if ($keyword === null) {
                        continue;
                    }

                    $needle = strtolower((string) $keyword);
                    if ($needle !== '' && str_contains($title, $needle)) {
                        return true;
                    }
                }

                return false;
            });

            if ($tmp->isNotEmpty()) {
                $filtered = $tmp;
            }
        }

        if (! empty($context['time_constraint'])) {
            $tmp = $this->applyTimeConstraintFilter($filtered, (string) $context['time_constraint'], $now);
            if ($tmp->isNotEmpty()) {
                $filtered = $tmp;
            }
        }

        return $filtered;
    }

    /**
     * Apply time-based filtering.
     */
    private function applyTimeConstraintFilter(Collection $tasks, string $constraint, \DateTimeImmutable $now): Collection
    {
        $today = $now;

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
     * Calculate deadline score based on urgency.
     * Higher score = more urgent
     */
    private function calculateTaskScore(array $task, \DateTimeImmutable $now): array
    {
        $task['deadline_score'] = $this->calculateDeadlineScore($task, $now);
        if (($task['deadline_score'] ?? 0) > 0 && ($task['status'] ?? null) === TaskStatus::Doing->value) {
            // Momentum boost, but kept small so it cannot override urgency buckets.
            $task['deadline_score'] += self::TASK_DOING_BOOST;
        }
        $task['priority_score'] = $this->calculatePriorityScore($task);
        $task['duration_score'] = $this->calculateDurationScore($task);

        return $task;
    }

    /**
     * Calculate deadline score based on urgency.
     * Higher score = more urgent
     */
    private function calculateDeadlineScore(array $task, \DateTimeImmutable $now): int
    {
        if (! isset($task['ends_at']) || $task['ends_at'] === null) {
            return 0; // No deadline = lowest urgency
        }

        try {
            $deadline = new \DateTime($task['ends_at']);
            $interval = $now->diff($deadline);
            $daysUntil = $interval->days;

            // Scoring system:
            if ($deadline < $now) {
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
    private function generateReasoning(array $task, \DateTimeImmutable $now): string
    {
        $priority = ucfirst($task['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($task['ends_at']) && $task['ends_at']) {
            try {
                $deadline = new \DateTime($task['ends_at']);
                $interval = $now->diff($deadline);

                if ($deadline < $now) {
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
