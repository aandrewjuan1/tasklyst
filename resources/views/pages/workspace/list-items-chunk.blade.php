@php
    $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
@endphp
@foreach ($items as $entry)
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
