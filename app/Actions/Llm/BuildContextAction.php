<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\EventContextItem;
use App\DataTransferObjects\Llm\ProjectContextItem;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\Models\ChatThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class BuildContextAction
{
    public function __invoke(User $user, string $threadId, string $message): ContextDto
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(config('llm.timezone')));
        $maxTasks = config('llm.context.max_tasks');
        $maxHours = config('llm.context.max_events_hours');
        $limit = config('llm.context.recent_messages');
        $summaryThreshold = config('llm.context.summary_task_threshold');
        $maxProjects = config('llm.context.max_projects', 5);

        $totalTasks = Task::forUser($user->id)
            ->incomplete()
            ->count();
        $summaryMode = $totalTasks > $summaryThreshold;

        $taskQuery = Task::activeForUser($user->id)->limit($maxTasks);
        if ($summaryMode) {
            $taskQuery->summaryColumns();
        }

        $tasks = $taskQuery->get()->map(
            fn (Task $t) => new TaskContextItem(
                id: $t->id,
                title: $t->title,
                dueDate: $t->end_datetime?->format('Y-m-d'),
                priority: $t->priority?->value,
                estimateMinutes: $t->duration,
            )
        )->all();

        $events = Event::upcomingForUser($user->id, $maxHours)
            ->get()
            ->map(function (Event $e): EventContextItem {
                $duration = 0;

                if ($e->start_datetime !== null && $e->end_datetime !== null) {
                    $duration = $e->end_datetime->diffInMinutes($e->start_datetime);
                }

                return new EventContextItem(
                    id: $e->id,
                    title: $e->title,
                    startDatetime: $e->start_datetime?->format(\DateTimeInterface::ATOM) ?? '',
                    durationMinutes: $duration,
                );
            })->all();

        $projects = Project::forUser($user->id)
            ->withIncompleteTasks()
            ->orderByName()
            ->limit($maxProjects)
            ->get()
            ->map(function (Project $project): ProjectContextItem {
                $activeTasks = $project->tasks()
                    ->whereNull('completed_at')
                    ->count();

                return new ProjectContextItem(
                    id: $project->id,
                    name: $project->name,
                    startDate: $project->start_datetime?->format('Y-m-d'),
                    endDate: $project->end_datetime?->format('Y-m-d'),
                    activeTaskCount: $activeTasks,
                );
            })->all();

        $thread = ChatThread::findOrFail($threadId);

        $recentMessages = $thread->recentTurns($limit)
            ->map(fn ($m) => $m->toConversationTurn())
            ->all();

        $fingerprint = md5(implode(',', array_column($tasks, 'id')));

        $taskSummary = $this->buildTaskSummary($user, $tasks, $now);
        $projectSummary = $this->buildProjectSummary($user, $projects, $now);
        $lastUserMessage = $this->extractLastUserMessage($recentMessages);

        $userPreferences = [
            'default_study_block_minutes' => 60,
            'preferred_study_times' => ['afternoon', 'evening'],
            'max_parallel_tasks' => 1,
        ];

        return new ContextDto(
            now: $now,
            tasks: $tasks,
            events: $events,
            recentMessages: $recentMessages,
            userPreferences: $userPreferences,
            fingerprint: $fingerprint,
            isSummaryMode: $summaryMode,
            taskSummary: $taskSummary,
            projects: $projects,
            projectSummary: $projectSummary,
            lastUserMessage: $lastUserMessage,
        );
    }

    /**
     * @param  list<TaskContextItem>  $tasks
     * @return array<string, mixed>
     */
    private function buildTaskSummary(User $user, array $tasks, \DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $carbonNow = \Carbon\CarbonImmutable::instance(\Carbon\Carbon::parse($now->format(\DateTimeInterface::ATOM)));

        $overdueCount = Task::forUser($user->id)
            ->incomplete()
            ->overdue($carbonNow)
            ->count();

        $dueTodayCount = Task::forUser($user->id)
            ->incomplete()
            ->whereDate('end_datetime', $today)
            ->count();

        $dueNext7DaysCount = Task::forUser($user->id)
            ->incomplete()
            ->dueSoon($carbonNow, 7)
            ->count();

        $highPriorityCount = Task::forUser($user->id)
            ->incomplete()
            ->highPriority()
            ->count();

        $urgentCount = Task::forUser($user->id)
            ->incomplete()
            ->byPriority(\App\Enums\TaskPriority::Urgent->value)
            ->count();

        $relevantTodayIds = [];
        $next7DaysIds = [];
        $topHighPriorityIds = [];

        foreach ($tasks as $task) {
            if (! $task instanceof TaskContextItem) {
                continue;
            }

            if ($task->dueDate === $today) {
                $relevantTodayIds[] = $task->id;
            }

            if ($task->dueDate !== null) {
                $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $task->dueDate);

                if ($dueDate instanceof \DateTimeImmutable) {
                    $diffDays = (int) $carbonNow->diffInDays(\Carbon\CarbonImmutable::instance($dueDate), false);

                    if ($diffDays >= 0 && $diffDays <= 7) {
                        $next7DaysIds[] = $task->id;
                    }
                }
            }

            if (in_array($task->priority, [\App\Enums\TaskPriority::High->value, \App\Enums\TaskPriority::Urgent->value], true)) {
                $topHighPriorityIds[] = $task->id;
            }
        }

        $topHighPriorityIds = array_slice($topHighPriorityIds, 0, 5);

        return [
            'total_active_tasks' => $total = $overdueCount + ($dueTodayCount + $dueNext7DaysCount),
            'overdue_count' => $overdueCount,
            'due_today_count' => $dueTodayCount,
            'due_next_7_days_count' => $dueNext7DaysCount,
            'high_priority_count' => $highPriorityCount,
            'urgent_count' => $urgentCount,
            'relevant_today_task_ids' => $relevantTodayIds,
            'next_7_days_task_ids' => $next7DaysIds,
            'top_high_priority_task_ids' => $topHighPriorityIds,
        ];
    }

    /**
     * @param  list<\App\DataTransferObjects\Llm\ConversationTurn>  $recentMessages
     */
    private function extractLastUserMessage(array $recentMessages): ?string
    {
        $messages = array_reverse($recentMessages);

        foreach ($messages as $turn) {
            if ($turn->role === \App\Enums\ChatMessageRole::User->value) {
                return $turn->text;
            }
        }

        return null;
    }

    /**
     * @param  list<ProjectContextItem>  $projects
     * @return array<string, mixed>
     */
    private function buildProjectSummary(User $user, array $projects, \DateTimeImmutable $now): array
    {
        $carbonNow = \Carbon\CarbonImmutable::instance(\Carbon\Carbon::parse($now->format(\DateTimeInterface::ATOM)));

        $totalProjects = Project::forUser($user->id)->count();
        $projectsWithIncompleteTasks = Project::forUser($user->id)
            ->withIncompleteTasks()
            ->count();
        $overdueProjects = Project::forUser($user->id)
            ->overdue($carbonNow)
            ->count();
        $upcomingProjects = Project::forUser($user->id)
            ->startingSoon($carbonNow, 7)
            ->count();

        $topProjectIds = array_map(
            fn (ProjectContextItem $p): int => $p->id,
            array_slice($projects, 0, 5),
        );

        return [
            'total_projects' => $totalProjects,
            'projects_with_incomplete_tasks' => $projectsWithIncompleteTasks,
            'overdue_projects' => $overdueProjects,
            'upcoming_projects_next_7_days' => $upcomingProjects,
            'top_project_ids' => $topProjectIds,
        ];
    }
}
