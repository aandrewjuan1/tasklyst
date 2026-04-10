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
    $cardOrder = [
        'total_tasks',
        'todo_tasks',
        'recurring_due',
        'tasks_completed',
    ];
    $cardLabels = [
        'total_tasks' => __('Total tasks'),
        'todo_tasks' => __('To-Do Tasks'),
        'tasks_created' => __('Tasks created'),
        'tasks_completed' => __('Tasks completed'),
        'completion_rate' => __('Completion rate'),
        'recurring_due' => __('Repeating tasks due'),
        'focus_work_seconds' => __('Focus time'),
        'focus_sessions' => __('Focus sessions'),
    ];
    $cardIcons = [
        'total_tasks' => 'squares-2x2',
        'todo_tasks' => 'clipboard-document-list',
        'tasks_created' => 'plus-circle',
        'tasks_completed' => 'check-circle',
        'completion_rate' => 'chart-pie',
        'recurring_due' => 'arrow-path',
        'focus_work_seconds' => 'bolt',
        'focus_sessions' => 'circle-stack',
    ];
    $cardIconColorClasses = [
        'total_tasks' => 'bg-sky-100 text-sky-800 dark:bg-sky-950/50 dark:text-sky-200',
        'todo_tasks' => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200',
        'tasks_created' => 'bg-emerald-100 text-emerald-700',
        'tasks_completed' => 'bg-green-100 text-green-700',
        'completion_rate' => 'bg-violet-100 text-violet-700',
        'recurring_due' => 'bg-teal-100 text-teal-700 dark:bg-teal-950/50 dark:text-teal-200',
        'focus_work_seconds' => 'bg-orange-100 text-orange-700',
        'focus_sessions' => 'bg-cyan-100 text-cyan-700',
    ];

    $riskBadgeClass = static function (string $risk): string {
        return match ($risk) {
            'Critical' => 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
            'At Risk' => 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
            default => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200',
        };
    };


    $panelCtaClass = 'inline-flex items-center gap-1 rounded-md px-1 text-xs font-semibold text-brand-blue transition hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50';
    $panelCtaIconClass = 'size-3.5';

    $summaryCardShellClasses = [
        'total_tasks' => 'rounded-xl border border-sky-200/70 bg-linear-to-br from-sky-50/60 via-background to-brand-purple/8 p-3 shadow-sm ring-1 ring-sky-400/15 sm:p-4 dark:border-sky-800/45 dark:from-sky-950/25 dark:ring-sky-500/10',
        'todo_tasks' => 'rounded-xl border border-zinc-200/80 bg-linear-to-br from-zinc-50/50 via-background to-brand-blue/5 p-3 shadow-sm ring-1 ring-zinc-400/12 sm:p-4 dark:border-zinc-700/55 dark:from-zinc-950/30 dark:ring-zinc-500/8',
        'tasks_completed' => 'rounded-xl border border-emerald-200/65 bg-linear-to-br from-emerald-50/50 via-background to-brand-green/8 p-3 shadow-sm ring-1 ring-emerald-400/15 sm:p-4 dark:border-emerald-800/40 dark:from-emerald-950/20 dark:ring-emerald-500/10',
        'completion_rate' => 'rounded-xl border border-violet-200/60 bg-linear-to-br from-violet-50/45 via-background to-brand-purple/12 p-3 shadow-sm ring-1 ring-violet-400/15 sm:p-4 dark:border-violet-800/40 dark:from-violet-950/20 dark:ring-violet-500/10',
        'recurring_due' => 'rounded-xl border border-teal-200/65 bg-linear-to-br from-teal-50/45 via-background to-brand-green/10 p-3 shadow-sm ring-1 ring-teal-400/15 sm:p-4 dark:border-teal-800/45 dark:from-teal-950/20 dark:ring-teal-500/10',
    ];

    $dashboardPanelShell = [
        'urgent' => 'min-w-0 rounded-xl border border-red-200/55 bg-background shadow-sm ring-1 ring-red-500/8 dark:border-red-900/40 dark:bg-zinc-900/50 dark:ring-red-500/10',
        'projects' => 'rounded-xl border border-emerald-200/55 bg-background shadow-sm ring-1 ring-emerald-500/10 dark:border-emerald-900/40 dark:bg-zinc-900/50 dark:ring-emerald-500/10',
        'collab' => 'rounded-xl border border-violet-200/55 bg-background shadow-sm ring-1 ring-violet-500/10 dark:border-violet-900/40 dark:bg-zinc-900/50 dark:ring-violet-500/10',
        'trend' => 'rounded-xl border border-indigo-200/50 bg-background shadow-sm ring-1 ring-indigo-500/10 dark:border-indigo-900/40 dark:ring-indigo-500/10',
    ];

    $dashboardPanelHeaderBorder = [
        'urgent' => 'border-b border-red-200/45 dark:border-red-900/45',
        'projects' => 'border-b border-emerald-200/45 dark:border-emerald-900/45',
        'collab' => 'border-b border-violet-200/45 dark:border-violet-900/45',
        'trend' => 'border-b border-indigo-200/40 dark:border-indigo-900/45',
    ];
