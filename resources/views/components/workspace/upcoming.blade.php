@props([
    'items' => collect(),
    'selectedDate' => null,
])

@php
    use Illuminate\Support\Carbon;

    /** @var \Illuminate\Support\Collection $items */
    $items = $items instanceof \Illuminate\Support\Collection ? $items : collect($items);

    // Always base "upcoming" on today, independent of the selected date, filters, and search.
    $baseDate = now()->startOfDay();

    $grouped = $items->groupBy(function (array $entry) {
        /** @var \App\Models\Task|\App\Models\Event|\App\Models\Project $item */
        $item = $entry['item'];
        $kind = $entry['kind'] ?? null;

        $date = match ($kind) {
            'task' => $item->end_datetime,
            'event', 'project' => $item->start_datetime,
            default => null,
        };

        return $date?->toDateString() ?? 'unknown';
    })->sortKeys();

    $maxDays = 7;

    $formatDateLabel = static function (string $dateString) use ($baseDate, $maxDays): string {
        if ($dateString === 'unknown') {
            return __('Someday');
        }

        $date = Carbon::parse($dateString)->startOfDay();

        if ($date->equalTo($baseDate)) {
            return __('Today');
        }

        if ($date->equalTo($baseDate->copy()->addDay())) {
            return __('Tomorrow');
        }

        if ($date->lessThan($baseDate)) {
            return $date->translatedFormat('l, F j');
        }

        $diff = $baseDate->diffInDays($date, false);

        if ($diff > 0 && $diff <= $maxDays) {
            return $date->translatedFormat('D, F j');
        }

        return $date->translatedFormat('l, F j');
    };

    $kindLabel = static function (string $kind): string {
        return match ($kind) {
            'task' => __('Task'),
            'event' => __('Event'),
            'project' => __('Project'),
            default => __('Item'),
        };
    };
@endphp

<div class="w-full">
    <div class="rounded-xl border border-brand-blue/35 bg-brand-light-lavender/90 shadow-lg backdrop-blur-xs dark:border-brand-blue/25 dark:bg-brand-light-lavender/10">
        <div class="flex items-center gap-2 px-4 py-3">
            <flux:icon name="calendar-days" class="size-4 text-muted-foreground" />
            <div class="flex flex-col">
                <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Upcoming (next :days days)', ['days' => 7]) }}
                </span>
                <span class="text-[11px] text-muted-foreground/80">
                    {{ __('Starting today') }}
                </span>
            </div>
        </div>

        @if ($items->isEmpty())
            <div class="px-4 py-3">
                <p class="text-xs text-muted-foreground">
                    {{ __('No upcoming tasks, events, or projects in the next few days.') }}
                </p>
            </div>
        @else
            <div class="max-h-80 space-y-3 overflow-y-auto px-3 py-3">
                @foreach ($grouped as $dateString => $entries)
                    @php
                        /** @var \Illuminate\Support\Collection $entries */
                        $entries = $entries->values();
                        $label = $formatDateLabel($dateString);
                    @endphp

                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ $label }}
                            </span>
                            <span class="text-[10px] text-muted-foreground/70">
                                {{ trans_choice(':count item|:count items', $entries->count(), ['count' => $entries->count()]) }}
                            </span>
                        </div>

                        <ul class="space-y-1.5">
                            @foreach ($entries as $entry)
                                @php
                                    /** @var \App\Models\Task|\App\Models\Event|\App\Models\Project $item */
                                    $item = $entry['item'];
                                    $kind = $entry['kind'] ?? 'item';

                                    $iconName = match ($kind) {
                                        'task' => 'check-circle',
                                        'event' => 'calendar-days',
                                        'project' => 'folder',
                                        default => 'rectangle-group',
                                    };

                                    $accentClass = match ($kind) {
                                        'task' => 'border-[var(--color-brand-blue)]/30 bg-[var(--color-brand-light-blue)] text-[var(--color-brand-navy-blue)]',
                                        'event' => 'border-[var(--color-brand-navy-blue)]/25 bg-[var(--color-brand-light-lavender)] text-[var(--color-brand-navy-blue)]',
                                        'project' => 'border-[var(--color-brand-blue)]/25 bg-[var(--color-brand-light-lavender)] text-[var(--color-brand-blue)]',
                                        default => 'border-border/60 bg-muted text-muted-foreground',
                                    };

                                    $primaryDate = match ($kind) {
                                        'task' => $item->end_datetime,
                                        'event', 'project' => $item->start_datetime,
                                        default => null,
                                    };

                                    $timeLabel = $primaryDate
                                        ? $primaryDate->translatedFormat('M j · H:i')
                                        : __('No time');

                                    $searchLabel = $item->title ?? $item->name ?? __('Untitled');
                                @endphp

                                <li
                                    class="flex items-start gap-2 rounded-lg bg-background/55 px-2.5 py-1.5 shadow-sm dark:bg-white/5"
                                >
                                    <div class="mt-0.5 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $accentClass }}">
                                        <div class="flex items-center gap-1">
                                            <flux:icon name="{{ $iconName }}" class="size-3" />
                                            <span>{{ $kindLabel($kind) }}</span>
                                        </div>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs font-medium text-foreground">
                                            {{ $item->title ?? $item->name ?? __('Untitled') }}
                                        </p>
                                        <p class="text-[11px] text-muted-foreground">
                                            {{ $timeLabel }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

