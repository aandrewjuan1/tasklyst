@props([
    'overdueTasks',
    'overdueCount' => 0,
    'dueTodayTasks',
    'dueTodayCount' => 0,
    'doingTasks',
    'doingCount' => 0,
    'todayEvents',
    'todayEventsCount' => 0,
    'workspaceUrl',
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
@endphp

<div data-testid="dashboard-at-a-glance" class="flex flex-col gap-4">
    {{-- Row 1: Today's events | Overdue | Due today --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        {{-- Today's events --}}
        <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:icon name="calendar-days" class="size-4 text-[var(--color-brand-navy-blue)]" />
                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-today-events-heading">
                        {{ __("Today's Events") }}
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
                                href="{{ $workspaceUrl }}"
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
    <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
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
                            href="{{ $workspaceUrl }}"
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

    {{-- Due today --}}
    <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
            <div class="flex flex-wrap items-center gap-2">
                <flux:icon name="sun" class="size-4 text-amber-600 dark:text-amber-400" />
                <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-due-today-heading">
                    {{ __('Due Today') }}
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
            <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('Nothing due today.') }}</p>
        @else
            <ul class="max-h-64 space-y-1.5 overflow-y-auto px-3 py-3">
                @foreach ($dueTodayTasks as $task)
                    <li>
                        <a
                            href="{{ $workspaceUrl }}"
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

    {{-- Row 2: Doing | Status breakdown --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        {{-- Doing --}}
        <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:icon name="arrow-path" class="size-4 text-blue-600 dark:text-blue-400" />
                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-doing-heading">
                        {{ __('Doing') }}
                    </span>
                    <span
                        class="inline-flex min-w-6 items-center justify-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-blue-900 dark:bg-blue-950/60 dark:text-blue-200"
                        data-testid="dashboard-doing-count"
                    >
                        {{ $doingCount }}
                    </span>
                </div>
            </div>
            @if ($doingTasks->isEmpty())
                <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No tasks in progress.') }}</p>
            @else
                <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                    @foreach ($doingTasks as $task)
                        @php
                            $progressPercent = $taskFocusProgressPercent($task);
                        @endphp
                        <li>
                            <a
                                href="{{ $workspaceUrl }}"
                                wire:navigate
                                class="flex items-start gap-2 px-3 py-2.5 transition hover:bg-muted/50"
                                data-testid="dashboard-row-doing-task"
                            >
                                <div class="mt-0.5 shrink-0 rounded-md border border-blue-600/25 bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-900 dark:border-blue-500/30 dark:bg-blue-950/40 dark:text-blue-200">
                                    <div class="flex items-center gap-1">
                                        <flux:icon name="arrow-path" class="size-3" />
                                        <span>{{ __('Doing') }}</span>
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium text-foreground">
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
            @endif
        </div>

        {{-- Status breakdown --}}
        <div class="min-w-0 overflow-hidden rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="border-b border-border/60 px-3 py-2">
                <div class="font-primary text-base font-bold text-foreground">
                    {{ __('Task Status Summary') }}
                </div>
            </div>
            <div class="px-2 py-3 sm:px-3">
                <div
                    x-ref="statusChart"
                    wire:ignore
                    class="mx-auto h-56 min-h-[220px] w-full"
                ></div>
            </div>
        </div>
    </div>
</div>
