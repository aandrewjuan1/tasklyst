@props([
    'selectedDate',
])

@php
    $date = \Illuminate\Support\Carbon::parse($selectedDate);
    $today = now()->toDateString();
    $locale = str_replace('_', '-', app()->getLocale());
@endphp

<div
    x-data="{
        displayDate: @js($date->toDateString()),
        today: @js($today),
        locale: @js($locale),
        lastNavAt: 0,
        navThrottleMs: 300,
        init() {
            this.$watch('$wire.selectedDate', (value) => {
                if (value) {
                    this.displayDate = value;
                }
            });
        },
        navAllowed() {
            if (Date.now() - this.lastNavAt < this.navThrottleMs) return false;
            this.lastNavAt = Date.now();
            return true;
        },
        goPrev() {
            if (!this.navAllowed()) return;
            const d = new Date(this.displayDate + 'T12:00:00');
            d.setDate(d.getDate() - 1);
            this.displayDate = d.toISOString().split('T')[0];
            $wire.set('selectedDate', this.displayDate);
        },
        goNext() {
            if (!this.navAllowed()) return;
            const d = new Date(this.displayDate + 'T12:00:00');
            d.setDate(d.getDate() + 1);
            this.displayDate = d.toISOString().split('T')[0];
            $wire.set('selectedDate', this.displayDate);
        },
        goToday() {
            if (!this.navAllowed()) return;
            this.displayDate = this.today;
            $wire.set('selectedDate', this.displayDate);
        },
        formatDate(dateStr) {
            const d = new Date(dateStr + 'T12:00:00');
            return d.toLocaleDateString(this.locale, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }
    }"
    class="mt-4 inline-flex items-center gap-0 rounded-xl border border-border/60 bg-muted/30 px-1 py-1 shadow-sm ring-1 ring-border/20 dark:bg-muted/20"
>
    <flux:button
        variant="ghost"
        size="xs"
        icon="chevron-left"
        @click="goPrev()"
        :loading="false"
        wire:loading.attr="disabled"
        wire:target="selectedDate"
        class="size-8 shrink-0 rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
    />

    <div class="flex min-w-0 items-center gap-2 px-3 py-1">
        <button
            type="button"
            :disabled="displayDate === today"
            :aria-current="displayDate === today ? 'date' : false"
            aria-label="{{ __('Go to today') }}"
            @click="displayDate !== today && goToday()"
            wire:loading.attr="disabled"
            wire:target="selectedDate"
            class="rounded-md px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide transition-colors disabled:cursor-default disabled:opacity-60"
            :class="displayDate === today
                ? 'bg-muted/60 text-muted-foreground'
                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'"
        >
            {{ __('Today') }}
        </button>
        <span class="h-4 w-px shrink-0 bg-border/60" aria-hidden="true"></span>
        <span class="min-w-0 truncate text-sm font-semibold tabular-nums text-foreground" x-text="formatDate(displayDate)">{{ $date->translatedFormat('D, M j, Y') }}</span>
    </div>

    <flux:button
        variant="ghost"
        size="xs"
        icon="chevron-right"
        @click="goNext()"
        :loading="false"
        wire:loading.attr="disabled"
        wire:target="selectedDate"
        class="size-8 shrink-0 rounded-lg text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
    />
</div>

