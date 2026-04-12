@props([
    'overdueTasks',
    'overdueCount' => 0,
    'dueTodayTasks',
    'dueTodayCount' => 0,
    'doingTasks',
    'doingCount' => 0,
    'todayEvents',
    'todayEventsCount' => 0,
    'workspaceDate',
])

@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $overdueTasks */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $doingTasks */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $dueTodayTasks */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Event> $todayEvents */
    $taskTimeLabel = static function (\App\Models\Task $task): string {
        if ($task->end_datetime === null) {
            return __('No time');
        }

        return $task->end_datetime->translatedFormat('M j · H:i');
    };

    $taskFocusProgressPercent = static function (\App\Models\Task $task): ?int {
        $durationMinutes = (int) ($task->duration ?? 0);
        if ($durationMinutes <= 0) {
            return null;
        }

        $targetSeconds = $durationMinutes * 60;
        $spentSeconds = $task->calculateFocusedWorkSecondsExcludingActive(now());

        return (int) min(100, max(0, round(($spentSeconds / $targetSeconds) * 100)));
    };

    $eventTimeLabel = static function (\App\Models\Event $event): string {
        if ($event->start_datetime === null) {
            return __('No time');
        }

        if ($event->all_day ?? false) {
            return __('All day');
        }

        return $event->start_datetime->translatedFormat('M j · H:i');
    };

    $workspaceListBase = [
        'date' => $workspaceDate,
        'view' => 'list',
    ];

    $doingTasksForDisplay = $doingTasks
        ->map(function (\App\Models\Task $task) use ($taskFocusProgressPercent): array {
            return [
                'task' => $task,
                'progress_percent' => $taskFocusProgressPercent($task),
            ];
        })
        ->sortByDesc(fn (array $item): int => $item['progress_percent'] ?? -1)
        ->take(3)
        ->values();
@endphp

