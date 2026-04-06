@php
    $analytics = $this->analytics;
@endphp

<section
    x-data="dashboardAnalyticsCharts({ analytics: @js($analytics), preset: @js($this->analyticsPreset) })"
    x-effect="sync(@js($this->analytics), @js($this->analyticsPreset))"
    class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg border border-border/60 bg-muted/30 p-0.5">
            @foreach (['7d' => '7D', '30d' => '30D', '90d' => '90D', 'this_month' => __('This month')] as $presetKey => $presetLabel)
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    @class([
                        'bg-background text-foreground shadow-sm' => $this->analyticsPreset === $presetKey,
                        'text-muted-foreground hover:text-foreground' => $this->analyticsPreset !== $presetKey,
                    ])
                    wire:click="$set('analyticsPreset', '{{ $presetKey }}')"
                    wire:loading.attr="disabled"
                    wire:target="analyticsPreset"
                >
                    {{ $presetLabel }}
                </button>
            @endforeach
        </div>

        @if ($analytics)
            <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span class="rounded-full border border-border/60 bg-muted/40 px-2 py-1">
                    {{ __('Completed') }}: {{ $analytics->cards['tasks_completed']['current'] ?? 0 }}
                </span>
                <span class="rounded-full border border-border/60 bg-muted/40 px-2 py-1">
                    {{ __('Overdue') }}: {{ $analytics->cards['overdue']['current'] ?? 0 }}
                </span>
                <span class="rounded-full border border-border/60 bg-muted/40 px-2 py-1">
                    {{ __('Focus') }}: {{ $analytics->cards['focus_work_seconds']['current'] ?? 0 }}s
                </span>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="rounded-xl border border-border/60 bg-background p-3 xl:col-span-2">
            <div class="mb-2 text-sm font-medium text-foreground">{{ __('Daily Trend') }}</div>
            <div x-ref="trendChart" wire:ignore class="h-80 w-full"></div>
        </div>

        <div class="rounded-xl border border-border/60 bg-background p-3">
            <div class="mb-2 text-sm font-medium text-foreground">{{ __('Status Breakdown') }}</div>
            <div x-ref="statusChart" wire:ignore class="h-80 w-full"></div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="rounded-xl border border-border/60 bg-background p-3">
            <div class="mb-2 text-sm font-medium text-foreground">{{ __('Priority Breakdown') }}</div>
            <div x-ref="priorityChart" wire:ignore class="h-80 w-full"></div>
        </div>

        <div class="rounded-xl border border-border/60 bg-background p-3">
            <div class="mb-2 text-sm font-medium text-foreground">{{ __('Complexity Breakdown') }}</div>
            <div x-ref="complexityChart" wire:ignore class="h-80 w-full"></div>
        </div>

        <div class="rounded-xl border border-border/60 bg-background p-3">
            <div class="mb-2 text-sm font-medium text-foreground">{{ __('Completed By Project') }}</div>
            <div x-ref="projectChart" wire:ignore class="h-80 w-full"></div>
        </div>
    </div>

    <div wire:loading.flex wire:target="analyticsPreset" class="items-center justify-end text-xs text-muted-foreground">
        {{ __('Updating charts...') }}
    </div>
</section>