@props([
    'selectedDate' => null,
    'currentMonth' => null,
    'currentYear' => null,
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
@endphp

<div
    x-data="{
        alpineReady: false,
        month: @js($initialMonth),
        year: @js($initialYear),
        selectedDate: @js($selectedDateString),
        today: @js($today),
        todayCache: null,
        days: [],
        locale: @js(str_replace('_', '-', app()->getLocale())),
        lastNavAt: 0,
        navThrottleMs: 300,
        
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
        
        navAllowed() {
            if (Date.now() - this.lastNavAt < this.navThrottleMs) return false;
            this.lastNavAt = Date.now();
            return true;
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
            
            // Previous month days (grayed out)
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
                });
            }
            
            // Next month days to fill last week (only fill to complete the week, not always 6 rows)
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
        
        changeMonth(offset) {
            if (!this.navAllowed()) return;
            const newMonth = this.month + offset;
            const date = new Date(this.year, newMonth, 1);
            this.month = date.getMonth();
            this.year = date.getFullYear();
            this.updateMonthLabel();
            this.buildDays();
        },
        
        monthLabel: '',
        monthLabelCache: null,
        
        updateMonthLabel() {
            const cacheKey = `${this.year}-${this.month}`;
            if (this.monthLabelCache === cacheKey) return;
            
            const date = new Date(this.year, this.month, 1);
            this.monthLabel = date.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
            this.monthLabelCache = cacheKey;
        },
        
        selectDay(dayData) {
            if (!dayData.dateString) return;
            // Optimistic update: update only selection state without full rebuild
            const oldSelected = this.days.find(d => d.isSelected);
            if (oldSelected) oldSelected.isSelected = false;
            dayData.isSelected = true;
            this.selectedDate = dayData.dateString;
            // Update Livewire selectedDate (server will sync in background)
            $wire.set('selectedDate', dayData.dateString);
        },
        
        goToday() {
            if (!this.navAllowed()) return;
            // Optimistic update: update month/year/selectedDate and rebuild calendar immediately
            const today = new Date();
            this.month = today.getMonth();
            this.year = today.getFullYear();
            this.selectedDate = this.today;
            this.buildDays();
            // Update Livewire selectedDate (server will sync in background)
            $wire.set('selectedDate', this.today);
        },
    }"
    class="w-full"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{-- Calendar Container --}}
    <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
        {{-- Header: Month/Year Navigation --}}
        <div class="flex items-center justify-between border-b border-border/60 px-3 py-3 sm:px-4 sm:py-4 dark:border-zinc-800">
            {{-- Previous Month Button --}}
            <button
                type="button"
                @click="changeMonth(-1)"
                wire:loading.attr="disabled"
                wire:target="selectedDate"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed sm:h-9 sm:w-9"
                aria-label="{{ __('Previous month') }}"
            >
                <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            {{-- Month/Year Display --}}
            <div class="text-center">
                <h2 class="text-sm font-semibold text-foreground tabular-nums sm:text-base" x-text="monthLabel" x-show="alpineReady">
                    {{ \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y') }}
                </h2>
                <h2 class="text-sm font-semibold text-foreground tabular-nums sm:text-base" x-show="!alpineReady">
                    {{ \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y') }}
                </h2>
            </div>

            {{-- Next Month Button --}}
            <button
                type="button"
                @click="changeMonth(1)"
                wire:loading.attr="disabled"
                wire:target="selectedDate"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed sm:h-9 sm:w-9"
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
            <div class="grid grid-cols-7 gap-1 sm:gap-1.5">
                {{-- Server-rendered first paint (visible by default) --}}
                @foreach ($serverDays as $dayData)
                    @if ($dayData['month'] !== 'current')
                        {{-- Previous/Next Month Days (Grayed Out) --}}
                        <div 
                            x-show="!alpineReady"
                            class="flex aspect-square min-w-0 w-full items-center justify-center"
                            style="display: flex;"
                        >
                            <span class="text-xs tabular-nums text-muted-foreground/40 dark:text-muted-foreground/30 sm:text-sm">{{ $dayData['day'] }}</span>
                        </div>
                    @else
                        {{-- Current Month Days (Clickable) --}}
                        <button
                            x-show="!alpineReady"
                            type="button"
                            style="display: flex;"
                            @click="if (typeof $wire !== 'undefined') { $wire.set('selectedDate', '{{ $dayData['dateString'] }}'); }"
                            wire:loading.attr="disabled"
                            wire:target="selectedDate"
                            class="group relative flex h-full w-full min-w-0 items-center justify-center rounded-lg text-xs font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm {{ $dayData['isSelected'] ? 'bg-pink-500 text-white shadow-md shadow-pink-500/20 dark:bg-pink-500 dark:text-white' : ($dayData['isToday'] ? 'bg-pink-50 text-pink-600 ring-2 ring-pink-500/30 dark:bg-pink-900/20 dark:text-pink-400 dark:ring-pink-500/20' : 'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300') }}"
                            data-date="{{ $dayData['dateString'] }}"
                            aria-label="{{ __('Select date') }}: {{ \Illuminate\Support\Carbon::parse($dayData['dateString'])->translatedFormat('F j, Y') }}"
                        >
                            <span class="relative z-10">{{ $dayData['day'] }}</span>
                            
                            @if (!$dayData['isSelected'] && !$dayData['isToday'])
                                <span class="absolute inset-0 rounded-lg bg-foreground/5 opacity-0 transition-opacity group-hover:opacity-100"></span>
                            @endif
                        </button>
                    @endif
                @endforeach
                
                {{-- Alpine reactive (shown when Alpine ready) --}}
                <template x-for="dayData in days" :key="`day-${year}-${month}-${dayData.day}-${dayData.month}`">
                    <div class="flex aspect-square min-w-0 w-full items-center justify-center" x-show="alpineReady" x-cloak>
                        {{-- Previous/Next Month Days (Grayed Out) --}}
                        <div 
                            x-show="dayData.month !== 'current'"
                            class="flex h-full w-full min-w-0 items-center justify-center"
                        >
                            <span class="text-xs tabular-nums text-muted-foreground/40 dark:text-muted-foreground/30 sm:text-sm" x-text="dayData.day"></span>
                        </div>
                        
                        {{-- Current Month Days (Clickable) --}}
                        <button
                            x-show="dayData.month === 'current'"
                            type="button"
                            @click="selectDay(dayData)"
                            wire:loading.attr="disabled"
                            wire:target="selectedDate"
                            class="group relative flex h-full w-full min-w-0 items-center justify-center rounded-lg text-xs font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm"
                            :data-date="dayData.dateString"
                            :aria-label="`{{ __('Select date') }}: ${dayData.dateString}`"
                            :class="{
                                'bg-pink-500 text-white shadow-md shadow-pink-500/20 dark:bg-pink-500 dark:text-white': dayData.isSelected,
                                'bg-pink-50 text-pink-600 ring-2 ring-pink-500/30 dark:bg-pink-900/20 dark:text-pink-400 dark:ring-pink-500/20': !dayData.isSelected && dayData.isToday,
                                'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300': !dayData.isSelected && !dayData.isToday,
                            }"
                        >
                            <span class="relative z-10" x-text="dayData.day"></span>
                            
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

        {{-- Footer: Today Button --}}
        <div class="border-t border-border/60 px-3 py-2.5 sm:px-4 sm:py-3 dark:border-zinc-800">
            <div class="flex items-center justify-center">
                <button
                    type="button"
                    @click="goToday()"
                    wire:loading.attr="disabled"
                    wire:target="selectedDate"
                    class="rounded-lg px-3 py-1.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed sm:px-4 sm:text-xs"
                    aria-label="{{ __('Go to today') }}"
                >
                    {{ __('Today') }}
                </button>
            </div>
        </div>
    </div>
</div>
