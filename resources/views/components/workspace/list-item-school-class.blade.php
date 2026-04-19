@props([
    'schoolClass' => null,
])

@php
    /** @var \App\Models\SchoolClass $schoolClass */
    $recurring = $schoolClass->recurringSchoolClass;
    $teacher = $schoolClass->teacher;
    $teacherName = trim((string) ($teacher?->name ?? ''));
    $start = $schoolClass->start_datetime;
    $end = $schoolClass->end_datetime;
    $startTimeLabel = $schoolClass->start_time ? \Illuminate\Support\Carbon::parse($schoolClass->start_time)->translatedFormat('g:i A') : null;
    $endTimeLabel = $schoolClass->end_time ? \Illuminate\Support\Carbon::parse($schoolClass->end_time)->translatedFormat('g:i A') : null;
    $timeLine = ($startTimeLabel !== null && $endTimeLabel !== null)
        ? $startTimeLabel.' – '.$endTimeLabel
        : __('Not set');
    $meetingDateLine = $start?->translatedFormat('M j, Y') ?? __('Not set');
    $classStartsLine = $start?->translatedFormat('M j, Y') ?? __('Not set');
    $classEndsLine = ($recurring?->end_datetime ?? $end)?->translatedFormat('M j, Y') ?? __('Not set');
    $recurrenceUsesWeekdayAbbreviations = $recurring?->recurrence_type?->value === 'weekly' && $recurring?->weekdayAbbreviationList() !== '';
    $recurrenceLine = $recurring === null
        ? null
        : ($recurrenceUsesWeekdayAbbreviations
            ? $recurring->weekdayAbbreviationList()
            : ($recurring->recurrence_type?->value ?? __('Not set')));
@endphp

<div
    {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}
    data-test="workspace-school-class-item"
>
    @if ($recurring !== null && $recurrenceLine !== null)
        <div class="flex flex-wrap items-center gap-2 text-muted-foreground">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="arrow-path" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Repeats') }}:
                    </span>
                    <span @class([
                        'text-[11px] font-semibold',
                        'uppercase' => ! $recurrenceUsesWeekdayAbbreviations,
                    ])>
                        {{ $recurrenceLine }}
                    </span>
                </span>
            </span>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 text-muted-foreground">
        @if ($teacherName !== '')
            <flux:tooltip :content="$teacherName" position="top" align="start">
                <span
                    tabindex="0"
                    class="inline-flex max-w-[min(100%,14rem)] cursor-default items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <flux:icon name="academic-cap" class="size-3 shrink-0" />
                    <span class="min-w-0 truncate text-[10px] font-semibold uppercase leading-tight">
                        {{ $teacherName }}
                    </span>
                </span>
            </flux:tooltip>
        @endif
        @if ($recurring !== null)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Class starts') }}:
                    </span>
                    <span class="text-[11px] font-semibold uppercase">
                        {{ $classStartsLine }}
                    </span>
                </span>
            </span>

            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Class ends') }}:
                    </span>
                    <span class="text-[11px] font-semibold uppercase">
                        {{ $classEndsLine }}
                    </span>
                </span>
            </span>

            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Hours') }}:
                    </span>
                    <span class="text-[11px] font-semibold uppercase">
                        {{ $timeLine }}
                    </span>
                </span>
            </span>

        @else
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Meeting date') }}:
                    </span>
                    <span class="text-[11px] font-semibold uppercase">
                        {{ $meetingDateLine }}
                    </span>
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3 shrink-0" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Hours') }}:
                    </span>
                    <span class="text-[11px] font-semibold uppercase">
                        {{ $timeLine }}
                    </span>
                </span>
            </span>
        @endif
    </div>
</div>
