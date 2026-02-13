<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Async;
use Livewire\Attributes\Renderless;

trait HandlesTrash
{
    /**
     * Page size for trash items "load more".
     */
    private const TRASH_PAGE_SIZE = 10;

    /**
     * Max items per multi-restore or multi-force-delete request.
     */
    private const TRASH_BATCH_MAX = 50;

    /**
     * Max items to permanently delete in one "delete all" request.
     */
    private const TRASH_DELETE_ALL_MAX = 500;

    /**
     * Valid kinds for trash items.
     *
     * @var array<string>
     */
    private const TRASH_KINDS = ['task', 'project', 'event'];

    /**
     * Load a page of trashed items (tasks, projects, events) for the authenticated user.
     * Merged and sorted by deleted_at descending. Use lastDeletedAt as cursor for next page.
     *
     * @return array{items: array<int, array{kind: string, id: int, title: string, deleted_at: string, deleted_at_display: string}>, hasMore: bool, lastDeletedAt: string|null}
     */
    #[Renderless]
    public function loadTrashItems(?string $afterDeletedAt = null, int $limit = 10): array
    {
        $user = $this->requireAuth(__('You must be logged in to view trash.'));
        if ($user === null) {
            return ['items' => [], 'hasMore' => false, 'lastDeletedAt' => null];
        }

        $limit = max(1, min($limit, 50));
        $pageSize = self::TRASH_PAGE_SIZE;
        $take = min($limit, $pageSize);

        $tasks = $this->fetchTrashedTasks($user->id, $afterDeletedAt, $pageSize);
        $projects = $this->fetchTrashedProjects($user->id, $afterDeletedAt, $pageSize);
        $events = $this->fetchTrashedEvents($user->id, $afterDeletedAt, $pageSize);

        $merged = collect()
            ->merge($tasks)
            ->merge($projects)
            ->merge($events)
            ->sortByDesc('deleted_at')
            ->values();

        $items = $merged->take($take)->map(function (array $row): array {
            return [
                'kind' => $row['kind'],
                'id' => $row['id'],
                'title' => $row['title'],
                'deleted_at' => $row['deleted_at'],
                'deleted_at_display' => $row['deleted_at_display'],
            ];
        })->values()->all();

        $hasMore = $merged->count() > $take;
        $last = $merged->get($take - 1);
        $lastDeletedAt = $last ? $last['deleted_at'] : null;

        return [
            'items' => $items,
            'hasMore' => $hasMore,
            'lastDeletedAt' => $lastDeletedAt,
        ];
    }

    /**
     * Restore a trashed item by kind and id.
     */
    #[Async]
    #[Renderless]
    public function restoreTrashItem(string $kind, int $id): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore items.'));
        if ($user === null) {
            return false;
        }

        $model = $this->resolveTrashedModel($kind, $id, $user->id, true);
        if ($model === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        if ((int) $model->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can restore this item.'));

            return false;
        }

        if (! $this->authorizeTrashAction($model, 'restore')) {
            return false;
        }

        $actionMethod = match ($kind) {
            'task' => 'restoreTaskAction',
            'project' => 'restoreProjectAction',
            'event' => 'restoreEventAction',
            default => null,
        };

        if ($actionMethod === null || ! property_exists($this, $actionMethod)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid item type.'));

            return false;
        }

        try {
            $restored = $this->{$actionMethod}->execute($model, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to restore item from trash.', [
                'user_id' => $user->id,
                'kind' => $kind,
                'id' => $id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: $this->restoreTrashErrorMessage($kind));

            return false;
        }

        if (! $restored) {
            $this->dispatch('toast', type: 'error', message: $this->restoreTrashErrorMessage($kind));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: $this->restoreTrashSuccessMessage($kind));

        return true;
    }

    /**
     * Permanently delete a trashed item by kind and id.
     */
    #[Async]
    #[Renderless]
    public function forceDeleteTrashItem(string $kind, int $id): bool
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete items.'));
        if ($user === null) {
            return false;
        }

