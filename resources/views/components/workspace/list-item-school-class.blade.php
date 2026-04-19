@props([
    'schoolClass' => null,
])

@php
    /** @var \App\Models\SchoolClass $schoolClass */
    $start = $schoolClass->start_datetime;
    $end = $schoolClass->end_datetime;
@endphp

<div
    {{ $attributes->merge([
        'class' => 'list-item-card flex flex-col gap-2 rounded-xl px-3 py-2 lic-surface-school-class scroll-mt-28',
    ]) }}
    data-test="workspace-school-class-item"
>
    <div class="flex min-w-0 items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <h3 class="truncate text-lg font-bold leading-tight text-foreground md:text-xl">
                {{ $schoolClass->subject_name }}
            </h3>
            <p class="mt-0.5 truncate text-sm text-muted-foreground">
                {{ $schoolClass->teacher_name }}
            </p>
        </div>
        <span
            class="lic-item-type-pill lic-item-type-pill--school-class shrink-0"
            aria-hidden="true"
        >
            {{ __('Class') }}
        </span>
    </div>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
        <span class="inline-flex items-center gap-1">
            <flux:icon name="clock" class="size-3.5 shrink-0 opacity-80" />
            <span>{{ __('Start') }}: {{ $start->translatedFormat('M j, Y · g:i A') }}</span>
        </span>
        <span class="inline-flex items-center gap-1">
            <flux:icon name="clock" class="size-3.5 shrink-0 opacity-80" />
            <span>{{ __('End') }}: {{ $end->translatedFormat('M j, Y · g:i A') }}</span>
        </span>
    </div>
</div>
