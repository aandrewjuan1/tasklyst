@php
    $dashboardSelectedDate = $this->getParsedSelectedDate();
    $selectedDayContextPhrase = $dashboardSelectedDate->isToday()
        ? __('for today')
        : __('on :date', ['date' => $dashboardSelectedDate->translatedFormat('F j')]);
    $urgentNow = $this->urgentNow;
    $urgentNowDisplayed = $this->urgentNowDisplayed;
    $urgentNowHasMore = $this->urgentNowHasMore;
    $recurringSummary = $this->dashboardRecurringSummary;
    $insightsOpen = $this->insightsOpen;
    $projectHealth = $this->projectHealth;
    $focusThroughput = $insightsOpen
        ? $this->focusThroughput
        : ['daily_focus_minutes' => 0, 'weekly_focus_minutes' => 0, 'completed_today' => 0, 'focus_per_completed_minutes' => 0];
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
            'label' => __('Due :when', ['when' => $selectedDayContextPhrase]),
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
        [
            'key' => 'total',
            'label' => __('Total tasks'),
            'value' => $this->dashboardTotalTasksCount,
            'icon' => 'queue-list',
            'shell' => 'border-indigo-200/55 ring-indigo-500/10 dark:border-indigo-900/40',
            'icon_shell' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-200',
        ],
        [
            'key' => 'completed',
            'label' => __('Completed tasks'),
            'value' => $this->dashboardCompletedTasksCount,
            'icon' => 'check-badge',
            'shell' => 'border-emerald-200/55 ring-emerald-500/10 dark:border-emerald-900/40',
            'icon_shell' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200',
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
    $doingDisplayLimit = 3;
    $doingTasksForDisplay = $this->dashboardDoingTasks
        ->map(function (\App\Models\Task $task) use ($taskFocusProgressPercent): array {
            return [
                'task' => $task,
                'progress_percent' => $taskFocusProgressPercent($task),
            ];
        })
        ->sortByDesc(fn (array $item): int => $item['progress_percent'] ?? -1)
        ->take($doingDisplayLimit)
        ->values();
    $doingTasksHasMore = $this->dashboardDoingTasksCount > $doingDisplayLimit;
    $noDateBacklogDisplayLimit = $this->noDateBacklogDisplayLimit();
    $noDateBacklogHasMore = $this->dashboardNoDateBacklogCount > $noDateBacklogDisplayLimit;
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
        <div class="order-2 min-w-0 space-y-4 lg:order-1">
            <section class="flex h-full w-full flex-1 flex-col gap-4">
                <div class="min-h-0 flex-1 space-y-4">
                    <div class="hero-brand-gradient-shell p-4 sm:p-5">
                        <div
                            class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl"
                            aria-hidden="true"
                        >
                            <div class="absolute inset-0 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15"></div>
                            <div class="absolute -right-4 -top-4 flex size-48 items-center justify-center rounded-full bg-brand-blue/15 blur-2xl"></div>
                        </div>
                        <div class="hero-brand-gradient-glass" aria-hidden="true"></div>
                        <div class="relative z-10 flex w-full min-w-0 flex-col gap-2">
                        @php
                            $greetingName = auth()->user()?->firstName() ?? '';
                        @endphp
                        <div class="flex w-full min-w-0 flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                                    @if ($greetingName !== '')
                                        {{ __('Dashboard — Hello, :name!', ['name' => $greetingName]) }}
                                    @else
                                        {{ __('Dashboard — Hello!') }}
                                    @endif
                                </p>
                                <h2 class="min-w-0 text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                                    {{ __('Focus on what needs attention right now.') }}
                                </h2>
                                <p class="max-w-2xl text-sm text-muted-foreground">
                                    {{ __('Tasklyst helps you manage tasks and schedules in one workspace, with AI guidance to prioritize what matters next and plan your day with confidence.') }}
                                </p>
                            </div>
                            <div class="inline-flex shrink-0 self-start items-center">
                                <x-notifications.bell-cluster variant="hero" />
                            </div>
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
                                        class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                    >
                                        <flux:icon name="sparkles" class="size-4" />
                                        <span>{{ __('Ask AI assistant') }}</span>
                                    </button>
                                </flux:modal.trigger>
                            @endauth
                        </div>
                        </div>
                    </div>

                    <div class="lg:hidden">
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

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($topKpis as $kpi)
                            <div class="rounded-xl border bg-background px-3 py-2.5 shadow-sm ring-1 sm:px-3.5 sm:py-3 {{ $kpi['shell'] }}" data-testid="dashboard-kpi-{{ $kpi['key'] }}">
                                <div class="flex items-center gap-2 sm:gap-2.5">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $kpi['icon_shell'] }}">
                                        <flux:icon name="{{ $kpi['icon'] }}" class="size-4.5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-muted-foreground">{{ $kpi['label'] }}</p>
                                        <p class="text-xl font-bold tabular-nums leading-none text-foreground sm:text-2xl" data-testid="dashboard-kpi-{{ $kpi['key'] }}-value">{{ $kpi['value'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @auth
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                            <div class="{{ $dashboardPanelShell['urgent'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['urgent'] }}">
                                    <flux:icon name="bolt" class="size-4 text-red-600 dark:text-red-400" />
                                    <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-urgent-now-heading">
                                        {{ __('Urgent Now') }}
                                    </span>
                                    <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-urgent-now-count">
                                        {{ $urgentNow->count() }}
                                    </span>
                                </div>
                                @if ($urgentNow->isEmpty())
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No urgent items right now.') }}</p>
                                @else
                                    <ul class="divide-y divide-border/60 dark:divide-zinc-800">
                                        @foreach ($urgentNowDisplayed as $row)
                                            <li class="px-4 py-3" data-testid="dashboard-row-urgent-item" data-urgency-level="{{ $row['urgency_level'] }}">
                                                <a href="{{ $row['workspace_url'] }}" wire:navigate class="block rounded-md transition hover:bg-muted/40">
                                                    <div class="min-w-0 space-y-1">
                                                        <p class="truncate text-sm font-semibold text-foreground">{{ $row['title'] }}</p>
                                                        <p class="text-xs text-muted-foreground">{{ $row['reasoning'] }}</p>
                                                        <div class="flex flex-wrap gap-1.5 pt-0.5 text-[11px]">
                                                            @if (! empty($row['ends_at']))
                                                                <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                    {{ __('Due :date', ['date' => \Carbon\Carbon::parse($row['ends_at'])->translatedFormat('M j · H:i')]) }}
                                                                </span>
                                                            @endif
                                                            @if (! empty($row['priority']))
                                                                <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                    {{ __('Priority: :value', ['value' => \Illuminate\Support\Str::headline((string) $row['priority'])]) }}
                                                                </span>
                                                            @endif
                                                            @if (! empty($row['complexity']))
                                                                <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                    {{ __('Complexity: :value', ['value' => \Illuminate\Support\Str::headline((string) $row['complexity'])]) }}
                                                                </span>
                                                            @endif
                                                        </div>
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
                                    <ul class="divide-y divide-border/60 dark:divide-zinc-800">
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
                                                    <div class="mt-1 flex flex-wrap gap-1.5 text-[11px]">
                                                        <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                            {{ __('Due: :date', ['date' => $task->end_datetime?->translatedFormat('M j · H:i') ?? __('No date')]) }}
                                                        </span>
                                                        @if ($task->priority !== null)
                                                            <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                {{ __('Priority: :value', ['value' => $task->priority->label()]) }}
                                                            </span>
                                                        @endif
                                                        @if ($task->complexity !== null)
                                                            <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                {{ __('Complexity: :value', ['value' => $task->complexity->label()]) }}
                                                            </span>
                                                        @endif
                                                    </div>
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
                                    @if ($doingTasksHasMore)
                                        <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                                            <a
                                                href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list']) }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                                                data-testid="dashboard-doing-see-all"
                                            >
                                                <span>{{ __('See all in Workspace') }}</span>
                                                <flux:icon name="arrow-right" class="size-3.5" />
                                            </a>
                                        </div>
                                    @endif
                                @endif
                            </div>

                        </div>
                    @endauth

                    @auth
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="{{ $dashboardPanelShell['default'] }}">
                                <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                    <flux:icon name="arrow-path" class="size-4 text-foreground/80" />
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-foreground" data-testid="dashboard-section-recurring-heading">{{ __('Repeating tasks :when', ['when' => $selectedDayContextPhrase]) }}</span>
                                    <span class="ml-auto text-xs font-semibold text-muted-foreground" data-testid="dashboard-recurring-due-count">{{ $recurringSummary['due'] }}</span>
                                </div>
                                <div class="grid grid-cols-1 gap-2 border-b border-border/60 px-4 py-3 sm:grid-cols-2 dark:border-zinc-800">
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
                                    <p class="px-4 py-3 text-sm text-muted-foreground">{{ __('No repeating tasks due :when.', ['when' => $selectedDayContextPhrase]) }}</p>
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
                                    <ul class="divide-y divide-border/60 dark:divide-zinc-800">
                                        @foreach ($this->dashboardNoDateBacklogTasks as $task)
                                            @php
                                                $noDateBacklogProgressPercent = $taskFocusProgressPercent($task);
                                            @endphp
                                            <li class="px-4 py-3" data-testid="dashboard-row-no-date-backlog-task">
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
                                                        <span class="text-muted-foreground/80"> · </span>
                                                        <span>{{ $task->project?->name ?? __('No project') }}</span>
                                                        @if ($noDateBacklogProgressPercent !== null)
                                                            <span class="text-muted-foreground/80"> · </span>
                                                            <span class="tabular-nums">{{ $noDateBacklogProgressPercent }}% {{ __('Progress') }}</span>
                                                        @endif
                                                    </p>
                                                    <div class="mt-1 flex flex-wrap gap-1.5 text-[11px]">
                                                        <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                            {{ __('Due: :date', ['date' => __('No date')]) }}
                                                        </span>
                                                        @if ($task->priority !== null)
                                                            <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                {{ __('Priority: :value', ['value' => $task->priority->label()]) }}
                                                            </span>
                                                        @endif
                                                        @if ($task->complexity !== null)
                                                            <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                                {{ __('Complexity: :value', ['value' => $task->complexity->label()]) }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if ($noDateBacklogProgressPercent !== null)
                                                        <div
                                                            class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                                            role="progressbar"
                                                            aria-valuenow="{{ $noDateBacklogProgressPercent }}"
                                                            aria-valuemin="0"
                                                            aria-valuemax="100"
                                                            aria-label="{{ __('Task progress') }}"
                                                        >
                                                            <div
                                                                class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width] duration-300 ease-linear dark:bg-blue-500"
                                                                style="width: {{ $noDateBacklogProgressPercent }}%; min-width: {{ $noDateBacklogProgressPercent > 0 ? '2px' : '0' }};"
                                                            ></div>
                                                        </div>
                                                    @endif
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($noDateBacklogHasMore)
                                        <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                                            <a
                                                href="{{ route('workspace', ['date' => $this->selectedDate, 'view' => 'list']) }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                                                data-testid="dashboard-no-date-backlog-see-all"
                                            >
                                                <span>{{ __('See all in Workspace') }}</span>
                                                <flux:icon name="arrow-right" class="size-3.5" />
                                            </a>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endauth

                    @auth
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
                                                        <a href="{{ $project['workspace_url'] }}" wire:navigate class="block max-w-48 truncate font-semibold text-foreground transition hover:text-brand-blue sm:max-w-none">
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
                    @endauth

                    <div class="{{ $dashboardPanelShell['default'] }}">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-4 py-3 text-left"
                            wire:click="toggleInsights"
                            aria-expanded="{{ $insightsOpen ? 'true' : 'false' }}"
                        >
                            <div class="flex items-center gap-2">
                                <flux:icon name="chart-bar" class="size-4 text-foreground/80" />
                                <span class="text-sm font-semibold text-foreground">{{ __('Show insights') }}</span>
                            </div>
                            <flux:icon name="chevron-down" class="size-4 transition-transform {{ $insightsOpen ? 'rotate-180' : '' }}" />
                        </button>

                        @if ($insightsOpen)
                            <div class="space-y-4 border-t border-border/60 p-4 dark:border-zinc-800">
                            @auth

                                <div class="{{ $dashboardPanelShell['default'] }}">
                                    <div class="flex items-center gap-2 px-4 py-3 {{ $dashboardPanelHeaderBorder['default'] }}">
                                        <flux:icon name="bolt" class="size-4 text-foreground/80" />
                                        <span class="text-sm font-semibold text-foreground" data-testid="dashboard-section-focus-throughput-heading">{{ __('Focus + Throughput') }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-2">
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus :when', ['when' => $selectedDayContextPhrase]) }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['daily_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus this week') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['weekly_focus_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Completed :when', ['when' => $selectedDayContextPhrase]) }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['completed_today'] }}</p>
                                        </div>
                                        <div class="rounded-lg bg-muted/50 px-3 py-2">
                                            <p class="text-xs text-muted-foreground">{{ __('Focus / completion') }}</p>
                                            <p class="text-base font-bold text-foreground">{{ $focusThroughput['focus_per_completed_minutes'] }} {{ __('min') }}</p>
                                        </div>
                                    </div>
                                </div>

                            @endauth

                            @if (! $this->insightsChartsReady)
                                <div wire:init="loadInsightsCharts" class="space-y-3">
                                    <div class="rounded-xl border border-border/70 bg-background p-4 shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted/60">
                                                <flux:icon name="chart-bar" class="size-4 text-muted-foreground" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-semibold text-foreground">{{ __('Preparing your insights…') }}</p>
                                                <p class="mt-1 text-sm text-muted-foreground">{{ __('Crunching your recent activity and getting charts ready.') }}</p>
                                            </div>
                                        </div>
                                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" aria-hidden="true">
                                            @foreach (range(1, 8) as $skeletonIndex)
                                                <div class="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 dark:border-zinc-800">
                                                    <div class="h-3 w-28 rounded bg-muted/60"></div>
                                                    <div class="mt-2 h-6 w-20 rounded bg-muted/60"></div>
                                                    <div class="mt-2 h-3 w-24 rounded bg-muted/60"></div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-border/70 bg-background p-4 shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5" aria-hidden="true">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="h-4 w-24 rounded bg-muted/60"></div>
                                            <div class="h-8 w-40 rounded-lg bg-muted/60"></div>
                                        </div>
                                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                            <div class="h-64 rounded-lg bg-muted/30"></div>
                                            <div class="h-64 rounded-lg bg-muted/30"></div>
                                        </div>
                                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                                            <div class="h-52 rounded-lg bg-muted/30"></div>
                                            <div class="h-52 rounded-lg bg-muted/30"></div>
                                            <div class="h-52 rounded-lg bg-muted/30"></div>
                                        </div>
                                        <div class="mt-4 h-64 rounded-lg bg-muted/30"></div>
                                    </div>
                                </div>
                            @elseif ($this->trendAnalytics)
                                    @php
                                        $trendOverview = $this->trendAnalytics;
                                        $analyticsPeriodLabel = $trendOverview->periodStart->translatedFormat('M j, Y')
                                            . ' – '
                                            . $trendOverview->periodEnd->translatedFormat('M j, Y');
                                        $analyticsSummaryKeys = [
                                            'tasks_created' => __('Tasks created'),
                                            'tasks_completed' => __('Tasks completed'),
                                            'completion_rate' => __('Completion rate'),
                                            'overdue' => __('Overdue (open)'),
                                            'due_soon' => __('Due soon (7 days)'),
                                            'focus_work_seconds' => __('Focus time'),
                                            'focus_sessions' => __('Focus sessions'),
                                        ];
                                        $formatAnalyticsCardCurrent = static function (string $key, float|int $current): string {
                                            return match ($key) {
                                                'completion_rate' => number_format((float) $current, 1).'%',
                                                'focus_work_seconds' => (static function (int $seconds): string {
                                                    if ($seconds >= 3600) {
                                                        $h = round($seconds / 3600, 1);

                                                        return $h.' '.__('h');
                                                    }
                                                    if ($seconds >= 60) {
                                                        return (int) round($seconds / 60).' '.__('min');
                                                    }

                                                    return $seconds.' '.__('s');
                                                })((int) $current),
                                                default => (string) (int) round((float) $current),
                                            };
                                        };
                                        $formatAnalyticsCardDelta = static function (string $key, float|int $delta): string {
                                            return match ($key) {
                                                'completion_rate' => sprintf('%+.1f%%', (float) $delta),
                                                'focus_work_seconds' => (static function (int $d): string {
                                                    if (abs($d) >= 3600) {
                                                        return sprintf('%+.1f %s', round($d / 3600, 1), __('h'));
                                                    }

                                                    return sprintf('%+d %s', (int) round($d / 60), __('min'));
                                                })((int) $delta),
                                                default => sprintf('%+d', (int) round((float) $delta)),
                                            };
                                        };
                                    @endphp
                                    <div class="space-y-4" wire:key="dashboard-trend-{{ $this->selectedDate }}-{{ $this->trendPreset }}">
                                        <div
                                            class="overflow-hidden rounded-xl border border-border/70 bg-background shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5"
                                            data-testid="dashboard-insights-period-summary"
                                        >
                                            <div class="border-b border-border/60 px-3 py-2 dark:border-zinc-800">
                                                <p class="text-sm font-semibold text-foreground">{{ __('Period summary') }}</p>
                                                <p class="text-xs text-muted-foreground">{{ $analyticsPeriodLabel }}</p>
                                                <p class="mt-0.5 text-[11px] text-muted-foreground">{{ __('vs previous period of the same length') }}</p>
                                            </div>
                                            <div class="grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                                @foreach ($analyticsSummaryKeys as $cardKey => $cardTitle)
                                                    @php
                                                        $card = $trendOverview->cards[$cardKey] ?? null;
                                                    @endphp
                                                    @if ($card !== null)
                                                        <div
                                                            class="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 dark:border-zinc-800"
                                                            data-testid="dashboard-insights-summary-{{ $cardKey }}"
                                                        >
                                                            <p class="text-xs text-muted-foreground">{{ $cardTitle }}</p>
                                                            <p class="text-lg font-bold tabular-nums text-foreground">
                                                                {{ $formatAnalyticsCardCurrent($cardKey, $card['current']) }}
                                                            </p>
                                                            @php
                                                                $delta = $card['delta'];
                                                                $deltaTone = $delta > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($delta < 0 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground');
                                                            @endphp
                                                            <p class="text-xs font-medium tabular-nums {{ $deltaTone }}">
                                                                @if ((float) $delta !== 0.0)
                                                                    {{ $formatAnalyticsCardDelta($cardKey, $delta) }}
                                                                    @if ($card['delta_percentage'] !== null)
                                                                        <span class="text-muted-foreground">({{ sprintf('%+.1f%%', (float) $card['delta_percentage']) }})</span>
                                                                    @endif
                                                                @else
                                                                    {{ __('No change') }}
                                                                @endif
                                                            </p>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>

                                        <div
                                            x-data="dashboardAnalyticsCharts({ analytics: @js($trendOverview), preset: @js($this->trendPreset) })"
                                            x-effect="sync(@js($trendOverview), @js($this->trendPreset))"
                                            class="relative overflow-hidden rounded-xl border border-border/70 bg-background shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5"
                                        >
                                            <div class="flex flex-wrap items-start justify-between gap-2 border-b border-border/60 px-3 py-2 dark:border-zinc-800">
                                                <div class="min-w-0">
                                                    <div class="text-sm font-semibold text-foreground">{{ __('Charts') }}</div>
                                                    <div class="text-xs text-muted-foreground">{{ __('Trends and how your tasks are distributed in this period.') }}</div>
                                                </div>
                                                <div class="inline-flex w-full items-center gap-1 rounded-lg bg-muted p-1 sm:w-auto sm:shrink-0">
                                                    @foreach (['daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly')] as $presetValue => $presetLabel)
                                                        <button
                                                            type="button"
                                                            wire:click="setTrendPreset('{{ $presetValue }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="setTrendPreset"
                                                            class="{{ $this->trendPreset === $presetValue
                                                                ? 'bg-background text-foreground shadow-sm'
                                                                : 'text-muted-foreground hover:text-foreground' }} rounded-md px-2.5 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {{ $presetLabel }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 gap-4 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-6">
                                                <div class="min-w-0 space-y-1">
                                                    <div class="text-sm font-semibold text-foreground">{{ __('Tasks over time') }}</div>
                                                    <div class="relative">
                                                        <div x-ref="trendChart" wire:ignore class="h-64 min-h-[240px] w-full"></div>
                                                        <div x-cloak x-show="!echartsReady" class="pointer-events-none absolute inset-0 grid place-items-center rounded-lg bg-muted/20">
                                                            <div class="text-center">
                                                                <p class="text-sm font-semibold text-foreground">{{ __('Loading chart…') }}</p>
                                                                <p class="mt-1 text-xs text-muted-foreground">{{ __('Just a moment.') }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="min-w-0 space-y-2 border-t border-border/60 pt-4 dark:border-zinc-800 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                                                    <div class="text-sm font-semibold text-foreground">{{ __('Focus over time') }}</div>
                                                    <div class="relative">
                                                        <div x-ref="focusSessionsChart" wire:ignore class="h-64 min-h-[240px] w-full"></div>
                                                        <div x-cloak x-show="!echartsReady" class="pointer-events-none absolute inset-0 grid place-items-center rounded-lg bg-muted/20">
                                                            <div class="text-center">
                                                                <p class="text-sm font-semibold text-foreground">{{ __('Loading chart…') }}</p>
                                                                <p class="mt-1 text-xs text-muted-foreground">{{ __('Just a moment.') }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="border-t border-border/60 px-3 py-2 dark:border-zinc-800">
                                                <div class="text-sm font-semibold text-foreground">{{ __('Task distribution') }}</div>
                                                <p class="text-xs text-muted-foreground">{{ __('Tasks created in this period by status, priority, and complexity; completions by project.') }}</p>
                                            </div>
                                            <div class="grid grid-cols-1 gap-4 p-3 lg:grid-cols-3 lg:gap-4">
                                                <div class="min-w-0 space-y-1">
                                                    <div class="text-xs font-semibold text-muted-foreground">{{ __('By status') }}</div>
                                                    <div class="relative">
                                                        <div x-ref="statusChart" wire:ignore class="h-52 min-h-[208px] w-full"></div>
                                                        <div x-cloak x-show="!echartsReady" class="pointer-events-none absolute inset-0 grid place-items-center rounded-lg bg-muted/20">
                                                            <p class="text-xs font-semibold text-muted-foreground">{{ __('Preparing…') }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="min-w-0 space-y-1">
                                                    <div class="text-xs font-semibold text-muted-foreground">{{ __('By priority') }}</div>
                                                    <div class="relative">
                                                        <div x-ref="priorityChart" wire:ignore class="h-52 min-h-[208px] w-full"></div>
                                                        <div x-cloak x-show="!echartsReady" class="pointer-events-none absolute inset-0 grid place-items-center rounded-lg bg-muted/20">
                                                            <p class="text-xs font-semibold text-muted-foreground">{{ __('Preparing…') }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="min-w-0 space-y-1">
                                                    <div class="text-xs font-semibold text-muted-foreground">{{ __('By complexity') }}</div>
                                                    <div class="relative">
                                                        <div x-ref="complexityChart" wire:ignore class="h-52 min-h-[208px] w-full"></div>
                                                        <div x-cloak x-show="!echartsReady" class="pointer-events-none absolute inset-0 grid place-items-center rounded-lg bg-muted/20">
                                                            <p class="text-xs font-semibold text-muted-foreground">{{ __('Preparing…') }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                wire:loading.flex
                                                wire:target="setTrendPreset"
                                                class="absolute inset-0 z-10 items-center justify-center bg-background/75 backdrop-blur-[1px] dark:bg-zinc-900/70"
                                                aria-live="polite"
                                                aria-busy="true"
                                            >
                                                <div class="flex items-center gap-2 rounded-lg border border-border/70 bg-background px-3 py-2 text-xs font-semibold text-foreground shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                                                    <flux:icon name="arrow-path" class="size-4 animate-spin text-muted-foreground" />
                                                    <span>{{ __('Updating charts...') }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            @else
                                <div class="rounded-xl border border-border/70 bg-background p-4 shadow-sm ring-1 ring-black/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:ring-white/5" data-testid="dashboard-insights-empty">
                                    <div class="flex items-start gap-3">
                                        <div class="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted/60">
                                            <flux:icon name="chart-bar" class="size-4 text-muted-foreground" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-foreground">{{ __('No insights yet') }}</p>
                                            <p class="mt-1 text-sm text-muted-foreground">
                                                {{ __('Once you start creating and completing tasks (and logging focus sessions), charts will appear here.') }}
                                            </p>
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <a
                                                    href="{{ $this->workspaceUrlForToday }}"
                                                    wire:navigate
                                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                                >
                                                    <span>{{ __('Open workspace') }}</span>
                                                    <flux:icon name="arrow-right" class="size-3.5" />
                                                </a>
                                                <span class="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-2.5 py-1 text-xs font-semibold text-foreground">
                                                    {{ __('Tip: complete a few tasks to populate trends.') }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        </div>

        <div class="order-1 hidden lg:order-2 lg:block lg:min-w-[260px]">
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