@endphp

<section class="space-y-6">
    {{-- Main Content: 80/20 Split Layout --}}
    <div class="grid w-full gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(260px,1fr)]">
        {{-- Left Side: Analytics (80%) --}}
        <div class="min-w-0 space-y-4">
            <section
                x-data="dashboardAnalyticsCharts({ analytics: @js($analytics), preset: @js($this->analyticsPreset) })"
                x-effect="sync(@js($this->analytics), @js($this->analyticsPreset))"
                class="flex h-full w-full flex-1 flex-col gap-4"
            >
                <div class="min-h-0 flex-1">
                    <div class="flex flex-col gap-4">
                        @if ($analytics)
                            <div class="relative flex min-h-56 w-full items-center overflow-hidden rounded-2xl border border-brand-blue/25 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15 px-5 py-5 shadow-sm ring-1 ring-brand-purple/15 lg:min-h-60 lg:px-7 dark:ring-brand-purple/20">
                                <div class="relative z-10 max-w-xl space-y-2">
                                    @php
                                        $greetingName = auth()->user()->firstName();
                                    @endphp
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-blue/90 sm:text-sm">
                                        @if ($greetingName !== '')
                                            {{ __('Dashboard — Hello, :name!', ['name' => $greetingName]) }}
                                        @else
                                            {{ __('Dashboard — Hello!') }}
                                        @endif
                                    </p>
                                    <h2 class="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                        {{ __('Plan smarter, execute faster, and finish what matters.') }}
                                    </h2>
                                    <p class="max-w-lg text-sm text-muted-foreground sm:text-base">
                                        {{ __('Tasklyst uses AI-powered task prioritization and smart scheduling to help you focus on the right work at the right time.') }}
                                    </p>
                                    <div class="pt-2 flex flex-wrap items-center gap-2">
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
                                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                                >
                                                    <flux:icon name="sparkles" class="size-4" />
                                                    <span>{{ __('Ask AI assistant') }}</span>
                                                </button>
                                            </flux:modal.trigger>
                                        @endauth
                                    </div>
                                </div>
                                <div class="pointer-events-none absolute -right-4 -top-4 flex size-48 items-center justify-center rounded-full bg-brand-blue/15 blur-2xl"></div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($cardOrder as $key)
                                    @if ($key === 'total_tasks')
                                        <div
                                            class="{{ $summaryCardShellClasses['total_tasks'] }}"
                                            data-testid="dashboard-summary-total-tasks"
                                        >
                                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                                @if (isset($cardIcons[$key]))
                                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg sm:size-9 {{ $cardIconColorClasses[$key] ?? 'bg-zinc-100 text-zinc-700' }}">
                                                        <flux:icon name="{{ $cardIcons[$key] }}" class="size-6" />
                                                    </div>
                                                @endif
                                                <div class="flex min-w-0 items-center gap-2">
                                                    <span
                                                        class="shrink-0 text-xl font-bold tabular-nums leading-none text-foreground sm:text-2xl"
                                                        data-testid="dashboard-summary-total-tasks-value"
                                                    >
                                                        {{ $this->dashboardIncompleteTasksCount }}
                                                    </span>
                                                    <span class="truncate text-xs font-semibold text-foreground sm:text-sm">{{ $cardLabels[$key] ?? $key }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @elseif ($key === 'todo_tasks')
                                        <div
                                            class="{{ $summaryCardShellClasses['todo_tasks'] }}"
                                            data-testid="dashboard-summary-todo-tasks"
                                        >
                                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                                @if (isset($cardIcons[$key]))
                                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg sm:size-9 {{ $cardIconColorClasses[$key] ?? 'bg-zinc-100 text-zinc-700' }}">
                                                        <flux:icon name="{{ $cardIcons[$key] }}" class="size-6" />
                                                    </div>
                                                @endif
                                                <div class="flex min-w-0 items-center gap-2">
                                                    <span
                                                        class="shrink-0 text-xl font-bold tabular-nums leading-none text-foreground sm:text-2xl"
                                                        data-testid="dashboard-summary-todo-tasks-value"
                                                    >
                                                        {{ $this->dashboardTodoTasksCount }}
                                                    </span>
                                                    <span class="truncate text-xs font-semibold text-foreground sm:text-sm">{{ $cardLabels[$key] ?? $key }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @elseif ($key === 'recurring_due')
                                        <div
                                            class="{{ $summaryCardShellClasses['recurring_due'] }}"
                                            data-testid="dashboard-summary-recurring-due"
                                        >
                                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                                @if (isset($cardIcons[$key]))
                                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg sm:size-9 {{ $cardIconColorClasses[$key] ?? 'bg-zinc-100 text-zinc-700' }}">
                                                        <flux:icon name="{{ $cardIcons[$key] }}" class="size-6" />
                                                    </div>
                                                @endif
                                                <div class="flex min-w-0 items-center gap-2">
                                                    <span
                                                        class="shrink-0 text-xl font-bold tabular-nums leading-none text-foreground sm:text-2xl"
                                                        data-testid="dashboard-summary-recurring-due-value"
                                                    >
                                                        {{ $recurringSummary['due'] }}
                                                    </span>
                                                    <span class="truncate text-xs font-semibold text-foreground sm:text-sm">{{ $cardLabels[$key] ?? $key }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        @php
                                            $card = $analytics->cards[$key] ?? null;
                                        @endphp
                                        @if ($card)
                                            <div class="{{ $summaryCardShellClasses[$key] ?? 'rounded-xl border border-brand-blue/20 bg-linear-to-br from-brand-blue/10 via-background to-brand-purple/10 p-3 shadow-sm ring-1 ring-brand-blue/10 sm:p-4' }}">
                                                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                                    @if (isset($cardIcons[$key]))
                                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg sm:size-9 {{ $cardIconColorClasses[$key] ?? 'bg-zinc-100 text-zinc-700' }}">
                                                            <flux:icon name="{{ $cardIcons[$key] }}" class="size-6" />
                                                        </div>
                                                    @endif
                                                    <div class="flex min-w-0 items-center gap-2">
                                                        <span class="shrink-0 text-xl font-bold tabular-nums leading-none text-foreground sm:text-2xl">
                                                            @if ($key === 'completion_rate')
                                                                {{ (int) round($card['current']) }}%
                                                            @elseif ($key === 'focus_work_seconds')
                                                                @if (($card['current'] ?? 0) >= 3600)
                                                                    {{ round($card['current'] / 3600, 1) }} {{ __('h') }}
                                                                @elseif (($card['current'] ?? 0) >= 60)
                                                                    {{ round($card['current'] / 60) }} {{ __('min') }}
                                                                @else
                                                                    {{ (int) ($card['current'] ?? 0) }} {{ __('s') }}
                                                                @endif
                                                            @else
                                                                {{ $card['current'] }}
                                                            @endif
                                                        </span>
                                                        <span class="truncate text-xs font-bold text-foreground sm:text-sm">{{ $cardLabels[$key] ?? $key }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @auth
                            <x-dashboard.at-a-glance
                                :overdue-tasks="$this->dashboardOverdueTasks"
                                :overdue-count="$this->dashboardOverdueTasksCount"
                                :due-today-count="$this->dashboardDueTodayTasksCount"
                                :doing-tasks="$this->dashboardDoingTasks"
                                :doing-count="$this->dashboardDoingTasksCount"
                                :due-today-tasks="$this->dashboardDueTodayTasks"
                                :today-events="$this->dashboardTodayEvents"
                                :today-events-count="$this->dashboardTodayEventsCount"
                                :workspace-url="$this->workspaceUrlForToday"
                            >
                                <x-slot name="urgentNow">
                                    <div class="{{ $dashboardPanelShell['urgent'] }}">
                                        <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['urgent'] }}">
                                            <flux:icon name="bolt" class="size-4 text-red-600 dark:text-red-400" />
                                            <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-urgent-now-heading">
                                                {{ __('Urgent Now') }}
                                            </span>
                                        </div>
                                        @if ($urgentNow->isEmpty())
                                            <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No urgent items right now.') }}</p>
                                        @else
                                            <ul class="divide-y divide-border/60 dark:divide-zinc-800">
                                                @foreach ($urgentNowDisplayed as $row)
                                                    <li class="px-4 py-3" data-testid="dashboard-row-urgent-item">
                                                        <a href="{{ $row['workspace_url'] }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                            <div class="min-w-0 space-y-1">
                                                                <p class="truncate text-sm font-semibold text-foreground">{{ $row['title'] }}</p>
                                                                <p class="text-[11px] text-muted-foreground">{{ $row['reasoning'] }}</p>
                                                                @if (! empty($row['ends_at']))
                                                                    <p class="text-[11px] text-muted-foreground">
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
                                </x-slot>
                            </x-dashboard.at-a-glance>
                        @endauth

                        @auth
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                                <div class="rounded-xl border border-teal-200/60 bg-background shadow-sm ring-1 ring-teal-500/10 dark:border-teal-900/40 dark:bg-zinc-900/50 dark:ring-teal-500/10">
                                    <div class="flex items-center gap-2 border-b border-teal-200/45 px-4 py-3 dark:border-teal-900/45">
                                        <flux:icon name="arrow-path" class="size-4 text-teal-600 dark:text-teal-400" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-recurring-heading">{{ __('Repeating tasks on selected day') }}</span>
                                        <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-recurring-due-count">{{ $recurringSummary['due'] }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 border-b border-teal-200/45 px-4 py-3 dark:border-teal-900/45">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Due') }}</p>
                                            <p class="text-base font-bold text-foreground" data-testid="dashboard-recurring-due-count-value">{{ $recurringSummary['due'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Completed') }}</p>
                                            <p class="text-base font-bold text-foreground" data-testid="dashboard-recurring-completed-count-value">{{ $recurringSummary['completed'] }}</p>
                                        </div>
                                    </div>
                                    <div class="border-b border-teal-200/45 px-4 py-2 dark:border-teal-900/45">
                                        <p class="text-xs font-semibold text-muted-foreground" data-testid="dashboard-recurring-streak-days-value">
                                            {{ __('Completion streak: :days day(s)', ['days' => $recurringSummary['streak_days']]) }}
                                        </p>
                                    </div>
                                    @if ($this->dashboardRecurringDueTasks->isEmpty())
                                        <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No repeating tasks due on selected day.') }}</p>
                                    @else
                                        <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                                            @foreach ($this->dashboardRecurringDueTasks as $task)
                                                <li class="px-4 py-2.5" data-testid="dashboard-row-recurring-task">
                                                    <a href="{{ route('workspace', ['date' => $this->selectedDate, 'type' => 'tasks', 'q' => $task->title]) }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                        <p class="truncate text-sm font-semibold text-foreground">{{ $task->title }}</p>
                                                        <p class="text-[11px] text-muted-foreground">
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
                                <div class="rounded-xl border border-zinc-200/70 bg-background shadow-sm ring-1 ring-zinc-500/10 dark:border-zinc-700/50 dark:bg-zinc-900/50">
                                    <div class="flex items-center gap-2 border-b border-zinc-200/45 px-4 py-3 dark:border-zinc-700/50">
                                        <flux:icon name="clock" class="size-4 text-zinc-600 dark:text-zinc-300" />
                                        <span class="text-sm font-semibold text-foreground">{{ __('No-date Backlog') }}</span>
                                        <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-no-date-backlog-count">{{ $this->dashboardNoDateBacklogCount }}</span>
                                    </div>
                                    @if ($this->dashboardNoDateBacklogTasks->isEmpty())
                                        <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No no-date tasks right now.') }}</p>
                                    @else
                                        <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto dark:divide-zinc-800">
                                            @foreach ($this->dashboardNoDateBacklogTasks as $task)
                                                <li class="px-4 py-2.5">
                                                    <a href="{{ route('workspace', ['date' => $this->selectedDate, 'type' => 'tasks']) }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                        <p class="truncate text-sm font-semibold text-foreground">{{ $task->title }}</p>
                                                        <p class="text-[11px] text-muted-foreground">{{ $task->project?->name ?? __('No project') }}</p>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                                <div class="rounded-xl border border-orange-200/55 bg-background shadow-sm ring-1 ring-orange-500/10 dark:border-orange-900/40 dark:bg-zinc-900/50 dark:ring-orange-500/10">
                                    <div class="flex items-center gap-2 border-b border-orange-200/45 px-4 py-3 dark:border-orange-900/45">
                                        <flux:icon name="bolt" class="size-4 text-orange-600 dark:text-orange-400" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-focus-throughput-heading">{{ __('Focus + Throughput') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 px-4 py-3">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Focus on selected day') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['daily_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Focus this week') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['weekly_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Completed on selected day') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['completed_today'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-[11px] text-muted-foreground">{{ __('Focus / completion') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['focus_per_completed_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="rounded-xl border border-indigo-200/55 bg-background shadow-sm ring-1 ring-indigo-500/10 dark:border-indigo-900/40 dark:bg-zinc-900/50 dark:ring-indigo-500/10">
                                    <div class="flex items-center gap-2 border-b border-indigo-200/45 px-4 py-3 dark:border-indigo-900/45">
                                        <flux:icon name="calendar-days" class="size-4 text-indigo-600 dark:text-indigo-400" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-calendar-load-heading">{{ __('Calendar Load (next 24h)') }}</span>
                                    </div>
                                    <div class="space-y-2 px-4 py-3">
                                        <div class="grid grid-cols-2 gap-2 text-xs">
                                            <div class="rounded-lg bg-muted/50 px-3 py-2">
                                                <p class="text-muted-foreground">{{ __('Events') }}</p>
                                                <p class="text-base font-bold text-foreground">{{ $calendarLoadInsights['events_in_window'] }}</p>
                                            </div>
                                            <div class="rounded-lg bg-muted/50 px-3 py-2">
                                                <p class="text-muted-foreground">{{ __('Conflicts') }}</p>
                                                <p class="text-base font-bold text-foreground">{{ $calendarLoadInsights['overlap_conflicts'] }}</p>
                                            </div>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">
                                            {{ __('Busy :busy min · Free :free min · All-day :allDay', ['busy' => $calendarLoadInsights['busy_minutes'], 'free' => $calendarLoadInsights['free_minutes'], 'allDay' => $calendarLoadInsights['all_day_events']]) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endauth

                        @auth
                            <div class="{{ $dashboardPanelShell['projects'] }}">
                                <div class="flex items-center justify-between gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['projects'] }}">
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="briefcase" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-project-health-heading">
                                            {{ __('Project Health') }}
                                        </span>
                                    </div>
                                    <a
                                        href="{{ $this->workspaceUrlForToday }}"
                                        wire:navigate
                                        class="{{ $panelCtaClass }}"
                                    >
                                        <span>{{ __('Open workspace') }}</span>
                                        <flux:icon name="arrow-right" class="{{ $panelCtaIconClass }}" />
                                    </a>
                                </div>
                                @if ($projectHealth->isEmpty())
                                    <p class="px-4 py-3 text-xs text-muted-foreground">{{ __('No active projects yet.') }}</p>
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
                                                            <p class="text-[11px] text-muted-foreground">
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
                                                            <p class="mt-1 text-[11px] text-muted-foreground">{{ $project['risk_reason'] }}</p>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endauth

                        @auth
                            <div class="rounded-xl border border-fuchsia-200/55 bg-background shadow-sm ring-1 ring-fuchsia-500/10 dark:border-fuchsia-900/40 dark:bg-zinc-900/50 dark:ring-fuchsia-500/10">
                                <div class="flex items-center justify-between gap-2 border-b border-fuchsia-200/45 px-4 py-3 dark:border-fuchsia-900/45">
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="sparkles" class="size-4 text-fuchsia-600 dark:text-fuchsia-400" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-llm-activity-heading">{{ __('LLM Assistant Activity') }}</span>
                                    </div>
                                    <flux:modal.trigger name="task-assistant-chat">
                                        <button type="button" class="{{ $panelCtaClass }}">
                                            <span>{{ __('Open assistant') }}</span>
                                            <flux:icon name="arrow-right" class="{{ $panelCtaIconClass }}" />
                                        </button>
                                    </flux:modal.trigger>
                                </div>
                                <div class="grid grid-cols-2 gap-2 px-4 py-3">
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-[11px] text-muted-foreground">{{ __('Threads') }}</p>
                                        <p class="text-base font-bold text-foreground">{{ $llmActivity['total_threads'] }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-[11px] text-muted-foreground">{{ __('Recent threads') }}</p>
                                        <p class="text-base font-bold text-foreground">{{ $llmActivity['recent_threads'] }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-[11px] text-muted-foreground">{{ __('Tool calls success') }}</p>
                                        <p class="text-base font-bold text-foreground">{{ $llmActivity['successful_tool_calls'] }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/50 px-3 py-2">
                                        <p class="text-[11px] text-muted-foreground">{{ __('Tool calls pending/failed') }}</p>
                                        <p class="text-base font-bold text-foreground">{{ $llmActivity['pending_tool_calls'] }} / {{ $llmActivity['failed_tool_calls'] }}</p>
                                    </div>
                                </div>
                                <div class="border-t border-border/60 px-4 py-3 dark:border-zinc-800">
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Quick actions') }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($assistantQuickActions as $action)
                                            <flux:modal.trigger name="task-assistant-chat">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center rounded-full border border-zinc-300/80 px-2.5 py-1 text-[11px] font-semibold text-foreground transition hover:bg-muted/70 dark:border-zinc-700"
                                                >
                                                    {{ $action }}
                                                </button>
                                            </flux:modal.trigger>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endauth

                        @if ($analytics)
                            <div class="flex flex-col gap-4">
                                @island(name: 'dashboard-trend')
                                    <div
                                        x-data="dashboardAnalyticsCharts({ analytics: @js($this->trendAnalytics), preset: @js($this->trendPreset) })"
                                        x-effect="sync(@js($this->trendAnalytics), @js($this->trendPreset))"
                                        wire:key="dashboard-trend-{{ $this->selectedDate }}-{{ $this->trendPreset }}"
                                        class="w-full overflow-hidden rounded-xl border border-indigo-200/50 bg-background shadow-sm ring-1 ring-indigo-500/10 dark:border-indigo-900/40 dark:ring-indigo-500/10"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200/40 px-3 py-2 dark:border-indigo-900/45">
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
                                                <div class="text-sm font-semibold text-foreground">
                                                    {{ __('Tasks') }}
                                                </div>
                                                <div x-ref="trendChart" wire:ignore class="h-64 min-h-[240px] w-full"></div>
                                            </div>
                                            <div
                                                class="min-w-0 space-y-2 border-t border-indigo-200/35 pt-4 dark:border-indigo-900/40 lg:border-l lg:border-indigo-200/35 lg:border-t-0 lg:pl-6 lg:pt-0 dark:lg:border-indigo-900/40"
                                            >
                                                <div class="text-sm font-semibold text-foreground">{{ __('Focus') }}</div>
                                                <div
                                                    x-ref="focusSessionsChart"
                                                    wire:ignore
                                                    class="h-64 min-h-[240px] w-full"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                @endisland

                            </div>
                        @else
                            <p class="text-sm text-muted-foreground">{{ __('No analytics to show.') }}</p>
                        @endif
                    </div>
                </div>
            </section>
        </div>

        {{-- Right Side: Calendar & Upcoming (20%) --}}
        <div class="hidden lg:block lg:min-w-[260px]">
            <div class="sticky top-6" data-focus-lock-viewport>
                <x-workspace.calendar
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

                <div class="mt-4">
                    <x-workspace.upcoming
                        :items="$this->upcoming"
                        :selected-date="$this->selectedDate"
                    />
                </div>
            </div>
        </div>
    </div>

    @auth
        <flux:modal name="task-assistant-chat" flyout position="right" class="h-full max-h-full w-full max-w-lg">
            <livewire:assistant.chat-flyout />
        </flux:modal>
    @endauth
</section>
