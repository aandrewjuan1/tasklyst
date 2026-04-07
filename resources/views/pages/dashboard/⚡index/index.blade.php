@php
    $analytics = $this->analytics;
    $cardOrder = [
        'overdue',
        'due_soon',
        'tasks_completed',
        'completion_rate',
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
    $cardIconColorClasses = [
        'tasks_created' => 'bg-emerald-100 text-emerald-700',
        'tasks_completed' => 'bg-green-100 text-green-700',
        'completion_rate' => 'bg-violet-100 text-violet-700',
        'overdue' => 'bg-red-100 text-red-700',
        'due_soon' => 'bg-amber-100 text-amber-700',
        'focus_work_seconds' => 'bg-orange-100 text-orange-700',
        'focus_sessions' => 'bg-cyan-100 text-cyan-700',
    ];
@endphp

<section class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <div class="w-fit rounded-full border border-border/60 bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground sm:text-sm">
                {{ now()->translatedFormat('l, F j, Y') }}
            </div>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                {{ __('Dashboard') }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ __('Your task management area') }}
            </p>
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
                            <div class="relative flex min-h-56 w-full items-center overflow-hidden rounded-2xl border border-brand-blue/20 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15 px-5 py-5 shadow-sm lg:min-h-60 lg:px-7">
                                <div class="relative z-10 max-w-xl space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-blue/90">
                                        {{ __('Welcome to Tasklyst') }}
                                    </p>
                                    <h2 class="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                        {{ __('Plan smarter, execute faster, and finish what matters.') }}
                                    </h2>
                                    <p class="max-w-lg text-sm text-muted-foreground sm:text-base">
                                        {{ __('Tasklyst uses AI-powered task prioritization and smart scheduling to help you focus on the right work at the right time.') }}
                                    </p>
                                    <div class="pt-2 flex flex-wrap items-center gap-2">
                                        <a
                                            href="{{ route('workspace') }}"
                                            class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                        >
                                            <span>{{ __('Start your next task') }}</span>
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

                            <div class="grid grid-cols-4 gap-3">
                                @foreach ($cardOrder as $key)
                                    @php
                                        $card = $analytics->cards[$key] ?? null;
                                    @endphp
                                    @if ($card)
                                        <div class="rounded-xl border border-brand-blue/20 bg-linear-to-br from-brand-blue/10 via-background to-brand-purple/10 p-3 shadow-sm sm:p-4">
                                            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                                @if (isset($cardIcons[$key]))
                                                    <div class="flex size-9 items-center justify-center rounded-lg {{ $cardIconColorClasses[$key] ?? 'bg-zinc-100 text-zinc-700' }}">
                                                        <flux:icon name="{{ $cardIcons[$key] }}" class="size-6" />
                                                    </div>
                                                @endif
                                                <span class="text-2xl font-bold tabular-nums text-foreground">
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
                                                <span class="font-bold">{{ $cardLabels[$key] ?? $key }}</span>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>

                            <div class="grid grid-cols-1 gap-4 2xl:grid-cols-12">
                                @island(name: 'dashboard-trend')
                                    <div
                                        x-data="dashboardAnalyticsCharts({ analytics: @js($this->trendAnalytics), preset: @js($this->trendPreset) })"
                                        x-effect="sync(@js($this->trendAnalytics), @js($this->trendPreset))"
                                        wire:key="dashboard-trend-{{ $this->selectedDate }}-{{ $this->trendPreset }}"
                                        class="overflow-hidden rounded-xl border border-border/60 bg-background shadow-sm 2xl:col-span-8"
                                    >
                                        <div class="flex items-center justify-between border-b border-border/60 px-3 py-2">
                                            <div class="font-primary text-base font-bold text-foreground">{{ __('Trend') }}</div>
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
                                        <div class="px-3 py-3">
                                            <div x-ref="trendChart" wire:ignore class="w-full"></div>
                                        </div>
                                    </div>
                                @endisland

                                @island(name: 'dashboard-status')
                                    <div
                                        x-data="dashboardAnalyticsCharts({ analytics: @js($this->analytics), preset: @js($this->analyticsPreset) })"
                                        x-effect="sync(@js($this->analytics), @js($this->analyticsPreset))"
                                        wire:key="dashboard-status-{{ $this->selectedDate }}-{{ $this->analyticsPreset }}"
                                        class="grid grid-cols-1 gap-4 2xl:col-span-4"
                                    >
                                        <div class="overflow-hidden rounded-xl border border-border/60 bg-background shadow-sm">
                                            <div class="flex items-center justify-between border-b border-border/60 px-3 py-2">
                                                <div class="font-primary text-base font-bold text-foreground">{{ __('Status breakdown') }}</div>
                                            </div>
                                            <div class="px-3 py-3">
                                                <div x-ref="statusChart" wire:ignore class="h-64 w-full"></div>
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

    @auth
        <flux:modal name="task-assistant-chat" flyout position="right" class="h-full max-h-full w-full max-w-lg">
            <livewire:assistant.chat-flyout />
        </flux:modal>
    @endauth
</section>
