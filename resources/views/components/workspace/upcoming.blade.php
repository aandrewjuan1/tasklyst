@props([
    'items' => collect(),
    'selectedDate' => null,
])

@php
    use Illuminate\Support\Carbon;

    /** @var \Illuminate\Support\Collection $items */
    $items = $items instanceof \Illuminate\Support\Collection ? $items : collect($items);

    // Always base "upcoming" on today, independent of the selected date.
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

<div class="mt-4 w-full">
    <div class="rounded-xl border border-border/60 bg-background shadow-sm ring-1 ring-border/20 dark:bg-zinc-900/50">
        <div class="flex items-center justify-between border-b border-border/60 px-4 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
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
                                        'task' => 'border-emerald-500/30 bg-emerald-500/5 text-emerald-600 dark:text-emerald-300',
                                        'event' => 'border-sky-500/30 bg-sky-500/5 text-sky-600 dark:text-sky-300',
                                        'project' => 'border-violet-500/30 bg-violet-500/5 text-violet-600 dark:text-violet-300',
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
                                @endphp

                                <li class="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5">
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