        $model = $this->resolveTrashedModel($kind, $id, $user->id, false);
        if ($model === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        if ((int) $model->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can permanently delete this item.'));

            return false;
        }

        if (! $this->authorizeTrashAction($model, 'forceDelete')) {
            return false;
        }

        $actionMethod = match ($kind) {
            'task' => 'forceDeleteTaskAction',
            'project' => 'forceDeleteProjectAction',
            'event' => 'forceDeleteEventAction',
            default => null,
        };

        if ($actionMethod === null || ! property_exists($this, $actionMethod)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid item type.'));

            return false;
        }

        try {
            $deleted = $this->{$actionMethod}->execute($model, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to permanently delete item from trash.', [
                'user_id' => $user->id,
                'kind' => $kind,
                'id' => $id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: $this->forceDeleteTrashErrorMessage($kind));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: $this->forceDeleteTrashErrorMessage($kind));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: $this->forceDeleteTrashSuccessMessage($kind));

        return true;
    }

    /**
     * Restore multiple trashed items. Each entry must have 'kind' and 'id'.
     *
     * @param  array<int, array{kind: string, id: int|string}>  $items
     * @return array{restored: int, failed: int}
     */
    #[Async]
    #[Renderless]
    public function restoreTrashItems(array $items): array
    {
        $user = $this->requireAuth(__('You must be logged in to restore items.'));
        if ($user === null) {
            return ['restored' => 0, 'failed' => 0];
        }

        $normalized = $this->normalizeTrashBatchItems($items);
        if ($normalized === []) {
            return ['restored' => 0, 'failed' => 0];
        }

        $restored = 0;
        $failed = 0;

        foreach ($normalized as ['kind' => $kind, 'id' => $id]) {
            $model = $this->resolveTrashedModel($kind, (int) $id, $user->id, true);
            if ($model === null) {
                $failed++;

                continue;
            }
            if ((int) $model->user_id !== (int) $user->id) {
                $failed++;

                continue;
            }
            if (! $this->authorizeTrashAction($model, 'restore')) {
                $failed++;

                continue;
            }

            $actionMethod = match ($kind) {
                'task' => 'restoreTaskAction',
                'project' => 'restoreProjectAction',
                'event' => 'restoreEventAction',
                default => null,
            };
            if ($actionMethod === null || ! property_exists($this, $actionMethod)) {
                $failed++;

                continue;
            }

            try {
                if ($this->{$actionMethod}->execute($model, $user)) {
                    $restored++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to restore item from trash (batch).', [
                    'user_id' => $user->id,
                    'kind' => $kind,
                    'id' => $id,
                    'exception' => $e,
                ]);
                $failed++;
            }
        }

        $this->dispatchBatchTrashToast('restore', $restored, $failed);

        return ['restored' => $restored, 'failed' => $failed];
    }

    /**
     * Permanently delete multiple trashed items. Each entry must have 'kind' and 'id'.
     *
     * @param  array<int, array{kind: string, id: int|string}>  $items
     * @return array{deleted: int, failed: int}
     */
    #[Async]
    #[Renderless]
    public function forceDeleteTrashItems(array $items): array
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete items.'));
        if ($user === null) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $normalized = $this->normalizeTrashBatchItems($items);
        if ($normalized === []) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $result = $this->executeForceDeleteTrashItems($normalized, $user);
        $this->dispatchBatchTrashToast('forceDelete', $result['deleted'], $result['failed']);

        return $result;
    }

    /**
     * Permanently delete all trashed items for the authenticated user (up to a cap).
     *
     * @return array{deleted: int, failed: int}
     */
    #[Async]
    #[Renderless]
    public function forceDeleteAllTrashItems(): array
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete items.'));
        if ($user === null) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $items = $this->fetchAllTrashedItemIds($user->id, self::TRASH_DELETE_ALL_MAX);
        $result = $this->executeForceDeleteTrashItems($items, $user);

        if ($result['deleted'] > 0 || $result['failed'] > 0) {
            $this->dispatchBatchTrashToast('forceDelete', $result['deleted'], $result['failed']);
        }

        return $result;
    }

    /**
     * Execute force-delete for a list of items. No toast.
     *
     * @param  array<int, array{kind: string, id: int}>  $items
     * @return array{deleted: int, failed: int}
     */
    private function executeForceDeleteTrashItems(array $items, User $user): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($items as ['kind' => $kind, 'id' => $id]) {
            $model = $this->resolveTrashedModel($kind, (int) $id, $user->id, false);
            if ($model === null) {
                $failed++;

                continue;
            }
            if ((int) $model->user_id !== (int) $user->id) {
                $failed++;

                continue;
            }
            if (! $this->authorizeTrashAction($model, 'forceDelete')) {
                $failed++;

                continue;
            }

            $actionMethod = match ($kind) {
                'task' => 'forceDeleteTaskAction',
                'project' => 'forceDeleteProjectAction',
                'event' => 'forceDeleteEventAction',
                default => null,
            };
            if ($actionMethod === null || ! property_exists($this, $actionMethod)) {
                $failed++;

                continue;
            }

            try {
                if ($this->{$actionMethod}->execute($model, $user)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to permanently delete item from trash (batch).', [
                    'user_id' => $user->id,
                    'kind' => $kind,
                    'id' => $id,
                    'exception' => $e,
                ]);
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Normalize and limit batch items. Each entry must have 'kind' and 'id'.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{kind: string, id: int}>
     */
    private function normalizeTrashBatchItems(array $items): array
    {
        $seen = [];
        $normalized = [];
        foreach ($items as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = isset($entry['kind']) ? (string) $entry['kind'] : null;
            $id = isset($entry['id']) ? (int) $entry['id'] : null;
            if ($kind === null || $id < 1 || ! in_array($kind, self::TRASH_KINDS, true)) {
                continue;
            }
            $key = $kind.'-'.$id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = ['kind' => $kind, 'id' => $id];
            if (count($normalized) >= self::TRASH_BATCH_MAX) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Dispatch a single toast for batch restore or force-delete result.
     */
    private function dispatchBatchTrashToast(string $action, int $successCount, int $failedCount): void
    {
        if ($action === 'restore') {
            if ($successCount > 0 && $failedCount === 0) {
                $this->dispatch('toast', type: 'success', message: trans_choice('Restored :count item.|Restored :count items.', $successCount, ['count' => $successCount]));
            } elseif ($successCount > 0) {
                $this->dispatch('toast', type: 'warning', message: __('Restored :restored, :failed failed.', ['restored' => $successCount, 'failed' => $failedCount]));
            } elseif ($failedCount > 0) {
                $this->dispatch('toast', type: 'error', message: __('Couldn’t restore the selected items. Try again.'));
            }
        } else {
            if ($successCount > 0 && $failedCount === 0) {
                $this->dispatch('toast', type: 'success', message: trans_choice('Permanently deleted :count item.|Permanently deleted :count items.', $successCount, ['count' => $successCount]));
            } elseif ($successCount > 0) {
                $this->dispatch('toast', type: 'warning', message: __('Permanently deleted :deleted, :failed failed.', ['deleted' => $successCount, 'failed' => $failedCount]));
            } elseif ($failedCount > 0) {
                $this->dispatch('toast', type: 'error', message: __('Couldn’t permanently delete the selected items. Try again.'));
            }
        }
    }

    /**
     * Fetch all trashed item identifiers for the user, up to $max total.
     *
     * @return array<int, array{kind: string, id: int}>
     */
    private function fetchAllTrashedItemIds(int $userId, int $max = self::TRASH_DELETE_ALL_MAX): array
    {
        $perKind = (int) ceil($max / 3);
        $tasks = Task::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->limit($perKind)
            ->pluck('id')
            ->map(fn (int $id): array => ['kind' => 'task', 'id' => $id])
            ->all();
        $projects = Project::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->limit($perKind)
            ->pluck('id')
            ->map(fn (int $id): array => ['kind' => 'project', 'id' => $id])
            ->all();
        $events = Event::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->limit($perKind)
            ->pluck('id')
            ->map(fn (int $id): array => ['kind' => 'event', 'id' => $id])
            ->all();

        return collect($tasks)->merge($projects)->merge($events)->take($max)->values()->all();
    }

    /**
     * @return Collection<int, array{kind: string, id: int, title: string, deleted_at: string, deleted_at_display: string}>
     */
    private function fetchTrashedTasks(int $userId, ?string $afterDeletedAt, int $limit): Collection
    {
        $query = Task::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->orderByDesc('deleted_at')
            ->limit($limit);

        if ($afterDeletedAt !== null && $afterDeletedAt !== '') {
            $query->where('deleted_at', '<', $afterDeletedAt);
        }

        return $query->get()->map(fn (Task $task): array => [
            'kind' => 'task',
            'id' => $task->id,
            'title' => $task->title,
            'deleted_at' => $task->deleted_at?->toIso8601String() ?? '',
            'deleted_at_display' => $task->deleted_at?->translatedFormat('M j, Y g:i A') ?? '',
        ]);
    }

    /**
     * @return Collection<int, array{kind: string, id: int, title: string, deleted_at: string, deleted_at_display: string}>
     */
    private function fetchTrashedProjects(int $userId, ?string $afterDeletedAt, int $limit): Collection
    {
        $query = Project::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->orderByDesc('deleted_at')
            ->limit($limit);

        if ($afterDeletedAt !== null && $afterDeletedAt !== '') {
            $query->where('deleted_at', '<', $afterDeletedAt);
        }

        return $query->get()->map(fn (Project $project): array => [
            'kind' => 'project',
            'id' => $project->id,
            'title' => $project->name,
            'deleted_at' => $project->deleted_at?->toIso8601String() ?? '',
            'deleted_at_display' => $project->deleted_at?->translatedFormat('M j, Y g:i A') ?? '',
        ]);
    }

    /**
     * @return Collection<int, array{kind: string, id: int, title: string, deleted_at: string, deleted_at_display: string}>
     */
    private function fetchTrashedEvents(int $userId, ?string $afterDeletedAt, int $limit): Collection
    {
        $query = Event::query()
            ->onlyTrashed()
            ->forUser($userId)
            ->orderByDesc('deleted_at')
            ->limit($limit);

        if ($afterDeletedAt !== null && $afterDeletedAt !== '') {
            $query->where('deleted_at', '<', $afterDeletedAt);
        }

        return $query->get()->map(fn (Event $event): array => [
            'kind' => 'event',
            'id' => $event->id,
            'title' => $event->title,
            'deleted_at' => $event->deleted_at?->toIso8601String() ?? '',
            'deleted_at_display' => $event->deleted_at?->translatedFormat('M j, Y g:i A') ?? '',
        ]);
    }

    /**
     * Resolve a trashed (or with-trashed) model by kind and id for the user.
     * $onlyTrashed true = restore (must be trashed), false = force delete (can be trashed or not).
     */
    private function resolveTrashedModel(string $kind, int $id, int $userId, bool $onlyTrashed): Task|Project|Event|null
    {
        if (! in_array($kind, self::TRASH_KINDS, true)) {
            return null;
        }

        return match ($kind) {
            'task' => $onlyTrashed
                ? Task::query()->onlyTrashed()->forUser($userId)->find($id)
                : Task::query()->withTrashed()->forUser($userId)->find($id),
            'project' => $onlyTrashed
                ? Project::query()->onlyTrashed()->forUser($userId)->find($id)
                : Project::query()->withTrashed()->forUser($userId)->find($id),
            'event' => $onlyTrashed
                ? Event::query()->onlyTrashed()->forUser($userId)->find($id)
                : Event::query()->withTrashed()->forUser($userId)->find($id),
            default => null,
        };
    }

    private function authorizeTrashAction(Task|Project|Event $model, string $ability): bool
    {
        try {
            $this->authorize($ability, $model);

            return true;
        } catch (\Throwable) {
            $this->dispatch('toast', type: 'error', message: __('You are not allowed to do this.'));

            return false;
        }
    }

    private function restoreTrashSuccessMessage(string $kind): string
    {
        return match ($kind) {
            'task' => __('Restored the task.'),
            'project' => __('Restored the project.'),
            'event' => __('Restored the event.'),
            default => __('Restored the item.'),
        };
    }

    private function restoreTrashErrorMessage(string $kind): string
    {
        return match ($kind) {
            'task' => __('Couldn’t restore the task. Try again.'),
            'project' => __('Couldn’t restore the project. Try again.'),
            'event' => __('Couldn’t restore the event. Try again.'),
            default => __('Couldn’t restore the item. Try again.'),
        };
    }

    private function forceDeleteTrashSuccessMessage(string $kind): string
    {
        return match ($kind) {
            'task' => __('Permanently deleted the task.'),
            'project' => __('Permanently deleted the project.'),
            'event' => __('Permanently deleted the event.'),
            default => __('Permanently deleted the item.'),
        };
    }

    private function forceDeleteTrashErrorMessage(string $kind): string
    {
        return match ($kind) {
            'task' => __('Couldn’t permanently delete the task. Try again.'),
            'project' => __('Couldn’t permanently delete the project. Try again.'),
            'event' => __('Couldn’t permanently delete the event. Try again.'),
            default => __('Couldn’t permanently delete the item. Try again.'),
        };
    }
}
