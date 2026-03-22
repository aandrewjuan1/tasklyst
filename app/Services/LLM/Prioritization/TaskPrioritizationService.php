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

        /**
         * We want cross-type prioritization to be consistent with the task ordering
         * policy: urgency first (deadline_score), then explicit priority, then shorter
         * duration (duration_score), and finally deterministic tie-breakers.
         *
         * For events/projects we emulate priority via fixed priority_score values.
         */
        foreach ($taskRanked as $task) {
            $id = (int) ($task['id'] ?? 0);
            $title = (string) ($task['title'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }

            $deadlineEpoch = PHP_INT_MAX;
            if (is_string($task['ends_at'] ?? null) && ($task['ends_at'] ?? '') !== '') {
                try {
                    $deadlineEpoch = (new \DateTimeImmutable((string) $task['ends_at']))->getTimestamp();
                } catch (\Throwable) {
                    $deadlineEpoch = PHP_INT_MAX;
                }
            }

            $deadlineScore = (int) ($task['deadline_score'] ?? 0);
            $priorityScore = (int) ($task['priority_score'] ?? 0);
            $durationScore = (int) ($task['duration_score'] ?? 0);

            $candidates[] = [
                'type' => 'task',
                'id' => $id,
                'title' => $title,
                'score' => $deadlineScore + $priorityScore + $durationScore,
                'deadline_score' => $deadlineScore,
                'priority_score' => $priorityScore,
                'duration_score' => $durationScore,
                'deadline_epoch' => $deadlineEpoch,
                'duration_minutes' => (int) ($task['duration_minutes'] ?? 0),
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
                'deadline_score' => (int) ($event['deadline_score'] ?? ($event['score'] ?? 0)),
                'priority_score' => (int) ($event['priority_score'] ?? 0),
                'duration_score' => (int) ($event['duration_score'] ?? 0),
                'deadline_epoch' => (int) ($event['deadline_epoch'] ?? PHP_INT_MAX),
                'duration_minutes' => 0,
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
                'deadline_score' => (int) ($project['deadline_score'] ?? ($project['score'] ?? 0)),
                'priority_score' => (int) ($project['priority_score'] ?? 0),
                'duration_score' => (int) ($project['duration_score'] ?? 0),
                'deadline_epoch' => (int) ($project['deadline_epoch'] ?? PHP_INT_MAX),
                'duration_minutes' => 0,
                'reasoning' => (string) ($project['reasoning'] ?? 'Active project'),
                'raw' => $project,
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            $aDeadline = (int) ($a['deadline_score'] ?? 0);
            $bDeadline = (int) ($b['deadline_score'] ?? 0);
            if ($aDeadline !== $bDeadline) {
                return $bDeadline <=> $aDeadline; // higher urgency wins
            }

            $aPriority = (int) ($a['priority_score'] ?? 0);
            $bPriority = (int) ($b['priority_score'] ?? 0);
            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority; // then explicit priority
            }

            $aEpoch = (int) ($a['deadline_epoch'] ?? PHP_INT_MAX);
            $bEpoch = (int) ($b['deadline_epoch'] ?? PHP_INT_MAX);
            if ($aEpoch !== $bEpoch) {
                return $aEpoch <=> $bEpoch; // earlier deadline/time wins
            }

            $aDurationScore = (int) ($a['duration_score'] ?? 0);
            $bDurationScore = (int) ($b['duration_score'] ?? 0);
            if ($aDurationScore !== $bDurationScore) {
                return $bDurationScore <=> $aDurationScore; // prefer shorter duration
            }

            $aDurationMinutes = (int) ($a['duration_minutes'] ?? 0);
            $bDurationMinutes = (int) ($b['duration_minutes'] ?? 0);
            if ($aDurationMinutes !== $bDurationMinutes) {
                return $aDurationMinutes <=> $bDurationMinutes;
            }

            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });

        // Strip helper fields so the return shape matches callers.
        return array_map(function (array $c): array {
            unset($c['deadline_score'], $c['priority_score'], $c['duration_score'], $c['deadline_epoch'], $c['duration_minutes']);

            return $c;
        }, $candidates);
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

        $scored = $collection
            ->map(function (array $task) use ($now) {
                return $this->calculateTaskScore($task, $now);
            })
            ->values()
            ->all();

        // IMPORTANT:
        // Collection's chained `sortBy*()` calls are not a stable multi-key sort.
        // The last call (`sortBy('duration_minutes')`) currently overrides earlier
        // sorts, which can cause short-duration tasks to win over more urgent/deadline-heavy ones.
        usort($scored, function (array $a, array $b): int {
            $aDeadline = (int) ($a['deadline_score'] ?? 0);
            $bDeadline = (int) ($b['deadline_score'] ?? 0);
            if ($aDeadline !== $bDeadline) {
                return $bDeadline <=> $aDeadline; // deadline first (urgent/overdue wins)
            }

            $aPriority = (int) ($a['priority_score'] ?? 0);
            $bPriority = (int) ($b['priority_score'] ?? 0);
            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority; // then priority
            }

            $aDurationScore = (int) ($a['duration_score'] ?? 0);
            $bDurationScore = (int) ($b['duration_score'] ?? 0);
            if ($aDurationScore !== $bDurationScore) {
                return $bDurationScore <=> $aDurationScore; // then prefers shorter tasks
            }

            $aDurationMinutes = (int) ($a['duration_minutes'] ?? PHP_INT_MAX);
            $bDurationMinutes = (int) ($b['duration_minutes'] ?? PHP_INT_MAX);
            if ($aDurationMinutes !== $bDurationMinutes) {
                return $aDurationMinutes <=> $bDurationMinutes; // deterministic tie-break
            }

            $aId = (int) ($a['id'] ?? 0);
            $bId = (int) ($b['id'] ?? 0);

            return $aId <=> $bId;
        });

        return $scored;
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
                $reasoning = 'Upcoming event';

                $startsAt = $event['starts_at'] ?? null;
                $endsAt = $event['ends_at'] ?? null;
                $allDay = (bool) ($event['all_day'] ?? false);

                $deadlineEpoch = PHP_INT_MAX;
                $deadlineScore = 0;

                if (is_string($startsAt) && $startsAt !== '') {
                    try {
                        $start = new \DateTimeImmutable($startsAt);
                        $deadlineEpoch = $start->getTimestamp();

                        // Overdue is based on end time, not start time.
                        if (is_string($endsAt) && $endsAt !== '') {
                            try {
                                $end = new \DateTimeImmutable($endsAt);
                                if ($end < $now) {
                                    $deadlineScore = 1000;
                                }
                            } catch (\Throwable) {
                                // ignore invalid ends_at
                            }
                        }

                        // If still not overdue, bucket by start time.
                        if ($deadlineScore !== 1000) {
                            $minutesUntil = (int) floor(($start->getTimestamp() - $now->getTimestamp()) / 60);

                            if ($minutesUntil < 0) {
                                $deadlineScore = 900; // started today (treated as due today urgency)
                                $reasoning = 'Event already started';
                            } elseif ($start->format('Y-m-d') === $now->format('Y-m-d')) {
                                $deadlineScore = 900;
                                $reasoning = $minutesUntil <= 60 ? 'Event starts within 1 hour' : 'Event is today';
                            } else {
                                $daysUntil = (int) $now->diff($start)->days;
                                if ($daysUntil === 1) {
                                    $deadlineScore = 800;
                                    $reasoning = 'Event starts tomorrow';
                                } elseif ($daysUntil <= 7) {
                                    $deadlineScore = 700 - ($daysUntil * 50);
                                    $reasoning = 'Event starts soon';
                                } else {
                                    $deadlineScore = max(100, 600 - ($daysUntil * 20));
                                    $reasoning = 'Event starts later';
                                }
                            }
                        }
                    } catch (\Throwable) {
                        $deadlineScore = 0;
                        $deadlineEpoch = PHP_INT_MAX;
                    }
                }

                if ($allDay && $deadlineScore > 0) {
                    // All-day events should feel at least as urgent as timed events that start today.
                    $reasoning = $reasoning === 'Upcoming event' ? 'All-day event' : $reasoning;
                }

                // Map events to a strong priority because the user asked to prep for a time-bound item.
                $priorityScore = ($event['status'] ?? null) === EventStatus::Ongoing->value ? 120 : 110;
                $durationScore = 50;

                // If we have no deadline score but we still have starts_at, treat as low urgency (so it doesn't beat tasks).
                if ($deadlineScore === 0 && $deadlineEpoch !== PHP_INT_MAX) {
                    $deadlineScore = 100;
                }

                // Preserve legacy $score field for any existing logs/diagnostics.
                $event['score'] = $deadlineScore + $priorityScore + $durationScore;
                $event['deadline_score'] = $deadlineScore;
                $event['priority_score'] = $priorityScore;
                $event['duration_score'] = $durationScore;
                $event['deadline_epoch'] = $deadlineEpoch;
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
                $reasoning = 'Active project';
                $deadlineEpoch = PHP_INT_MAX;
                $deadlineScore = 0;

                $endAt = $project['end_at'] ?? null;
                if (is_string($endAt) && $endAt !== '') {
                    try {
                        $end = new \DateTimeImmutable($endAt);
                        $deadlineEpoch = $end->getTimestamp();

                        $daysUntil = (int) floor(($end->getTimestamp() - $now->getTimestamp()) / 86400);

                        if ($end < $now) {
                            $deadlineScore = 1000;
                            $reasoning = 'Project is overdue';
                        } elseif ($daysUntil === 0) {
                            $deadlineScore = 900;
                            $reasoning = 'Project ends today';
                        } elseif ($daysUntil <= 7) {
                            $deadlineScore = 700 - ($daysUntil * 50);
                            $reasoning = 'Project ends soon';
                        } else {
                            $deadlineScore = max(100, 600 - ($daysUntil * 20));
                            $reasoning = 'Project has an upcoming end date';
                        }
                    } catch (\Throwable) {
                        $deadlineScore = 0;
                        $deadlineEpoch = PHP_INT_MAX;
                    }
                }

                // Projects are important but not usually as time-urgent as events/tasks.
                $priorityScore = 80;
                $durationScore = 50;

                if ($deadlineScore === 0 && $deadlineEpoch !== PHP_INT_MAX) {
                    $deadlineScore = 100;
                }

                $project['score'] = $deadlineScore + $priorityScore + $durationScore;
                $project['deadline_score'] = $deadlineScore;
                $project['priority_score'] = $priorityScore;
                $project['duration_score'] = $durationScore;
                $project['deadline_epoch'] = $deadlineEpoch;
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
        // Filtering is a "preference cascade":
        // - We always try to respect explicit subject/type keywords.
        // - Priority and time constraints are relaxed if they would otherwise yield an empty candidate set.
        $filtered = $tasks;

        if (! empty($context['recurring_requested'])) {
            $recurringOnly = $filtered->filter(function (array $task): bool {
                return ! empty($task['is_recurring']);
            });

            if (! $recurringOnly->isEmpty()) {
                $filtered = $recurringOnly;
            }
        }

        // 1) Subject/type keywords: strict if possible, otherwise relax.
        if (! empty($context['task_keywords'])) {
            $keywordFiltered = $filtered->filter(function (array $task) use ($context): bool {
                $title = strtolower((string) ($task['title'] ?? ''));
                $subjectName = strtolower((string) ($task['subject_name'] ?? ''));
                $tags = is_array($task['tags'] ?? null) ? $task['tags'] : [];
                $tagsLower = array_map(
                    fn (mixed $t): string => strtolower((string) $t),
                    $tags
                );

                foreach ($context['task_keywords'] as $keyword) {
                    if ($keyword === null) {
                        continue;
                    }

                    $needle = strtolower((string) $keyword);
                    if ($needle === '') {
                        continue;
                    }

                    if (str_contains($title, $needle) || str_contains($subjectName, $needle)) {
                        return true;
                    }

                    foreach ($tagsLower as $tag) {
                        if ($tag !== '' && str_contains($tag, $needle)) {
                            return true;
                        }
                    }
                }

                return false;
            });

            // If the strict keyword filter excludes everything, relax it.
            if (! $keywordFiltered->isEmpty()) {
                $filtered = $keywordFiltered;
            }
        }

        $priorityFilters = $context['priority_filters'] ?? [];
        $timeConstraint = $context['time_constraint'] ?? null;

        $hasPriorityFilters = is_array($priorityFilters) && $priorityFilters !== [];
        $hasTimeConstraint = is_string($timeConstraint) && $timeConstraint !== '';

        // 2) Priority/time constraints: try intersection first, then relax.
        if ($hasPriorityFilters && $hasTimeConstraint) {
            $priorityOnly = $filtered->filter(function (array $task) use ($priorityFilters): bool {
                return in_array($task['priority'] ?? 'medium', $priorityFilters, true);
            });

            $intersection = $this->applyTimeConstraintFilter($priorityOnly, (string) $timeConstraint, $now);

            if (! $intersection->isEmpty()) {
                return $intersection;
            }

            if (! $priorityOnly->isEmpty()) {
                // Relax time constraint.
                return $priorityOnly;
            }

            $timeOnly = $this->applyTimeConstraintFilter($filtered, (string) $timeConstraint, $now);

            if (! $timeOnly->isEmpty()) {
                // Relax priority constraint.
                return $timeOnly;
            }

            // Relax both (fall back to keyword-filtered set).
            return $filtered;
        }

        if ($hasPriorityFilters) {
            $priorityOnly = $filtered->filter(function (array $task) use ($priorityFilters): bool {
                return in_array($task['priority'] ?? 'medium', $priorityFilters, true);
            });

            if (! $priorityOnly->isEmpty()) {
                return $priorityOnly;
            }
        }

        if ($hasTimeConstraint) {
            $timeOnly = $this->applyTimeConstraintFilter($filtered, (string) $timeConstraint, $now);

            if (! $timeOnly->isEmpty()) {
                return $timeOnly;
            }
        }

        return $filtered;
    }

    /**
     * Filter tasks by the same time rules used for prioritization/browse.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public function filterTasksForTimeConstraint(array $tasks, ?string $constraint, \DateTimeImmutable $now): array
    {
        if ($constraint === null || $constraint === '' || $constraint === 'none') {
            return $tasks;
        }

        return $this->applyTimeConstraintFilter(collect($tasks), $constraint, $now)->values()->all();
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
            if ($deadline < $now) {
                return 1000; // Overdue - highest priority
            }

            // Bucket by calendar day in the snapshot timezone (not by 24h intervals).
            $nowTz = $now->getTimezone();
            $deadlineLocal = $deadline->setTimezone($nowTz);

            $nowDate = $now->format('Y-m-d');
            $deadlineDate = $deadlineLocal->format('Y-m-d');

            if ($deadlineDate === $nowDate) {
                return 900; // Due today - very high priority
            }

            $nowMidnight = new \DateTimeImmutable($nowDate.' 00:00:00', $nowTz);
            $deadlineMidnight = new \DateTimeImmutable($deadlineDate.' 00:00:00', $nowTz);
            $daysUntil = (int) $nowMidnight->diff($deadlineMidnight)->days;

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
