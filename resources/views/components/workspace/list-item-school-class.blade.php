@props([
    'schoolClass' => null,
])

@php
    /** @var \App\Models\SchoolClass $schoolClass */
    $schoolClass->loadMissing('recurringSchoolClass');
    $recurring = $schoolClass->recurringSchoolClass;
    $start = $schoolClass->start_datetime;
    $end = $schoolClass->end_datetime;
    $timeLine = $start->translatedFormat('g:i A').' – '.$end->translatedFormat('g:i A');
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
        @if ($recurring !== null)
            <span class="inline-flex items-center gap-1">
                <flux:icon name="clock" class="size-3.5 shrink-0 opacity-80" />
                <span>{{ $timeLine }}</span>
            </span>
            @if ($recurring->recurrence_type?->value === 'weekly' && $recurring->weekdayAbbreviationList() !== '')
                <span class="inline-flex items-center gap-1">
                    <flux:icon name="calendar" class="size-3.5 shrink-0 opacity-80" />
                    <span>{{ $recurring->weekdayAbbreviationList() }}</span>
                </span>
            @elseif ($recurring->recurrence_type !== null)
                <span class="inline-flex items-center gap-1 capitalize">
                    <flux:icon name="arrow-path" class="size-3.5 shrink-0 opacity-80" />
                    <span>{{ $recurring->recurrence_type->value }}</span>
                </span>
            @endif
            @if ($recurring->end_datetime !== null)
                <span class="inline-flex items-center gap-1">
                    <span class="text-muted-foreground/90">{{ __('Through') }}</span>
                    <span>{{ $recurring->end_datetime->translatedFormat('M j, Y') }}</span>
                </span>
            @endif
        @else
            <span class="inline-flex items-center gap-1">
                <flux:icon name="clock" class="size-3.5 shrink-0 opacity-80" />
                <span>{{ $start->translatedFormat('M j, Y · g:i A') }}</span>
            </span>
            <span class="inline-flex items-center gap-1">
                <flux:icon name="clock" class="size-3.5 shrink-0 opacity-80" />
                <span>{{ $end->translatedFormat('M j, Y · g:i A') }}</span>
            </span>
        @endif
    </div>
</div>
