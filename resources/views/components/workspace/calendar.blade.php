@props([
    'selectedDate' => null,
    'currentMonth' => null,
    'currentYear' => null,
    'monthMeta' => [],
    'selectedDayAgenda' => [],
    /** @var 'dashboard'|'workspace' */
    'agendaContext' => 'dashboard',
])

@php
    $agendaContext = in_array($agendaContext, ['dashboard', 'workspace'], true) ? $agendaContext : 'dashboard';

    $workspaceAgendaBtnBase = 'cursor-pointer rounded-sm transition-colors hover:bg-foreground/10 dark:hover:bg-foreground/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40';

    // Default to current month/year if not provided
    $now = now();
    $currentMonth = $currentMonth ?? $now->month;
    $currentYear = $currentYear ?? $now->year;
    
    // Parse selected date if provided
    $selectedDateObj = $selectedDate ? \Illuminate\Support\Carbon::parse($selectedDate) : null;
    $selectedDateString = $selectedDateObj ? $selectedDateObj->toDateString() : null;
    
    // Today's date for highlighting
    $today = $now->toDateString();
    
    // Day names (localized) - static, doesn't change
    $dayNames = [];
    for ($i = 0; $i < 7; $i++) {
        $dayNames[] = \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->startOfWeek()->addDays($i)->translatedFormat('D');
    }
    
    // Build server-rendered days array for first paint.
    // Day headers use {@see \Illuminate\Support\Carbon::startOfWeek()} (locale week start).
    // Padding must use the same origin: PHP's `dayOfWeek` is 0=Sunday..6=Saturday, so using it
    // alone misaligns Monday-first grids by one column (headers vs cells).
    $calendarDate = \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1);
    $gridWeekStartDow = (int) $calendarDate->copy()->startOfWeek()->dayOfWeek;
    $daysToShowFromPreviousMonth = ($calendarDate->dayOfWeek - $gridWeekStartDow + 7) % 7;
    $daysInMonth = $calendarDate->daysInMonth;
    $previousMonth = $calendarDate->copy()->subMonth();
    $daysInPreviousMonth = $previousMonth->daysInMonth;
    
    $serverDays = [];
    
    // Previous month days (grayed out)
    for ($i = $daysInPreviousMonth - $daysToShowFromPreviousMonth + 1; $i <= $daysInPreviousMonth; $i++) {
        $serverDays[] = [
            'day' => $i,
            'month' => 'previous',
            'isToday' => false,
            'isSelected' => false,
            'dateString' => null,
        ];
    }
    
    // Current month days
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateString = \Illuminate\Support\Carbon::create($currentYear, $currentMonth, $day)->toDateString();
        $serverDays[] = [
            'day' => $day,
            'month' => 'current',
            'isToday' => $dateString === $today,
            'isSelected' => $dateString === $selectedDateString,
            'dateString' => $dateString,
        ];
    }
    
    // Next month days to fill last week
    $totalDaysShown = count($serverDays);
    $remainder = $totalDaysShown % 7;
    $blanksNeeded = $remainder === 0 ? 0 : 7 - $remainder;
    for ($day = 1; $day <= $blanksNeeded; $day++) {
        $serverDays[] = [
            'day' => $day,
            'month' => 'next',
            'isToday' => false,
            'isSelected' => false,
            'dateString' => null,
        ];
    }
    
    // Initial month/year for Alpine.js (0-indexed month for JavaScript)
    $initialMonth = $currentMonth - 1; // JavaScript months are 0-indexed
    $initialYear = $currentYear;
    $initialMonthLabel = \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y');

    // Same rule as {@see syncWorkspaceCalendarTodayButton}: disabled only when selected is today
    // and the grid is not showing a browsed month (SSR first paint before JS runs).
    $todayCarbon = \Illuminate\Support\Carbon::parse($today);
    $jumpToTodayDisabledInitially = $selectedDateString !== null
        && $selectedDateString === $today
        && (int) $currentYear === (int) $todayCarbon->year
        && (int) $currentMonth === (int) $todayCarbon->month;

    $workspaceCalendarConfig = [
        'month' => $initialMonth,
        'year' => $initialYear,
        'selectedDate' => $selectedDateString,
        'today' => $today,
        'monthMeta' => $monthMeta,
        'locale' => str_replace('_', '-', app()->getLocale()),
        'monthLabel' => $initialMonthLabel,
        'monthLabelCache' => $initialYear.'-'.$initialMonth,
        /** Matches {@see Carbon::startOfWeek()} for the live locale (0=Sun .. 6=Sat). */
        'gridWeekStartDow' => $gridWeekStartDow,
    ];
