@php
    $analytics = $this->analytics;
    $urgentNow = $this->urgentNow;
    $urgentNowDisplayed = $this->urgentNowDisplayed;
    $urgentNowHasMore = $this->urgentNowHasMore;
    $projectHealth = $this->projectHealth;
    $focusThroughput = $this->focusThroughput;
    $calendarLoadInsights = $this->calendarLoadInsights;
    $collaborationPulseCounts = $this->collaborationPulseCounts;
    $collaborationInboxInvites = $this->collaborationInboxInvites;
    $collaborationPulseRecentActivity = $this->collaborationPulseRecentActivity;
    $llmActivity = $this->llmActivity;
    $recurringSummary = $this->dashboardRecurringSummary;
    $assistantQuickActions = [
        __('Prioritize my tasks for today'),
        __('Suggest focus blocks around my events'),
        __('Summarize what I should do next'),
    ];
    $topKpis = [
        [
            'key' => 'overdue',
            'label' => __('Overdue'),
            'value' => $this->dashboardOverdueTasksCount,
            'icon' => 'exclamation-triangle',
            'shell' => 'border-red-200/55 ring-red-500/10 dark:border-red-900/40',
            'icon_shell' => 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-200',
        ],
        [
            'key' => 'due_today',
            'label' => __('Due on selected day'),
            'value' => $this->dashboardDueTodayTasksCount,
            'icon' => 'sun',
            'shell' => 'border-amber-200/55 ring-amber-500/10 dark:border-amber-900/40',
            'icon_shell' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-200',
        ],
        [
            'key' => 'doing',
            'label' => __('Doing tasks'),
            'value' => $this->dashboardDoingTasksCount,
            'icon' => 'arrow-path',
            'shell' => 'border-blue-200/55 ring-blue-500/10 dark:border-blue-900/40',
            'icon_shell' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-200',
        ],
    ];

    $riskBadgeClass = static function (string $risk): string {
        return match ($risk) {
            'Critical' => 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
            'At Risk' => 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
            default => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200',
        };
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
    $doingTasksForDisplay = $this->dashboardDoingTasks
        ->map(function (\App\Models\Task $task) use ($taskFocusProgressPercent): array {
            return [
                'task' => $task,
                'progress_percent' => $taskFocusProgressPercent($task),
            ];
        })
        ->sortByDesc(fn (array $item): int => $item['progress_percent'] ?? -1)
        ->take(5)
        ->values();
    $dashboardPanelShell = [
        'default' => 'rounded-xl border border-border/70 bg-background shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5',
        'urgent' => 'min-w-0 rounded-xl border border-red-200/55 bg-background shadow-sm ring-1 ring-red-500/8 dark:border-red-900/40 dark:bg-zinc-900/50 dark:ring-red-500/10',
    ];

    $dashboardPanelHeaderBorder = [
        'default' => 'border-b border-border/60 dark:border-zinc-800',
        'urgent' => 'border-b border-red-200/45 dark:border-red-900/45',
    ];
@endphp

<section class="space-y-6">
    <div class="grid w-full gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(260px,1fr)]">
        <div class="min-w-0 space-y-4">
            <section
                x-data="dashboardAnalyticsCharts({ analytics: @js($analytics), preset: @js($this->analyticsPreset) })"
                x-effect="sync(@js($this->analytics), @js($this->analyticsPreset))"
                class="flex h-full w-full flex-1 flex-col gap-4"
            >
                <div class="min-h-0 flex-1 space-y-4">
                    <div class="{{ $dashboardPanelShell['default'] }} p-4 sm:p-5">
                        @php
                            $greetingName = auth()->user()?->firstName() ?? '';
                        @endphp
                        <div class="flex w-full min-w-0 items-start justify-between gap-4">
                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                                    @if ($greetingName !== '')
                                        {{ __('Dashboard — Hello, :name!', ['name' => $greetingName]) }}
                                    @else
                                        {{ __('Dashboard — Hello!') }}
                                    @endif
                                </p>
                                <h2 class="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                                    {{ __('Focus on what needs attention right now.') }}
                                </h2>
                            </div>
                            <x-notifications.bell-cluster variant="hero" />
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <a
                                href="{{ $this->workspaceUrlForToday }}"
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                            >
                                <span>{{ __('Open workspace') }}</span>
                                <flux:icon name="arrow-right" class="size-4" />
                            </a>
                            @auth
                                <flux:modal.trigger name="task-assistant-chat">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-xl border border-border px-4 py-2 text-sm font-semibold text-foreground transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 dark:border-zinc-700"
                                    >
                                        <flux:icon name="sparkles" class="size-4" />
                                        <span>{{ __('Ask AI assistant') }}</span>
                                    </button>
                                </flux:modal.trigger>
                            @endauth
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($topKpis as $kpi)
                            <div class="rounded-xl border bg-background p-3 shadow-sm ring-1 sm:p-4 {{ $kpi['shell'] }}" data-testid="dashboard-kpi-{{ $kpi['key'] }}">
                                <div class="flex items-center gap-2">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $kpi['icon_shell'] }}">
                                        <flux:icon name="{{ $kpi['icon'] }}" class="size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-muted-foreground">{{ $kpi['label'] }}</p>
                                        <p class="text-2xl font-bold tabular-nums leading-none text-foreground" data-testid="dashboard-kpi-{{ $kpi['key'] }}-value">{{ $kpi['value'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @auth
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                            <div class="{{ $dashboardPanelShell['urgent'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['urgent'] }}">
                                    <flux:icon name="bolt" class="size-4 text-red-600 dark:text-red-400" />
                                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-urgent-now-heading">
                                        {{ __('Urgent Now') }}
                                    </span>
                                </div>
                                @if ($urgentNow->isEmpty())
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No urgent items right now.') }}</p>
                                @else
                                    <ul class="divide-y divide-border/60 dark:divide-zinc-800">
                                        @foreach ($urgentNowDisplayed as $row)
                                            <li class="px-4 py-3" data-testid="dashboard-row-urgent-item">
                                                <a href="{{ $row['workspace_url'] }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                    <div class="min-w-0 space-y-1">
                                                        <p class="truncate text-sm font-semibold text-foreground">{{ $row['title'] }}</p>
                                                        <p class="text-xs text-muted-foreground">{{ $row['reasoning'] }}</p>
                                                        @if (! empty($row['ends_at']))
                                                            <p class="text-xs text-muted-foreground">
                                                                {{ __('Due: :date', ['date' => \Carbon\Carbon::parse($row['ends_at'])->translatedFormat('M j · H:i')]) }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($urgentNowHasMore)
                                        <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                                            <a
                                                href="{{ $this->workspaceUrlForToday }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                                                data-testid="dashboard-urgent-now-see-all"
                                            >
                                                <span>{{ __('See all in Workspace') }}</span>
                                                <flux:icon name="arrow-right" class="size-3.5" />
                                            </a>
                                        </div>
                                    @endif
                                @endif
                            </div>

                            <div class="{{ $dashboardPanelShell['default'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                    <flux:icon name="arrow-path" class="size-4 text-foreground/80" />
                                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-doing-heading">
                                        {{ __('Doing Tasks') }}
                                    </span>
                                    <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-doing-count">
                                        {{ $this->dashboardDoingTasksCount }}
                                    </span>
                                </div>
                                @if ($doingTasksForDisplay->isEmpty())
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No tasks in progress.') }}</p>
                                @else
                                    <ul class="max-h-72 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                                        @foreach ($doingTasksForDisplay as $taskWithProgress)
                                            @php
                                                /** @var \App\Models\Task $task */
                                                $task = $taskWithProgress['task'];
                                                $progressPercent = $taskWithProgress['progress_percent'];
                                            @endphp
                                            <li class="px-4 py-3" data-testid="dashboard-row-doing-task">
                                                <a
                                                    href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list', 'type' => 'tasks', 'task' => $task->id]) }}"
                                                    wire:navigate
                                                    class="block rounded-md transition hover:bg-muted/40"
                                                >
                                                    <p class="truncate text-sm font-semibold text-foreground">
                                                        {{ $task->title ?: __('Untitled') }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-muted-foreground">
                                                        <span class="tabular-nums">{{ \App\Models\Task::formatDuration($task->duration) }}</span>
                                                        @if ($task->end_datetime !== null)
                                                            <span class="text-muted-foreground/80"> · </span>
                                                            <span>{{ $task->end_datetime->translatedFormat('M j · H:i') }}</span>
                                                        @endif
                                                        @if ($progressPercent !== null)
                                                            <span class="text-muted-foreground/80"> · </span>
                                                            <span class="tabular-nums">{{ $progressPercent }}% {{ __('Progress') }}</span>
                                                        @endif
                                                    </p>
                                                    @if ($progressPercent !== null)
                                                        <div
                                                            class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                                            role="progressbar"
                                                            aria-valuenow="{{ $progressPercent }}"
                                                            aria-valuemin="0"
                                                            aria-valuemax="100"
                                                            aria-label="{{ __('Task progress') }}"
                                                        >
                                                            <div
                                                                class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width] duration-300 ease-linear dark:bg-blue-500"
                                                                style="width: {{ $progressPercent }}%; min-width: {{ $progressPercent > 0 ? '2px' : '0' }};"
                                                            ></div>
                                                        </div>
                                                    @endif
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                                        <a
                                            href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list']) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                                            data-testid="dashboard-doing-see-all"
                                        >
                                            <span>{{ __('Open in Workspace') }}</span>
                                            <flux:icon name="arrow-right" class="size-3.5" />
                                        </a>
                                    </div>
                                @endif
                            </div>

                            <div class="{{ $dashboardPanelShell['default'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                    <flux:icon name="calendar-days" class="size-4 text-foreground/80" />
                                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-calendar-load-heading">{{ __('Calendar load (next 24h)') }}</span>
                                </div>
                                <div class="space-y-2 px-4 py-3">
                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-muted-foreground">{{ __('Events') }}</p>
                                                    <p class="text-base font-bold text-foreground" data-testid="dashboard-calendar-events-in-window">{{ $calendarLoadInsights['events_in_window'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-muted-foreground">{{ __('Conflicts') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $calendarLoadInsights['overlap_conflicts'] }}</p>
                                        </div>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ __('Busy :busy min · Free :free min · All-day :allDay', ['busy' => $calendarLoadInsights['busy_minutes'], 'free' => $calendarLoadInsights['free_minutes'], 'allDay' => $calendarLoadInsights['all_day_events']]) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endauth

                    @auth
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="{{ $dashboardPanelShell['default'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                    <flux:icon name="arrow-path" class="size-4 text-foreground/80" />
                                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-recurring-heading">{{ __('Repeating tasks on selected day') }}</span>
                                    <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-recurring-due-count">{{ $recurringSummary['due'] }}</span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-xs text-muted-foreground">{{ __('Due') }}</p>
                                        <p class="text-base font-bold text-foreground" data-testid="dashboard-recurring-due-count-value">{{ $recurringSummary['due'] }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-xs text-muted-foreground">{{ __('Completed') }}</p>
                                        <p class="text-base font-bold text-foreground" data-testid="dashboard-recurring-completed-count-value">{{ $recurringSummary['completed'] }}</p>
                                    </div>
                                </div>
                                <div class="border-b border-border/60 px-4 py-2 dark:border-zinc-800">
                                    <p class="text-xs font-semibold text-muted-foreground" data-testid="dashboard-recurring-streak-days-value">
                                        {{ __('Completion streak: :days day(s)', ['days' => $recurringSummary['streak_days']]) }}
                                    </p>
                                </div>
                                @if ($this->dashboardRecurringDueTasks->isEmpty())
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No repeating tasks due on selected day.') }}</p>
                                @else
                                    <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                                        @foreach ($this->dashboardRecurringDueTasks as $task)
                                            <li class="px-4 py-2.5" data-testid="dashboard-row-recurring-task">
                                                <a href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list', 'type' => 'tasks', 'task' => $task->id]) }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                    <p class="truncate text-sm font-semibold text-foreground">{{ $task->title }}</p>
                                                    <p class="text-xs text-muted-foreground">
                                                        {{ __('Due: :time', ['time' => $task->end_datetime?->translatedFormat('H:i') ?? __('No time')]) }}
                                                        ·
                                                        {{ ucfirst($task->recurringTask?->recurrence_type?->value ?? __('Repeating')) }}
                                                    </p>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <div class="{{ $dashboardPanelShell['default'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                    <flux:icon name="clock" class="size-4 text-foreground/80" />
                                    <span class="text-sm font-semibold text-foreground">{{ __('No-date Backlog') }}</span>
                                    <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-no-date-backlog-count">{{ $this->dashboardNoDateBacklogCount }}</span>
                                </div>
                                @if ($this->dashboardNoDateBacklogTasks->isEmpty())
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No no-date tasks right now.') }}</p>
                                @else
                                    <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                                        @foreach ($this->dashboardNoDateBacklogTasks as $task)
                                            <li class="px-4 py-2.5">
                                                <a href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list', 'type' => 'tasks', 'task' => $task->id]) }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                    <p class="truncate text-sm font-semibold text-foreground">{{ $task->title }}</p>
                                                    <p class="text-xs text-muted-foreground">{{ $task->project?->name ?? __('No project') }}</p>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    @endauth

                    <div x-data="{ insightsOpen: false }" class="{{ $dashboardPanelShell['default'] }}">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-4 py-3 text-left"
                            @click="insightsOpen = !insightsOpen"
                            :aria-expanded="insightsOpen.toString()"
                        >
                            <div class="flex items-center gap-2">
                                <flux:icon name="chart-bar" class="size-4 text-foreground/80" />
                                <span class="text-sm font-semibold text-foreground">{{ __('Show insights') }}</span>
                            </div>
                            <flux:icon name="chevron-down" class="size-4 transition-transform" x-bind:class="insightsOpen ? 'rotate-180' : ''" />
                        </button>

                        <div x-cloak x-show="insightsOpen" x-transition class="space-y-4 border-t border-border/60 p-4 dark:border-zinc-800">
                            @auth

                                <div class="{{ $dashboardPanelShell['default'] }}">
                                    <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                        <flux:icon name="bolt" class="size-4 text-foreground/80" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-focus-throughput-heading">{{ __('Focus + Throughput') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 px-4 py-3">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus on selected day') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['daily_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus this week') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['weekly_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Completed on selected day') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['completed_today'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus / completion') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['focus_per_completed_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="{{ $dashboardPanelShell['default'] }}">
                                    <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                        <flux:icon name="briefcase" class="size-4 text-foreground/80" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-project-health-heading">
                                            {{ __('Project Health') }}
                                        </span>
                                    </div>
                                    @if ($projectHealth->isEmpty())
                                        <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No active projects yet.') }}</p>
                                    @else
                                        <div class="overflow-x-auto">
                                            <table class="min-w-full text-left text-xs">
                                                <thead class="bg-muted/40 text-muted-foreground">
                                                    <tr>
                                                        <th class="px-4 py-2.5 font-semibold">{{ __('Project') }}</th>
                                                        <th class="px-4 py-2.5 font-semibold">{{ __('Progress') }}</th>
                                                        <th class="px-4 py-2.5 font-semibold">{{ __('Overdue') }}</th>
                                                        <th class="px-4 py-2.5 font-semibold">{{ __('Nearest deadline') }}</th>
                                                        <th class="px-4 py-2.5 font-semibold">{{ __('Risk') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-border/60 dark:divide-zinc-800">
                                                    @foreach ($projectHealth as $project)
                                                        <tr data-testid="dashboard-row-project-health">
                                                            <td class="px-4 py-2.5">
                                                                <a href="{{ $project['workspace_url'] }}" wire:navigate class="truncate font-semibold text-foreground transition hover:text-brand-blue">
                                                                    {{ $project['name'] }}
                                                                </a>
                                                                <p class="text-xs text-muted-foreground">
                                                                    {{ __(':incomplete of :total open', ['incomplete' => $project['incomplete_tasks'], 'total' => $project['total_tasks']]) }}
                                                                </p>
                                                            </td>
                                                            <td class="px-4 py-2.5">
                                                                <p class="font-semibold text-foreground">{{ $project['completion_rate'] }}%</p>
                                                                <div class="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                                                    <div
                                                                        class="h-full rounded-full bg-emerald-600 dark:bg-emerald-500"
                                                                        style="width: {{ $project['completion_rate'] }}%;"
                                                                    ></div>
                                                                </div>
                                                            </td>
                                                            <td class="px-4 py-2.5 font-semibold {{ $project['overdue_tasks'] > 0 ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground' }}">
                                                                {{ $project['overdue_tasks'] }}
                                                            </td>
                                                            <td class="px-4 py-2.5 text-muted-foreground">
                                                                {{ $project['nearest_deadline'] ? \Carbon\Carbon::parse($project['nearest_deadline'])->translatedFormat('M j') : __('No deadline') }}
                                                            </td>
                                                            <td class="px-4 py-2.5">
                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $riskBadgeClass($project['risk']) }}">
                                                                    {{ __($project['risk']) }}
                                                                </span>
                                                                <p class="mt-1 text-xs text-muted-foreground">{{ $project['risk_reason'] }}</p>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>

                                <div class="{{ $dashboardPanelShell['default'] }}">
                                    <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                        <flux:icon name="sparkles" class="size-4 text-foreground/80" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-llm-activity-heading">{{ __('LLM Assistant Activity') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 px-4 py-3">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Threads') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $llmActivity['total_threads'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Recent threads') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $llmActivity['recent_threads'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Tool calls success') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $llmActivity['successful_tool_calls'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Tool calls pending/failed') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $llmActivity['pending_tool_calls'] }} / {{ $llmActivity['failed_tool_calls'] }}</p>
                                        </div>
                                    </div>
                                    <div class="border-t border-border/60 px-4 py-3 dark:border-zinc-800">
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Quick actions') }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($assistantQuickActions as $action)
                                                <span class="inline-flex items-center rounded-full border border-zinc-300/80 px-2.5 py-1 text-xs font-semibold text-foreground dark:border-zinc-700">
                                                    {{ $action }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endauth

                            @if ($analytics)
                                @island(name: 'dashboard-trend')
                                    <div
                                        x-data="dashboardAnalyticsCharts({ analytics: @js($this->trendAnalytics), preset: @js($this->trendPreset) })"
                                        x-effect="sync(@js($this->trendAnalytics), @js($this->trendPreset))"
                                        wire:key="dashboard-trend-{{ $this->selectedDate }}-{{ $this->trendPreset }}"
                                        class="overflow-hidden rounded-xl border border-border/70 bg-background shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 px-3 py-2 dark:border-zinc-800">
                                            <div class="text-sm font-semibold text-foreground">{{ __('Trend') }}</div>
                                            <div class="inline-flex items-center gap-1 rounded-lg bg-muted p-1">
                                                @foreach (['daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly')] as $presetValue => $presetLabel)
                                                    <button
                                                        type="button"
                                                        wire:click="setTrendPreset('{{ $presetValue }}')"
                                                        wire:island="dashboard-trend"
                                                        class="{{ $this->trendPreset === $presetValue
                                                            ? 'bg-background text-foreground shadow-sm'
                                                            : 'text-muted-foreground hover:text-foreground' }} rounded-md px-2.5 py-1 text-xs font-semibold transition"
                                                    >
                                                        {{ $presetLabel }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 gap-4 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-6">
                                            <div class="min-w-0 space-y-1">
                                                <div class="text-sm font-semibold text-foreground">{{ __('Tasks') }}</div>
                                                <div x-ref="trendChart" wire:ignore class="h-64 min-h-[240px] w-full"></div>
                                            </div>
                                            <div class="min-w-0 space-y-2 border-t border-border/60 pt-4 dark:border-zinc-800 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                                                <div class="text-sm font-semibold text-foreground">{{ __('Focus') }}</div>
                                                <div x-ref="focusSessionsChart" wire:ignore class="h-64 min-h-[240px] w-full"></div>
                                            </div>
                                        </div>
                                    </div>
                                @endisland
                            @else
                                <p class="text-sm text-muted-foreground">{{ __('No analytics to show.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="hidden lg:block lg:min-w-[260px]">
            <div class="sticky top-6" data-focus-lock-viewport>
                <x-workspace.calendar
                    agenda-context="dashboard"
                    :selected-date="$this->selectedDate"
                    :current-month="$this->calendarMonth"
                    :current-year="$this->calendarYear"
                    :month-meta="$this->calendarMonthMeta"
                    :selected-day-agenda="$this->selectedDayAgenda"
                />

                @auth
                    <div class="mt-4">
                        <x-workspace.calendar-feeds-popover />
                    </div>
                @endauth
            </div>
        </div>
    </div>

</section>
