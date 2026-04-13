@php
    $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
    $previousSectionKey = $previousSection ?? null;
@endphp
@foreach ($items as $entry)
    @php
        $section = $entry['plannerSection'] ?? 'upcoming';
        $sectionLabel = $entry['plannerSectionLabel'] ?? __('Upcoming');
    @endphp
    @if ($section !== $previousSectionKey)
        <div class="px-1 pt-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $sectionLabel }}</h3>
        </div>
        @php $previousSectionKey = $section; @endphp
    @endif
    <x-workspace.list-item-card
        :kind="$entry['kind']"
        :item="$entry['item']"
        :list-filter-date="$entry['isOverdue'] ? null : $selectedDate"
        :filters="$filters"
        :available-tags="$tags"
        :is-overdue="$entry['isOverdue']"
        :active-focus-session="$activeFocusSession ?? null"
        :default-work-duration-minutes="$defaultWorkDurationMinutes"
        :pomodoro-settings="$pomodoroSettings"
        wire:key="{{ $entry['kind'] }}-{{ $entry['item']->id }}"
    />
@endforeach
