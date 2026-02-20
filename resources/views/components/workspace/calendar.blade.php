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
            this.buildDays();
            this.alpineReady = true;
            // Watch for selectedDate changes from Livewire
            this.$watch('$wire.selectedDate', (value) => {
                if (value) {
                    this.selectedDate = value;
                    const date = new Date(value + 'T12:00:00');
                    // Update calendar view to show the selected date's month
                    this.month = date.getMonth();
                    this.year = date.getFullYear();
                    this.buildDays();
                }
            });
        },
        
        navAllowed() {
            if (Date.now() - this.lastNavAt < this.navThrottleMs) return false;
            this.lastNavAt = Date.now();
            return true;
        },
        
        buildDays() {
            if (!this.todayCache) {
                const t = new Date();
                this.todayCache = { year: t.getFullYear(), month: t.getMonth(), date: t.getDate() };
            }
            
            const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
            const previousMonth = new Date(this.year, this.month, 0);
            const daysInPreviousMonth = previousMonth.getDate();
            
            const days = [];
            
            // Previous month days (grayed out)
            const daysToShowFromPreviousMonth = firstDayOfMonth;
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
            const remainder = days.length % 7;
            const blanksNeeded = remainder === 0 ? 0 : 7 - remainder;
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
            const newMonth = this.month + offset;
            const date = new Date(this.year, newMonth, 1);
            this.month = date.getMonth();
            this.year = date.getFullYear();
            this.buildDays();
        },
        
        get monthLabel() {
            const date = new Date(this.year, this.month, 1);
            return date.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
        },
        
        selectDay(dayData) {
            if (!dayData.dateString) return;
            // Optimistic update: update local state and rebuild calendar immediately
            this.selectedDate = dayData.dateString;
            this.buildDays();
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
>
    {{-- Calendar Container --}}
    <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
        {{-- Header: Month/Year Navigation --}}
        <div class="flex items-center justify-between border-b border-border/60 px-4 py-4 dark:border-zinc-800">
            {{-- Previous Month Button --}}
            <button
                type="button"
                @click="changeMonth(-1)"
                wire:loading.attr="disabled"
                wire:target="selectedDate"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('Previous month') }}"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            {{-- Month/Year Display --}}
            <div class="text-center">
                <h2 class="text-base font-semibold text-foreground tabular-nums" x-text="monthLabel">
                    {{ \Illuminate\Support\Carbon::create($currentYear, $currentMonth, 1)->translatedFormat('F Y') }}
                </h2>
            </div>

            {{-- Next Month Button --}}
            <button
                type="button"
                @click="changeMonth(1)"
                wire:loading.attr="disabled"
                wire:target="selectedDate"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('Next month') }}"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        {{-- Calendar Grid --}}
        <div class="p-4">
            {{-- Day Names Header --}}
            <div class="mb-3 grid grid-cols-7 gap-1.5">
                @foreach ($dayNames as $dayName)
                    <div class="flex items-center justify-center">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $dayName }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Calendar Days Grid --}}
            <div class="grid grid-cols-7 gap-1.5">
                {{-- Server-rendered first paint --}}
                @foreach ($serverDays as $dayData)
                    @if ($dayData['month'] !== 'current')
                        {{-- Previous/Next Month Days (Grayed Out) --}}
                        <div 
                            x-show="!alpineReady"
                            class="flex aspect-square w-full items-center justify-center"
                        >
                            <span class="text-sm tabular-nums text-muted-foreground/40 dark:text-muted-foreground/30">{{ $dayData['day'] }}</span>
                        </div>
                    @else
                        {{-- Current Month Days (Clickable) --}}
                        <button
                            x-show="!alpineReady"
                            type="button"
                            @click="if (typeof $wire !== 'undefined') { $wire.set('selectedDate', '{{ $dayData['dateString'] }}'); }"
                            wire:loading.attr="disabled"
                            wire:target="selectedDate"
                            class="group relative flex h-full w-full items-center justify-center rounded-lg text-sm font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed {{ $dayData['isSelected'] ? 'bg-pink-500 text-white shadow-md shadow-pink-500/20 dark:bg-pink-500 dark:text-white' : ($dayData['isToday'] ? 'bg-pink-50 text-pink-600 ring-2 ring-pink-500/30 dark:bg-pink-900/20 dark:text-pink-400 dark:ring-pink-500/20' : 'text-foreground hover:bg-muted/60 hover:text-foreground dark:text-zinc-300') }}"
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
                
                {{-- Alpine reactive (replaces server content when hydrated) --}}
                <template x-for="dayData in days" :key="`day-${year}-${month}-${dayData.day}-${dayData.month}`">
                    <div class="flex aspect-square w-full items-center justify-center" x-show="alpineReady" x-cloak>
                        {{-- Previous/Next Month Days (Grayed Out) --}}
                        <div 
                            x-show="dayData.month !== 'current'"
                            class="flex h-full w-full items-center justify-center"
                        >
                            <span class="text-sm tabular-nums text-muted-foreground/40 dark:text-muted-foreground/30" x-text="dayData.day"></span>
                        </div>
                        
                        {{-- Current Month Days (Clickable) --}}
                        <button
                            x-show="dayData.month === 'current'"
                            type="button"
                            @click="selectDay(dayData)"
                            wire:loading.attr="disabled"
                            wire:target="selectedDate"
                            class="group relative flex h-full w-full items-center justify-center rounded-lg text-sm font-medium tabular-nums transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed"
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
        <div class="border-t border-border/60 px-4 py-3 dark:border-zinc-800">
            <div class="flex items-center justify-center">
                <button
                    type="button"
                    @click="goToday()"
                    wire:loading.attr="disabled"
                    wire:target="selectedDate"
                    class="rounded-lg px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus:outline-none focus:ring-2 focus:ring-pink-500/50 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 disabled:opacity-50 disabled:cursor-not-allowed"
                    aria-label="{{ __('Go to today') }}"
                >
                    {{ __('Today') }}
                </button>
            </div>
        </div>
    </div>
</div>
