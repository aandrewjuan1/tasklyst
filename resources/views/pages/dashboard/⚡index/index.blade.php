@php
    $analytics = $this->analytics;
    $cardOrder = [
        'tasks_created',
        'tasks_completed',
        'completion_rate',
        'overdue',
        'due_soon',
        'focus_work_seconds',
        'focus_sessions',
    ];
    $cardLabels = [
        'tasks_created' => __('Tasks created'),
        'tasks_completed' => __('Tasks completed'),
        'completion_rate' => __('Completion rate'),
        'overdue' => __('Overdue'),
        'due_soon' => __('Due soon'),
        'focus_work_seconds' => __('Focus time'),
        'focus_sessions' => __('Focus sessions'),
    ];
    $cardIcons = [
        'tasks_created' => 'plus-circle',
        'tasks_completed' => 'check-circle',
        'completion_rate' => 'chart-pie',
        'overdue' => 'exclamation-triangle',
        'due_soon' => 'clock',
        'focus_work_seconds' => 'bolt',
        'focus_sessions' => 'circle-stack',
    ];
@endphp

<section class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                {{ __('Dashboard') }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ __('Your task management area') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <div class="rounded-full border border-border/60 bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground sm:text-sm">
                {{ now()->translatedFormat('l, F j, Y') }}
            </div>
        </div>
    </div>

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
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($cardOrder as $key)
                                    @php
                                        $card = $analytics->cards[$key] ?? null;
                                    @endphp
                                    @if ($card)
                                        <div class="rounded-xl border border-border/60 bg-background p-4 shadow-sm">
                                            <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                                @if (isset($cardIcons[$key]))
                                                    <flux:icon name="{{ $cardIcons[$key] }}" class="size-4 text-brand-blue" />
                                                @endif
                                                <span>{{ $cardLabels[$key] ?? $key }}</span>
                                            </div>
                                            <div class="mt-1 text-2xl font-semibold tabular-nums text-foreground">
                                                @if ($key === 'completion_rate')
                                                    {{ $card['current'] }}%
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
                                            </div>
                                            @if (array_key_exists('delta', $card))
                                                <div class="mt-2 text-xs text-muted-foreground">
                                                    {{ __('vs previous period') }}:
                                                    @if (($card['delta'] ?? 0) > 0)
                                                        +{{ $card['delta'] }}
                                                    @elseif (($card['delta'] ?? 0) < 0)
                                                        {{ $card['delta'] }}
                                                    @else
                                                        0
                                                    @endif
                                                    @if ($key !== 'completion_rate' && $key !== 'focus_work_seconds' && isset($card['delta_percentage']) && $card['delta_percentage'] !== null)
                                                        ({{ $card['delta_percentage'] }}%)
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>

                            <div class="rounded-xl border border-border/60 bg-background p-3 shadow-sm">
                                <div class="mb-2 text-sm font-medium text-foreground">{{ __('Daily trend') }}</div>
                                <div x-ref="trendChart" wire:ignore class="h-80 w-full"></div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                                <div class="rounded-xl border border-border/60 bg-background p-3 shadow-sm xl:col-span-2">
                                    <div class="mb-2 text-sm font-medium text-foreground">{{ __('Status breakdown') }}</div>
                                    <div x-ref="statusChart" wire:ignore class="h-80 w-full"></div>
                                </div>

                                <div class="rounded-xl border border-border/60 bg-background p-3 shadow-sm">
                                    <div class="mb-2 text-sm font-medium text-foreground">{{ __('Priority breakdown') }}</div>
                                    <div x-ref="priorityChart" wire:ignore class="h-80 w-full"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                                <div class="rounded-xl border border-border/60 bg-background p-3 shadow-sm">
                                    <div class="mb-2 text-sm font-medium text-foreground">{{ __('Complexity breakdown') }}</div>
                                    <div x-ref="complexityChart" wire:ignore class="h-80 w-full"></div>
                                </div>

                                <div class="rounded-xl border border-border/60 bg-background p-3 shadow-sm">
                                    <div class="mb-2 text-sm font-medium text-foreground">{{ __('Completed by project') }}</div>
                                    <div x-ref="projectChart" wire:ignore class="h-80 w-full"></div>
                                </div>
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
            <div class="sticky top-6 space-y-3" data-focus-lock-viewport>
                <x-workspace.calendar
                    :selected-date="$this->selectedDate"
                    :current-month="$this->calendarMonth"
                    :current-year="$this->calendarYear"
                />

                <x-workspace.upcoming
                    :items="$this->upcoming"
                    :selected-date="$this->selectedDate"
                />
            </div>
        </div>
    </div>
</section>
