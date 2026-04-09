@props([
    'overdueTasks',
    'doingTasks',
    'dueTodayTasks',
    'todayEvents',
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
    {{-- Row 1: Overdue | Due today --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        {{-- Overdue tasks --}}
        <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon name="exclamation-triangle" class="size-4 text-red-600 dark:text-red-400" />
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground" data-testid="dashboard-section-overdue-heading">
                        {{ __('Overdue') }}
                    </span>
                </div>
                <flux:button variant="ghost" size="sm" :href="$workspaceUrl" wire:navigate class="text-xs font-semibold">
                    {{ __('View in workspace') }}
                </flux:button>
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
                <div class="flex items-center gap-2">
                    <flux:icon name="sun" class="size-4 text-amber-600 dark:text-amber-400" />
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground" data-testid="dashboard-section-due-today-heading">
                        {{ __('Due today') }}
                    </span>
                </div>
                <flux:button variant="ghost" size="sm" :href="$workspaceUrl" wire:navigate class="text-xs font-semibold">
                    {{ __('View in workspace') }}
                </flux:button>
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

    {{-- Row 2: Doing | Today's events --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        {{-- Doing --}}
        <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon name="arrow-path" class="size-4 text-blue-600 dark:text-blue-400" />
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground" data-testid="dashboard-section-doing-heading">
                        {{ __('Doing') }}
                    </span>
                </div>
                <flux:button variant="ghost" size="sm" :href="$workspaceUrl" wire:navigate class="text-xs font-semibold">
                    {{ __('View in workspace') }}
                </flux:button>
            </div>
            @if ($doingTasks->isEmpty())
                <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No tasks in Doing.') }}</p>
            @else
                <ul class="max-h-[28rem] space-y-3 overflow-y-auto px-3 py-3">
                    @foreach ($doingTasks as $task)
                        @php
                            $progressPercent = $taskFocusProgressPercent($task);
                            $barTone = $loop->iteration % 2 === 1
                                ? 'bg-violet-600 dark:bg-violet-500'
                                : 'bg-rose-400 dark:bg-rose-400';
                        @endphp
                        <li>
                            <a
                                href="{{ $workspaceUrl }}"
                                wire:navigate
                                class="block rounded-xl border border-border/60 bg-background p-4 shadow-sm ring-1 ring-border/10 transition hover:border-border hover:shadow dark:bg-zinc-950/80"
                                data-testid="dashboard-row-doing-task"
                            >
                                <p class="truncate text-base font-bold text-foreground">
                                    {{ $task->title ?: __('Untitled') }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    <span class="font-medium text-foreground/80">{{ __('Duration') }}</span>
                                    <span class="tabular-nums"> · {{ \App\Models\Task::formatDuration($task->duration) }}</span>
                                </p>

                                <div class="mt-4 w-full">
                                    @if ($progressPercent !== null)
                                        <div
                                            class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                            role="progressbar"
                                            aria-valuenow="{{ $progressPercent }}"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                            aria-label="{{ __('Task progress') }}"
                                        >
                                            <div
                                                class="h-full min-w-0 rounded-full {{ $barTone }}"
                                                style="width: {{ $progressPercent }}%; min-width: {{ $progressPercent > 0 ? '2px' : '0' }}"
                                            ></div>
                                        </div>
                                        <p class="mt-2 text-xs text-muted-foreground">
                                            {{ $progressPercent }}% {{ __('Complete') }}
                                        </p>
                                    @else
                                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                            <div class="h-full w-0 rounded-full bg-zinc-300 dark:bg-zinc-600"></div>
                                        </div>
                                        <p class="mt-2 text-xs text-muted-foreground">
                                            {{ __('Set a duration in the workspace to track progress.') }}
                                        </p>
                                    @endif
                                </div>

                                <div
                                    class="mt-4 flex flex-wrap items-start justify-between gap-4 border-t border-border/60 pt-3 dark:border-zinc-800"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs text-muted-foreground">{{ __('Start Date') }}</p>
                                        <p class="text-sm font-semibold text-foreground">
                                            {{ $task->start_datetime?->translatedFormat('j M') ?? '—' }}
                                        </p>
                                    </div>
                                    <div class="min-w-0 flex-1 text-right sm:text-right">
                                        <p class="text-xs text-muted-foreground">{{ __('End Date') }}</p>
                                        <p class="text-sm font-semibold text-foreground">
                                            {{ $task->end_datetime?->translatedFormat('j M') ?? '—' }}
                                        </p>
                                    </div>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Today's events --}}
        <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar-days" class="size-4 text-[var(--color-brand-navy-blue)]" />
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground" data-testid="dashboard-section-today-events-heading">
                        {{ __("Today's events") }}
                    </span>
                </div>
                <flux:button variant="ghost" size="sm" :href="$workspaceUrl" wire:navigate class="text-xs font-semibold">
                    {{ __('View in workspace') }}
                </flux:button>
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
    </div>
</div>
