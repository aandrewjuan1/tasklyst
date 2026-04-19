@props([
    'timeLabel' => null,
    'time' => null,
    /** @var 'red'|'amber'|'green'|'indigo'|'emerald'|'violet' */
    'tone' => 'muted',
    'uppercaseLabels' => true,
])

@php
    $labelClasses = match ($tone) {
        'red' => 'text-red-700/95 dark:text-red-300/90',
        'amber' => 'text-amber-900/90 dark:text-amber-200/90',
        'green' => 'text-green-950/90 dark:text-green-200/90',
        'indigo' => 'text-indigo-900/90 dark:text-indigo-200/90',
        'emerald' => 'text-emerald-950/90 dark:text-emerald-200/90',
        'violet' => 'text-violet-900/90 dark:text-violet-200/90',
        default => 'text-muted-foreground',
    };
    $valueClasses = match ($tone) {
        'red' => 'text-red-800 dark:text-red-100',
        'amber' => 'text-amber-950 dark:text-amber-100',
        'green' => 'text-green-950 dark:text-green-100',
        'indigo' => 'text-indigo-950 dark:text-indigo-100',
        'emerald' => 'text-emerald-950 dark:text-emerald-100',
        'violet' => 'text-violet-950 dark:text-violet-100',
        default => 'text-foreground',
    };
@endphp

<div
    @class([
        'flex min-w-[4.5rem] max-w-[min(46%,11rem)] shrink-0 flex-col items-end justify-center gap-0.5 text-end leading-tight',
        'min-h-[2.25rem]' => filled($timeLabel) && ! filled($time),
    ])
>
    @if (filled($timeLabel))
        <span
            @class([
                'text-[9px] font-semibold tracking-wide',
                'uppercase' => $uppercaseLabels,
                'text-[10px]' => ! $uppercaseLabels,
                $labelClasses,
            ])
        >{{ $timeLabel }}</span>
    @endif
    @if (filled($time))
        <span class="text-[10px] font-medium tabular-nums {{ $valueClasses }}">{{ $time }}</span>
    @endif
</div>
