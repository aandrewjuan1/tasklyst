@props([
    'selectedDate',
])

@php
    $date = \Illuminate\Support\Carbon::parse($selectedDate);
    $today = now()->toDateString();
    $isToday = $date->isToday();

    $previousDate = $date->copy()->subDay()->toDateString();
    $nextDate = $date->copy()->addDay()->toDateString();
@endphp

<div
    x-data="{}"
    class="flex items-center gap-2 mt-4"
>
    <flux:button
        variant="ghost"
        size="xs"
        icon="chevron-left"
        @click="$wire.set('selectedDate', '{{ $previousDate }}')"
        :loading="false"
    />

    <div class="flex flex-col items-center">
        @if (! $isToday)
            <button
                type="button"
                @click="$wire.set('selectedDate', '{{ $today }}')"
                class="text-xs uppercase tracking-wide text-muted-foreground underline-offset-2 hover:underline"
            >
                {{ __('Today') }}
            </button>
        @endif
        <span class="text-sm font-medium">
            {{ $date->translatedFormat('D, M j, Y') }}
        </span>
    </div>

    <flux:button
        variant="ghost"
        size="xs"
        icon="chevron-right"
        @click="$wire.set('selectedDate', '{{ $nextDate }}')"
        :loading="false"
    />
</div>

