@props([
    'selectedDate' => null,
    'currentMonth' => null,
    'currentYear' => null,
    'monthMeta' => [],
    'selectedDayAgenda' => [],
])

@php
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
    
    // Build server-rendered days array for first paint
    $calendarDate = \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1);
    $firstDayOfMonth = $calendarDate->dayOfWeek;
    $daysInMonth = $calendarDate->daysInMonth;
    $previousMonth = $calendarDate->copy()->subMonth();
    $daysInPreviousMonth = $previousMonth->daysInMonth;
    $daysToShowFromPreviousMonth = $firstDayOfMonth;
    
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
@endphp

<div
    x-data="{
        alpineReady: false,
        month: @js($initialMonth),
        year: @js($initialYear),
        selectedDate: @js($selectedDateString),
        today: @js($today),
        monthMeta: @js($monthMeta),
        todayCache: null,
        days: [],
        locale: @js(str_replace('_', '-', app()->getLocale())),
        isBusy: false,
        busyContext: '',
        
        init() {
            // Initialize Alpine store if not already initialized
            Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: null, focusReady: false });
            
            // Initialize today cache once
            const t = new Date();
            this.todayCache = { year: t.getFullYear(), month: t.getMonth(), date: t.getDate() };
            
            this.buildDays();
            this.updateMonthLabel();
            this.alpineReady = true;
            
            // Watch for selectedDate changes from Livewire
            this.$watch('$wire.selectedDate', (value) => {
                if (!value) return;
                const date = new Date(value + 'T12:00:00');
                const newMonth = date.getMonth();
                const newYear = date.getFullYear();
                
                // Only rebuild if month/year changed
                if (newMonth !== this.month || newYear !== this.year) {
                    this.month = newMonth;
                    this.year = newYear;
                    this.updateMonthLabel();
                    this.buildDays();
                } else {
                    // Just update selection state without full rebuild
                    this.selectedDate = value;
                    this.days.forEach(day => {
                        day.isSelected = day.dateString === value;
                    });
                }
            });
        },
        
        buildDays() {
            const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
            const previousMonth = new Date(this.year, this.month, 0);
            const daysInPreviousMonth = previousMonth.getDate();
            
            const daysToShowFromPreviousMonth = firstDayOfMonth;
            const remainder = (daysToShowFromPreviousMonth + daysInMonth) % 7;
            const blanksNeeded = remainder === 0 ? 0 : 7 - remainder;
            const expectedLength = daysToShowFromPreviousMonth + daysInMonth + blanksNeeded;
            
            // Reuse array if same size to reduce GC pressure
            let days;
            if (this.days.length === expectedLength) {
                this.days.length = 0; // Clear but keep array reference
                days = this.days;
            } else {
                days = [];
            }
            
            // Leading padding cells (no labels; keeps weekday alignment)
            for (let i = daysInPreviousMonth - daysToShowFromPreviousMonth + 1; i <= daysInPreviousMonth; i++) {
                days.push({
                    day: i,
                    month: 'previous',
                    isToday: false,
                    isSelected: false,
                    dateString: null,
                });
            }
            
            // Current month days
            for (let day = 1; day <= daysInMonth; day++) {
                // Use T12:00:00 to avoid timezone shifts (same pattern as date-switcher)
                const monthStr = String(this.month + 1).padStart(2, '0');
                const dayStr = String(day).padStart(2, '0');
                const dateString = `${this.year}-${monthStr}-${dayStr}`;
                const isToday = this.todayCache.year === this.year && 
                               this.todayCache.month === this.month && 
                               this.todayCache.date === day;
                const isSelected = this.selectedDate === dateString;
                
                days.push({
                    day: day,
                    month: 'current',
                    isToday: isToday,
                    isSelected: isSelected,
                    dateString: dateString,
                    meta: this.getMeta(dateString),
                });
            }
            
            // Trailing padding cells (no labels)
            for (let day = 1; day <= blanksNeeded; day++) {
                days.push({
                    day: day,
                    month: 'next',
                    isToday: false,
                    isSelected: false,
                    dateString: null,
                });
            }
            
            this.days = days;
        },
        
        async changeMonth(offset) {
            this.isBusy = true;
            this.busyContext = 'calendar-nav';
            try {
                await $wire.browseCalendarMonth(offset);
                if ($wire.calendarViewYear != null && $wire.calendarViewMonth != null) {
                    this.year = $wire.calendarViewYear;
                    this.month = $wire.calendarViewMonth - 1;
                }
                if ($wire.calendarGridMetaForJs && typeof $wire.calendarGridMetaForJs === 'object') {
                    this.monthMeta = $wire.calendarGridMetaForJs;
                }
                this.updateMonthLabel();
                this.buildDays();
            } finally {
                this.isBusy = false;
                this.busyContext = '';
            }
        },
        
        monthLabel: @js($initialMonthLabel),
        monthLabelCache: @js($initialYear . '-' . $initialMonth),
        
        updateMonthLabel() {
            const cacheKey = `${this.year}-${this.month}`;
            if (this.monthLabelCache === cacheKey) return;
            
            const date = new Date(this.year, this.month, 1);
            this.monthLabel = date.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
            this.monthLabelCache = cacheKey;
        },
        
        async selectDay(dayData) {
            if (!dayData.dateString) return;
            if (dayData.dateString === this.selectedDate) {
                return;
            }
            const previousSelected = this.selectedDate;
            const oldSelected = this.days.find(d => d.isSelected);
            if (oldSelected) oldSelected.isSelected = false;
            dayData.isSelected = true;
            this.selectedDate = dayData.dateString;

            try {
                this.isBusy = true;
                this.busyContext = 'date-select';
                await $wire.set('selectedDate', dayData.dateString);
            } catch (error) {
                this.selectedDate = previousSelected;
                this.days.forEach((day) => {
                    day.isSelected = day.dateString === previousSelected;
                });
            } finally {
                this.isBusy = false;
                this.busyContext = '';
            }
        },
        getMeta(dateString) {
            if (!dateString || !this.monthMeta || typeof this.monthMeta !== 'object') {
                return { task_count: 0, overdue_count: 0, due_count: 0, urgent_count: 0, event_count: 0, conflict_count: 0, recurring_count: 0, all_day_count: 0 };
            }

            return this.monthMeta[dateString] ?? { task_count: 0, overdue_count: 0, due_count: 0, urgent_count: 0, event_count: 0, conflict_count: 0, recurring_count: 0, all_day_count: 0 };
        },
        handleKeydown(event) {
            const tag = (event.target?.tagName ?? '').toLowerCase();
            if (['input', 'textarea', 'select', 'button'].includes(tag)) return;
            if (this.isBusy) return;

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                $wire.navigateSelectedDate(-1);
                return;
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                $wire.navigateSelectedDate(1);
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                $wire.navigateSelectedDate(-7);
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                $wire.navigateSelectedDate(7);
                return;
            }
            if (event.key.toLowerCase() === 'n') {
                event.preventDefault();
                this.changeMonth(1);
                return;
            }
            if (event.key.toLowerCase() === 'p') {
                event.preventDefault();
                this.changeMonth(-1);
            }
        },
    }"
    class="w-full"
    tabindex="0"
    @keydown="handleKeydown($event)"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{-- Calendar Container --}}
    <div
        class="rounded-xl border border-brand-blue/35 bg-brand-light-lavender/90 shadow-lg backdrop-blur-xs transition-opacity dark:border-brand-blue/25 dark:bg-brand-light-lavender/10"
        :class="isBusy ? 'opacity-90' : 'opacity-100'"
    >
        {{-- Header: Month/Year Navigation --}}
        <div class="flex items-center justify-between px-3 py-3 sm:px-4 sm:py-4">
            {{-- Previous Month Button --}}
            <button
                type="button"
                @click="changeMonth(-1)"
                :disabled="isBusy"
                wire:loading.attr="disabled"
                wire:target="browseCalendarMonth,selectedDate"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9"
                aria-label="{{ __('Previous month') }}"
            >
                <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            {{-- Month/Year Display: single element so "Month Year" is not shown twice before Alpine hydrates --}}
            <div class="text-center">
                <h2 class="text-sm font-semibold text-foreground tabular-nums sm:text-base" x-text="monthLabel">
                    {{ \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y') }}
                </h2>
            </div>

            {{-- Next Month Button --}}
            <button
                type="button"
                @click="changeMonth(1)"
                :disabled="isBusy"
                wire:loading.attr="disabled"
                wire:target="browseCalendarMonth,selectedDate"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9"
                aria-label="{{ __('Next month') }}"
            >
                <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
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
                            :disabled="isBusy"
                            class="group relative box-border flex min-h-10 h-full w-full min-w-0 items-center justify-center rounded-lg border border-border/25 px-0.5 text-xs font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-brand-blue/40 focus:ring-offset-1 dark:border-zinc-600/40 dark:focus:ring-offset-zinc-900 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-11 sm:text-sm {{ $dayData['isSelected'] ? 'border-white/25 bg-brand-blue text-white shadow-md' : ($dayData['isToday'] ? 'border-brand-blue/30 bg-brand-light-blue text-brand-navy-blue ring-2 ring-brand-blue/30 dark:border-brand-blue/35 dark:bg-muted/40 dark:text-foreground dark:ring-brand-blue/25' : 'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300') }}"
                            data-date="{{ $dayData['dateString'] }}"
                            aria-label="{{ __('Select date') }}: {{ \Illuminate\Support\Carbon::parse($dayData['dateString'])->translatedFormat('F j, Y') }}"
                        >
                            <span class="relative z-10">{{ $dayData['day'] }}</span>

                            @php
                                $hasOverdue = ($meta['overdue_count'] ?? 0) > 0;
                                $hasUrgent = ($meta['urgent_count'] ?? 0) > 0 || ($meta['conflict_count'] ?? 0) > 0;
                                $hasScheduled = ($meta['due_count'] ?? 0) > 0 || ($meta['event_count'] ?? 0) > 0;
                            @endphp
                            @if ($hasOverdue || $hasUrgent || $hasScheduled)
                                <div class="pointer-events-none absolute -right-1 -top-1 z-20 flex items-center gap-1 rounded-full bg-background/90 px-1 py-0.5 shadow-xs dark:bg-zinc-900/90">
                                    @if ($hasOverdue)
                                        <flux:tooltip content="{{ __('Overdue items') }}">
                                            <span class="inline-flex size-2 rounded-full bg-red-500"></span>
                                        </flux:tooltip>
                                    @endif
                                    @if ($hasUrgent)
                                        <flux:tooltip content="{{ __('Urgent or conflicting') }}">
                                            <span class="inline-flex size-2 rounded-full bg-amber-500"></span>
                                        </flux:tooltip>
                                    @endif
                                    @if ($hasScheduled)
                                        <flux:tooltip content="{{ __('Scheduled tasks or events') }}">
                                            <span class="inline-flex size-2 rounded-full bg-cyan-500"></span>
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
                            :disabled="isBusy"
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
                                || (dayData.meta?.urgent_count ?? getMeta(dayData.dateString).urgent_count) > 0
                                || (dayData.meta?.conflict_count ?? getMeta(dayData.dateString).conflict_count) > 0
                                || (dayData.meta?.due_count ?? getMeta(dayData.dateString).due_count) > 0
                                || (dayData.meta?.event_count ?? getMeta(dayData.dateString).event_count) > 0
                            ">
                                <div class="pointer-events-none absolute -right-1 -top-1 z-20 flex items-center gap-1 rounded-full bg-background/90 px-1 py-0.5 shadow-xs dark:bg-zinc-900/90">
                                    <template x-if="(dayData.meta?.overdue_count ?? getMeta(dayData.dateString).overdue_count) > 0">
                                        <flux:tooltip content="{{ __('Overdue items') }}">
                                            <span class="inline-flex size-2 rounded-full bg-red-500"></span>
                                        </flux:tooltip>
                                    </template>
                                    <template x-if="(dayData.meta?.urgent_count ?? getMeta(dayData.dateString).urgent_count) > 0 || (dayData.meta?.conflict_count ?? getMeta(dayData.dateString).conflict_count) > 0">
                                        <flux:tooltip content="{{ __('Urgent or conflicting') }}">
                                            <span class="inline-flex size-2 rounded-full bg-amber-500"></span>
                                        </flux:tooltip>
                                    </template>
                                    <template x-if="(dayData.meta?.due_count ?? getMeta(dayData.dateString).due_count) > 0 || (dayData.meta?.event_count ?? getMeta(dayData.dateString).event_count) > 0">
                                        <flux:tooltip content="{{ __('Scheduled tasks or events') }}">
                                            <span class="inline-flex size-2 rounded-full bg-cyan-500"></span>
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

            <div class="mb-2 grid grid-cols-4 gap-1 text-center">
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Tasks') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-tasks">{{ $selectedDayAgenda['summary']['tasks'] ?? 0 }}</p>
                </div>
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Events') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-events">{{ $selectedDayAgenda['summary']['events'] ?? 0 }}</p>
                </div>
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Conflicts') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-conflicts">{{ $selectedDayAgenda['summary']['conflicts'] ?? 0 }}</p>
                </div>
                <div class="rounded-md bg-muted/50 px-1 py-1">
                    <p class="text-[10px] text-muted-foreground">{{ __('Overdue') }}</p>
                    <p class="text-xs font-semibold text-foreground" data-testid="calendar-agenda-summary-overdue">{{ $selectedDayAgenda['summary']['overdue'] ?? 0 }}</p>
                </div>
            </div>

            <div class="max-h-48 space-y-2 overflow-y-auto pr-1">
                @if (!empty($selectedDayAgenda['urgentTasks']))
                    <div>
                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">{{ __('Urgent tasks') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['urgentTasks'] ?? []) as $item)
                                <li class="rounded-md bg-background/70 px-2 py-1 text-xs">
                                    <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-foreground">{{ $item['title'] }}</span>
                                        <span class="shrink-0 text-[10px] text-muted-foreground">{{ $item['time'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['timedEvents']))
                    <div>
                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('Timed events') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['timedEvents'] ?? []) as $item)
                                <li class="rounded-md bg-background/70 px-2 py-1 text-xs">
                                    <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-foreground">{{ $item['title'] }}</span>
                                        <span class="shrink-0 text-[10px] text-muted-foreground">{{ $item['time'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['allDayEvents']))
                    <div>
                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('All-day events') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['allDayEvents'] ?? []) as $item)
                                <li class="rounded-md bg-background/70 px-2 py-1 text-xs">
                                    <a href="{{ $item['workspace_url'] }}" wire:navigate class="block truncate font-medium text-foreground">
                                        {{ $item['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($selectedDayAgenda['carryoverTasks']))
                    <div>
                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('Carryover tasks') }}</p>
                        <ul class="space-y-1">
                            @foreach (($selectedDayAgenda['carryoverTasks'] ?? []) as $item)
                                <li class="rounded-md bg-background/70 px-2 py-1 text-xs">
                                    <a href="{{ $item['workspace_url'] }}" wire:navigate class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-foreground">{{ $item['title'] }}</span>
                                        <span class="shrink-0 text-[10px] text-muted-foreground">{{ $item['time'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (
                    empty($selectedDayAgenda['urgentTasks'])
                    && empty($selectedDayAgenda['timedEvents'])
                    && empty($selectedDayAgenda['allDayEvents'])
                    && empty($selectedDayAgenda['carryoverTasks'])
                )
                    <p class="text-xs text-muted-foreground">{{ __('No scheduled items for this day.') }}</p>
                @endif
            </div>
        </div>

        <div
            x-show="isBusy"
            x-cloak
            class="border-t border-brand-blue/20 px-3 py-1.5 text-center text-[10px] font-medium uppercase tracking-wide text-muted-foreground sm:px-4"
            aria-live="polite"
        >
            {{ __('Updating calendar...') }}
        </div>
    </div>
</div>
