@php
    $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
@endphp
@foreach ($items as $entry)
    <x-workspace.list-item-entry
        :entry="$entry"
        :list-filter-date="$entry['isOverdue'] ? null : $selectedDate"
        :filters="$filters"
        :tags="$tags"
        :active-focus-session="$activeFocusSession ?? null"
        :default-work-duration-minutes="$defaultWorkDurationMinutes"
        :pomodoro-settings="$pomodoroSettings"
    />
@endforeach