<div data-testid="dashboard-at-a-glance" class="flex flex-col gap-4">
    {{-- Row 1: Selected-day events | Overdue | Due on selected day --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        {{-- Selected-day events --}}
        <div class="rounded-xl border border-indigo-200/55 bg-background shadow-sm ring-1 ring-indigo-500/10 dark:border-indigo-900/40 dark:bg-zinc-900/50 dark:ring-indigo-500/10">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200/45 px-4 py-3 dark:border-indigo-900/45">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:icon name="calendar-days" class="size-4 text-[var(--color-brand-navy-blue)]" />
                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-today-events-heading">
                        {{ __('Selected-day Events') }}
                    </span>
                    <span
                        class="inline-flex min-w-6 items-center justify-center rounded-full bg-[var(--color-brand-light-lavender)] px-2 py-0.5 text-[11px] font-bold tabular-nums text-[var(--color-brand-navy-blue)] dark:bg-indigo-950/50 dark:text-indigo-200"
                        data-testid="dashboard-today-events-count"
                    >
                        {{ $todayEventsCount }}
                    </span>
                </div>
            </div>
            @if ($todayEvents->isEmpty())
                <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No events today.') }}</p>
            @else
                <ul class="max-h-80 space-y-1.5 overflow-y-auto px-3 py-3">
                    @foreach ($todayEvents as $event)
                        <li>
                            <a
                                href="{{ route('workspace', array_merge($workspaceListBase, ['type' => 'events', 'event' => $event->id])) }}"
                                wire:navigate
                                class="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5 transition hover:bg-muted/70"
                                data-testid="dashboard-row-today-event"
                            >
                                <div class="mt-0.5 rounded-md border border-[var(--color-brand-navy-blue)]/25 bg-[var(--color-brand-light-lavender)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--color-brand-navy-blue)]">
                                    <div class="flex items-center gap-1">
                                        <flux:icon name="calendar-days" class="size-3" />
                                        <span>{{ __('Event') }}</span>
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium text-foreground">
                                        {{ $event->title ?: __('Untitled') }}
                                    </p>
                                    <p class="text-[11px] text-muted-foreground">
                                        {{ $eventTimeLabel($event) }}
                                    </p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    {{-- Overdue --}}
    <div class="rounded-xl border border-red-200/55 bg-background shadow-sm ring-1 ring-red-500/8 dark:border-red-900/40 dark:bg-zinc-900/50 dark:ring-red-500/10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-red-200/45 px-4 py-3 dark:border-red-900/45">
            <div class="flex flex-wrap items-center gap-2">
                <flux:icon name="exclamation-triangle" class="size-4 text-red-600 dark:text-red-400" />
                <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-overdue-heading">
                    {{ __('Overdue') }}
                </span>
                <span
                    class="inline-flex min-w-6 items-center justify-center rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-red-800 dark:bg-red-950/60 dark:text-red-200"
                    data-testid="dashboard-overdue-count"
                >
                    {{ $overdueCount }}
                </span>
            </div>
        </div>
        @if ($overdueTasks->isEmpty())
            <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No overdue tasks.') }}</p>
        @else
            <ul class="max-h-64 space-y-1.5 overflow-y-auto px-3 py-3">
                @foreach ($overdueTasks as $task)
                    <li>
                        <a
                            href="{{ route('workspace', array_merge($workspaceListBase, ['type' => 'tasks', 'task' => $task->id])) }}"
                            wire:navigate
                            class="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5 transition hover:bg-muted/70"
                            data-testid="dashboard-row-overdue-task"
                        >
                            <div class="mt-0.5 rounded-md border border-[var(--color-brand-blue)]/30 bg-[var(--color-brand-light-blue)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--color-brand-navy-blue)]">
                                <div class="flex items-center gap-1">
                                    <flux:icon name="check-circle" class="size-3" />
                                    <span>{{ __('Task') }}</span>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium text-foreground">
                                    {{ $task->title ?: __('Untitled') }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ $taskTimeLabel($task) }}
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Due on selected day --}}
    <div class="rounded-xl border border-amber-200/60 bg-background shadow-sm ring-1 ring-amber-400/12 dark:border-amber-900/40 dark:bg-zinc-900/50 dark:ring-amber-500/10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200/45 px-4 py-3 dark:border-amber-900/45">
            <div class="flex flex-wrap items-center gap-2">
                <flux:icon name="sun" class="size-4 text-amber-600 dark:text-amber-400" />
                <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-due-today-heading">
                    {{ __('Due on selected day') }}
                </span>
                <span
                    class="inline-flex min-w-6 items-center justify-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-amber-900 dark:bg-amber-950/60 dark:text-amber-200"
                    data-testid="dashboard-due-today-count"
                >
                    {{ $dueTodayCount }}
                </span>
            </div>
        </div>
        @if ($dueTodayTasks->isEmpty())
            <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('Nothing due on selected day.') }}</p>
        @else
            <ul class="max-h-64 space-y-1.5 overflow-y-auto px-3 py-3">
                @foreach ($dueTodayTasks as $task)
                    <li>
                        <a
                            href="{{ route('workspace', array_merge($workspaceListBase, ['type' => 'tasks', 'task' => $task->id])) }}"
                            wire:navigate
                            class="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5 transition hover:bg-muted/70"
                            data-testid="dashboard-row-due-today-task"
                        >
                            <div class="mt-0.5 rounded-md border border-[var(--color-brand-blue)]/30 bg-[var(--color-brand-light-blue)] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--color-brand-navy-blue)]">
                                <div class="flex items-center gap-1">
                                    <flux:icon name="check-circle" class="size-3" />
                                    <span>{{ __('Task') }}</span>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium text-foreground">
                                    {{ $task->title ?: __('Untitled') }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ $taskTimeLabel($task) }}
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    </div>

    {{-- Row 2: Doing | Urgent Now (slot from dashboard) --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Doing --}}
        <div class="min-w-0 rounded-xl border border-blue-200/55 bg-background shadow-sm ring-1 ring-blue-500/10 dark:border-blue-900/40 dark:bg-zinc-900/50 dark:ring-blue-400/10">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-blue-200/45 px-4 py-3 dark:border-blue-900/45">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:icon name="arrow-path" class="size-4 text-blue-600 dark:text-blue-400" />
                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-doing-heading">
                        {{ __('Doing Tasks') }}
                    </span>
                    <span
                        class="inline-flex min-w-6 items-center justify-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-blue-900 dark:bg-blue-950/60 dark:text-blue-200"
                        data-testid="dashboard-doing-count"
                    >
                        {{ $doingCount }}
                    </span>
                </div>
            </div>
            @if ($doingTasksForDisplay->isEmpty())
                <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No tasks in progress.') }}</p>
            @else
                <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                    @foreach ($doingTasksForDisplay as $taskWithProgress)
                        @php
                            /** @var \App\Models\Task $task */
                            $task = $taskWithProgress['task'];
                            $progressPercent = $taskWithProgress['progress_percent'];
                        @endphp
                        <li>
                            <a
                                href="{{ route('workspace', array_merge($workspaceListBase, ['type' => 'tasks', 'task' => $task->id])) }}"
                                wire:navigate
                                class="flex items-start px-3 py-2.5 transition hover:bg-muted/50"
                                data-testid="dashboard-row-doing-task"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-foreground">
                                        {{ $task->title ?: __('Untitled') }}
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                                        <span class="tabular-nums">{{ \App\Models\Task::formatDuration($task->duration) }}</span>
                                        @if ($task->end_datetime !== null)
                                            <span class="text-muted-foreground/80"> · </span>
                                            <span>{{ $taskTimeLabel($task) }}</span>
                                        @endif
                                        @if ($progressPercent !== null)
                                            <span class="text-muted-foreground/80"> · </span>
                                            <span class="tabular-nums">{{ $progressPercent }}% {{ __('Progress') }}</span>
                                        @endif
                                    </p>
                                    @if ($progressPercent !== null)
                                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Task progress') }}">
                                            <div
                                                class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width] duration-300 ease-linear dark:bg-blue-500"
                                                style="width: {{ $progressPercent }}%; min-width: {{ $progressPercent > 0 ? '2px' : '0' }};"
                                            ></div>
                                        </div>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($doingTasks->count() > 3)
                    <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                        <a
                            href="{{ route('workspace', $workspaceListBase) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                        >
                            <span>{{ __('See all in Workspace') }}</span>
                            <flux:icon name="arrow-right" class="size-3.5" />
                        </a>
                    </div>
                @endif
            @endif
        </div>

        {{ $urgentNow }}
    </div>
</div>