@endphp

<div
    x-data="workspaceCalendar({{ \Illuminate\Support\Js::from($workspaceCalendarConfig) }})"
    class="w-full"
    tabindex="0"
    @keydown="handleKeydown($event)"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{-- Calendar Container --}}
    <div
        class="rounded-xl border border-brand-blue/35 bg-brand-light-lavender/90 shadow-lg backdrop-blur-xs transition-opacity dark:border-brand-blue/25 dark:bg-brand-light-lavender/10"
        :class="calendarNavBusy || dateSelectBusy ? 'opacity-90' : 'opacity-100'"
    >
        {{-- Header: Month/Year Navigation (same blue family as “today” day cells) --}}
        <div class="flex items-center justify-between rounded-t-xl border-b border-brand-blue/25 bg-brand-light-blue px-3 py-3 sm:px-4 sm:py-4 dark:border-brand-blue/35 dark:bg-brand-blue/20">
            {{-- Previous Month Button --}}
            <button
                type="button"
                @click="changeMonth(-1)"
                :disabled="calendarNavBusy"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-navy-blue transition-colors hover:bg-white/55 hover:text-brand-navy-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-2 focus:ring-offset-brand-light-blue dark:text-brand-light-blue dark:hover:bg-white/10 dark:hover:text-white dark:focus:ring-offset-brand-blue/20 disabled:cursor-not-allowed disabled:opacity-50 sm:h-10 sm:w-10"
                aria-label="{{ __('Previous month') }}"
            >
                <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            {{-- Month/Year Display: single element so "Month Year" is not shown twice before Alpine hydrates --}}
            <div class="min-w-0 flex-1 text-center px-1">
                <h2 class="text-base font-bold leading-tight tracking-tight text-brand-navy-blue tabular-nums sm:text-lg dark:text-foreground" x-text="monthLabel">
                    {{ \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y') }}
                </h2>
            </div>

            {{-- Next Month Button --}}
            <button
                type="button"
                @click="changeMonth(1)"
                :disabled="calendarNavBusy"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-navy-blue transition-colors hover:bg-white/55 hover:text-brand-navy-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-2 focus:ring-offset-brand-light-blue dark:text-brand-light-blue dark:hover:bg-white/10 dark:hover:text-white dark:focus:ring-offset-brand-blue/20 disabled:cursor-not-allowed disabled:opacity-50 sm:h-10 sm:w-10"
                aria-label="{{ __('Next month') }}"
            >
                <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        <div class="flex justify-center border-b border-brand-blue/20 bg-brand-light-blue/40 px-3 py-2 dark:border-brand-blue/30 dark:bg-brand-blue/15">
            <flux:tooltip :content="__('Jump to today')" position="top">
                <span class="inline-flex">
                    <button
                        type="button"
                        data-testid="calendar-jump-to-today"
                        data-app-today="{{ $today }}"
                        @if ($jumpToTodayDisabledInitially) disabled @endif
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-blue/30 bg-brand-blue px-2.5 py-1 text-[11px] font-semibold text-white shadow-md transition hover:border-brand-blue/40 hover:bg-brand-blue/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-blue disabled:cursor-not-allowed disabled:opacity-50 dark:border-brand-light-blue/40 dark:bg-brand-blue dark:text-white dark:hover:border-brand-light-blue/50 dark:hover:bg-brand-blue/90 dark:focus-visible:ring-brand-light-blue/55 dark:focus-visible:ring-offset-zinc-900"
                        @click="jumpToToday()"
                    >
                        <flux:icon name="calendar-days" class="size-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        <span>{{ __('Today') }}</span>
                    </button>
                </span>
            </flux:tooltip>
        </div>

        {{-- Calendar Grid --}}
        <div class="p-2 sm:p-3 md:p-4">
            {{-- Day Names Header --}}
            <div class="mb-2 grid grid-cols-7 gap-1 sm:mb-3 sm:gap-1.5">
                @foreach ($dayNames as $dayName)
                    <div class="flex min-w-0 items-center justify-center">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground sm:text-[11px]">
                            {{ $dayName }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Calendar Days Grid --}}
            <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                {{-- Server-rendered first paint (visible by default) --}}
                @foreach ($serverDays as $dayData)
                    @if ($dayData['month'] !== 'current')
                        {{-- Grid padding only (no adjacent-month day labels) --}}
                        <div
                            x-show="!alpineReady"
                            class="flex aspect-square min-h-10 min-w-0 w-full items-center justify-center rounded-lg border border-border/20 dark:border-zinc-600/35 sm:min-h-11"
                            style="display: flex;"
                            aria-hidden="true"
                        ></div>
                    @else
                        {{-- Current Month Days (Clickable) --}}
                        @php
                            $meta = $monthMeta[$dayData['dateString']] ?? [
                                'task_count' => 0,
                                'event_count' => 0,
                                'overdue_count' => 0,
                                'conflict_count' => 0,
                                'recurring_count' => 0,
                            ];
                        @endphp
                        <button
                            x-show="!alpineReady"
                            type="button"
                            style="display: flex;"
                            @click="if (typeof $wire !== 'undefined') { $wire.set('selectedDate', '{{ $dayData['dateString'] }}'); }"
                            :disabled="calendarNavBusy || dateSelectBusy"
                            class="group relative box-border flex min-h-10 h-full w-full min-w-0 items-center justify-center rounded-lg border border-border/25 px-0.5 text-xs font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-1 dark:border-zinc-600/40 dark:focus:ring-offset-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-11 sm:text-sm {{ $dayData['isSelected'] ? 'border-white/25 bg-brand-blue text-white shadow-md' : ($dayData['isToday'] ? 'border-brand-blue/30 bg-brand-light-blue text-brand-navy-blue ring-2 ring-brand-blue/30 dark:border-brand-blue/35 dark:bg-muted/40 dark:text-foreground dark:ring-brand-blue/25' : 'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300') }}"
                            data-date="{{ $dayData['dateString'] }}"
                            aria-label="{{ __('Select date') }}: {{ \Illuminate\Support\Carbon::parse($dayData['dateString'])->translatedFormat('F j, Y') }}"
                        >
                            <span class="relative z-10">{{ $dayData['day'] }}</span>

                            @php
                                $hasOverdue = ($meta['overdue_count'] ?? 0) > 0;
                                $dueCount = (int) ($meta['due_count'] ?? 0);
                                $overdueCount = (int) ($meta['overdue_count'] ?? 0);
                                $hasDueToday = $dueCount > $overdueCount;
                                $hasStartsToday = (($meta['task_starts_count'] ?? 0) > 0) || (($meta['event_count'] ?? 0) > 0);
                            @endphp
                            @if ($hasOverdue || $hasDueToday || $hasStartsToday)
                                <div class="pointer-events-none absolute -right-1 -top-1 z-20 flex max-w-[calc(100%+0.25rem)] flex-wrap items-center justify-end gap-0.5 rounded-full bg-background/90 px-0.5 py-0.5 shadow-xs dark:bg-zinc-900/90">
                                    @if ($hasOverdue)
                                        <flux:tooltip content="{{ __('Overdue items') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-red-500"></span>
                                        </flux:tooltip>
                                    @endif
                                    @if ($hasDueToday)
                                        <flux:tooltip content="{{ __('Tasks due this day (not yet overdue).') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        </flux:tooltip>
                                    @endif
                                    @if ($hasStartsToday)
                                        <flux:tooltip content="{{ __('Tasks or events starting or scheduled this day.') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-green-600 dark:bg-green-500"></span>
                                        </flux:tooltip>
                                    @endif
                                </div>
                            @endif
                            
                            @if (!$dayData['isSelected'] && !$dayData['isToday'])
                                <span class="absolute inset-0 rounded-lg bg-foreground/5 opacity-0 transition-opacity group-hover:opacity-100"></span>
                            @endif
                        </button>
                    @endif
                @endforeach
                
                {{-- Alpine reactive (shown when Alpine ready) --}}
                <template x-for="dayData in days" :key="`day-${year}-${month}-${dayData.day}-${dayData.month}`">
                    <div class="flex aspect-square min-h-10 min-w-0 w-full items-center justify-center sm:min-h-11" x-show="alpineReady" x-cloak>
                        {{-- Grid padding only (no adjacent-month day labels) --}}
                        <div
                            x-show="dayData.month !== 'current'"
                            class="flex h-full w-full min-w-0 items-center justify-center rounded-lg border border-border/20 dark:border-zinc-600/35"
                            aria-hidden="true"
                        ></div>
                        
                        {{-- Current Month Days (Clickable) --}}
                        <button
                            x-show="dayData.month === 'current'"
                            type="button"
                            @click="selectDay(dayData)"
                            :disabled="calendarNavBusy || dateSelectBusy"
                            class="group relative box-border flex min-h-10 h-full w-full min-w-0 items-center justify-center rounded-lg border border-border/25 px-0.5 text-xs font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-1 dark:border-zinc-600/40 dark:focus:ring-offset-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-11 sm:text-sm"
                            :data-date="dayData.dateString"
                            :aria-label="`{{ __('Select date') }}: ${dayData.dateString}`"
                            :class="{
                                'border-white/25 bg-brand-blue text-white shadow-md': dayData.isSelected,
                                'border-brand-blue/30 bg-brand-light-blue text-brand-navy-blue ring-2 ring-brand-blue/30 dark:border-brand-blue/35 dark:bg-muted/40 dark:text-foreground dark:ring-brand-blue/25': !dayData.isSelected && dayData.isToday,
                                'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300': !dayData.isSelected && !dayData.isToday,
                            }"
                        >
                            <span class="relative z-10" x-text="dayData.day"></span>

                            <template x-if="
                                (dayData.meta?.overdue_count ?? getMeta(dayData.dateString).overdue_count) > 0
                                || (dayData.meta?.due_count ?? getMeta(dayData.dateString).due_count) > (dayData.meta?.overdue_count ?? getMeta(dayData.dateString).overdue_count)
                                || (dayData.meta?.task_starts_count ?? getMeta(dayData.dateString).task_starts_count) > 0
                                || (dayData.meta?.event_count ?? getMeta(dayData.dateString).event_count) > 0
                            ">
                                <div class="pointer-events-none absolute -right-1 -top-1 z-20 flex max-w-[calc(100%+0.25rem)] flex-wrap items-center justify-end gap-0.5 rounded-full bg-background/90 px-0.5 py-0.5 shadow-xs dark:bg-zinc-900/90">
                                    <template x-if="(dayData.meta?.overdue_count ?? getMeta(dayData.dateString).overdue_count) > 0">
                                        <flux:tooltip content="{{ __('Overdue items') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-red-500"></span>
                                        </flux:tooltip>
                                    </template>
                                    <template x-if="(dayData.meta?.due_count ?? getMeta(dayData.dateString).due_count) > (dayData.meta?.overdue_count ?? getMeta(dayData.dateString).overdue_count)">
                                        <flux:tooltip content="{{ __('Tasks due this day (not yet overdue).') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        </flux:tooltip>
                                    </template>
                                    <template x-if="
                                        (dayData.meta?.task_starts_count ?? getMeta(dayData.dateString).task_starts_count) > 0
                                        || (dayData.meta?.event_count ?? getMeta(dayData.dateString).event_count) > 0
                                    ">
                                        <flux:tooltip content="{{ __('Tasks or events starting or scheduled this day.') }}">
                                            <span class="inline-flex size-1.5 shrink-0 rounded-full bg-green-600 dark:bg-green-500"></span>
                                        </flux:tooltip>
                                    </template>
                                </div>
                            </template>
                            
                            {{-- Hover effect indicator --}}
                            <span 
                                x-show="!dayData.isSelected && !dayData.isToday"
                                class="absolute inset-0 rounded-lg bg-foreground/5 opacity-0 transition-opacity group-hover:opacity-100"
                            ></span>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <div class="border-t border-brand-blue/20 px-3 py-3 sm:px-4" data-testid="calendar-selected-day-agenda">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Selected day') }}
                </h3>
                <span class="text-[11px] text-muted-foreground">
                    {{ \Illuminate\Support\Carbon::parse($selectedDayAgenda['date'] ?? $today)->translatedFormat('D, M j') }}
                </span>
            </div>

            <div class="mb-2 grid grid-cols-3 gap-1 text-center">
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Tasks') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-tasks">{{ $selectedDayAgenda['summary']['tasks'] ?? 0 }}</p>
                </div>
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Events') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-events">{{ $selectedDayAgenda['summary']['events'] ?? 0 }}</p>
                </div>
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Overdue') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-overdue">{{ $selectedDayAgenda['summary']['overdue'] ?? 0 }}</p>
                </div>
            </div>

            <div class="max-h-48 space-y-2 overflow-y-auto pr-1">
                @if (!empty($selectedDayAgenda['overdueTasks'] ?? []))
                    <div data-testid="calendar-agenda-overdue-tasks">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">{{ __('Overdue tasks') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['overdueTasks'] ?? []) as $item)
                                <li class="rounded-md border border-red-500/30 bg-red-500/10 px-2 py-1 text-xs dark:border-red-500/25 dark:bg-red-950/40">
                                    @if ($agendaContext === 'dashboard')
                                        <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                            <span class="truncate font-medium text-red-900 dark:text-red-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-red-700/90 dark:text-red-300/90">{{ $item['time'] }}</span>
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            @click="
                                                const k = '{{ $item['focus_kind'] }}';
                                                const i = {{ $item['focus_id'] }};
                                                const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                                $wire.focusCalendarAgendaItem(k, i, !instant);
                                            "
                                            class="flex w-full items-center justify-between gap-2 text-left {{ $workspaceAgendaBtnBase }}"
                                        >
                                            <span class="truncate font-medium text-red-900 dark:text-red-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-red-700/90 dark:text-red-300/90">{{ $item['time'] }}</span>
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['dueDayTasks'] ?? []))
                    <div data-testid="calendar-agenda-due-day-tasks">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ __('Due tasks') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['dueDayTasks'] ?? []) as $item)
                                <li class="rounded-md border border-amber-500/35 bg-amber-500/10 px-2 py-1 text-xs dark:border-amber-500/30 dark:bg-amber-950/35">
                                    @if ($agendaContext === 'dashboard')
                                        <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                            <span class="truncate font-medium text-amber-950 dark:text-amber-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-amber-800/90 dark:text-amber-200/90">{{ $item['time'] }}</span>
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            @click="
                                                const k = '{{ $item['focus_kind'] }}';
                                                const i = {{ $item['focus_id'] }};
                                                const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                                $wire.focusCalendarAgendaItem(k, i, !instant);
                                            "
                                            class="flex w-full items-center justify-between gap-2 text-left {{ $workspaceAgendaBtnBase }}"
                                        >
                                            <span class="truncate font-medium text-amber-950 dark:text-amber-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-amber-800/90 dark:text-amber-200/90">{{ $item['time'] }}</span>
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['scheduledStarts'] ?? []))
                    <div data-testid="calendar-agenda-scheduled-starts">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-green-700 dark:text-green-400">{{ __('Starting this day') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['scheduledStarts'] ?? []) as $item)
                                <li class="rounded-md border border-green-600/30 bg-green-600/10 px-2 py-1 text-xs dark:border-green-500/35 dark:bg-green-950/40">
                                    @if ($agendaContext === 'dashboard')
                                        <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                            <span class="truncate font-medium text-green-950 dark:text-green-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-green-900/90 dark:text-green-200/90">{{ $item['time'] }}</span>
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            @click="
                                                const k = '{{ $item['focus_kind'] }}';
                                                const i = {{ $item['focus_id'] }};
                                                const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                                $wire.focusCalendarAgendaItem(k, i, !instant);
                                            "
                                            class="flex w-full items-center justify-between gap-2 text-left {{ $workspaceAgendaBtnBase }}"
                                        >
                                            <span class="truncate font-medium text-green-950 dark:text-green-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-green-900/90 dark:text-green-200/90">{{ $item['time'] }}</span>
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['timedEvents'] ?? []))
                    <div data-testid="calendar-agenda-timed-events">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('Timed events') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['timedEvents'] ?? []) as $item)
                                <li class="rounded-md border border-indigo-500/25 bg-indigo-500/10 px-2 py-1 text-xs dark:border-indigo-500/20 dark:bg-indigo-950/35">
                                    @if ($agendaContext === 'dashboard')
                                        <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                            <span class="truncate font-medium text-indigo-950 dark:text-indigo-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-indigo-800/85 dark:text-indigo-200/85">{{ $item['time'] }}</span>
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            @click="
                                                const k = '{{ $item['focus_kind'] }}';
                                                const i = {{ $item['focus_id'] }};
                                                const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                                $wire.focusCalendarAgendaItem(k, i, !instant);
                                            "
                                            class="flex w-full items-center justify-between gap-2 text-left {{ $workspaceAgendaBtnBase }}"
                                        >
                                            <span class="truncate font-medium text-indigo-950 dark:text-indigo-100">{{ $item['title'] }}</span>
                                            <span class="shrink-0 text-[10px] text-indigo-800/85 dark:text-indigo-200/85">{{ $item['time'] }}</span>
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['allDayEvents'] ?? []))
                    <div data-testid="calendar-agenda-all-day-events">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('All-day events') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['allDayEvents'] ?? []) as $item)
                                <li class="rounded-md border border-emerald-500/25 bg-emerald-500/10 px-2 py-1 text-xs dark:border-emerald-500/20 dark:bg-emerald-950/35">
                                    @if ($agendaContext === 'dashboard')
                                        <a href="{{ $item['workspace_url'] }}" wire:navigate class="block truncate font-medium text-emerald-950 dark:text-emerald-100">
                                            {{ $item['title'] }}
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            @click="
                                                const k = '{{ $item['focus_kind'] }}';
                                                const i = {{ $item['focus_id'] }};
                                                const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                                $wire.focusCalendarAgendaItem(k, i, !instant);
                                            "
                                            class="block w-full truncate text-left font-medium text-emerald-950 dark:text-emerald-100 {{ $workspaceAgendaBtnBase }}"
                                        >
                                            {{ $item['title'] }}
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (
                    empty($selectedDayAgenda['overdueTasks'] ?? [])
                    && empty($selectedDayAgenda['dueDayTasks'] ?? [])
                    && empty($selectedDayAgenda['scheduledStarts'] ?? [])
                    && empty($selectedDayAgenda['timedEvents'] ?? [])
                    && empty($selectedDayAgenda['allDayEvents'] ?? [])
                )
                    <p class="text-xs text-muted-foreground">{{ __('No tasks or events on this day.') }}</p>
                @endif
            </div>
        </div>

        <div
            x-show="calendarNavBusy || dateSelectBusy"
            x-cloak
            class="border-t border-brand-blue/20 px-3 py-1.5 text-center text-[10px] font-medium uppercase tracking-wide text-muted-foreground sm:px-4"
            aria-live="polite"
        >
            {{ __('Updating calendar...') }}
        </div>
    </div>
</div>
