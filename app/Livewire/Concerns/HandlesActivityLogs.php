<?php

namespace App\Livewire\Concerns;

use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use Livewire\Attributes\Renderless;

trait HandlesActivityLogs
{
    /**
     * Page size for "load more" activity logs.
     */
    private const ACTIVITY_LOGS_PAGE_SIZE = 10;

    /**
     * Load the next page of activity logs for a task, project, or event.
     * Used by the activity log popover "Load more" button.
     *
     * @return array{logs: array<int, array{id: int, action: string, actionLabel: string, payload: array, createdAt: string, user: array{id: int, name: string}}>, hasMore: bool}
     */
    #[Renderless]
    public function loadMoreActivityLogs(string $loggableType, int $loggableId, int $offset): array
    {
        $user = $this->requireAuth(__('You must be logged in to view activity.'));
        if ($user === null) {
            return ['logs' => [], 'hasMore' => false];
        }

        $item = match ($loggableType) {
            Task::class => Task::query()->forUser($user->id)->find($loggableId),
            Event::class => Event::query()->forUser($user->id)->find($loggableId),
            Project::class => Project::query()->forUser($user->id)->find($loggableId),
            default => null,
        };

        if ($item === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return ['logs' => [], 'hasMore' => false];
        }

        $this->authorize('update', $item);

        $limit = self::ACTIVITY_LOGS_PAGE_SIZE;
        $logs = ActivityLog::query()
            ->forItem($item)
            ->with('user')
            ->latest()
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $hasMore = $logs->count() > $limit;
        if ($hasMore) {
            $logs = $logs->take($limit);
        }

        $logEntries = $logs->map(function (ActivityLog $log): array {
            $actorName = $log->user?->name ?? $log->user?->email ?? __('Unknown user');

            return [
                'id' => $log->id,
                'action' => $log->action->value,
                'actionLabel' => $log->action->label(),
                'payload' => $log->payload ?? [],
                'createdAt' => $log->created_at?->toIso8601String() ?? '',
                'user' => [
                    'id' => $log->user_id ?? 0,
                    'name' => $actorName,
                ],
            ];
        })->values()->all();

        return [
            'logs' => $logEntries,
            'hasMore' => $hasMore,
        ];
    }
}
