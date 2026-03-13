<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\EventContextItem;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\Models\ChatThread;
use App\Models\Event;
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

        $totalTasks = Task::where('user_id', $user->id)
            ->whereNull('completed_at')
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

        $thread = ChatThread::findOrFail($threadId);

        $recentMessages = $thread->recentTurns($limit)
            ->map(fn ($m) => $m->toConversationTurn())
            ->all();

        $fingerprint = md5(implode(',', array_column($tasks, 'id')));

        return new ContextDto(
            now: $now,
            tasks: $tasks,
            events: $events,
            recentMessages: $recentMessages,
            fingerprint: $fingerprint,
            isSummaryMode: $summaryMode,
        );
    }
}
