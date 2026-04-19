@props([
    'entry',
    'listFilterDate',
    'filters' => [],
    'tags',
    'activeFocusSession' => null,
    'defaultWorkDurationMinutes' => 25,
    'pomodoroSettings' => null,
    'keyPrefix' => null,
    /** When true (completed section), list cards always show as not overdue — matches previous list.blade behavior. */
    'completedStrip' => false,
])

@php
    $kind = $entry['kind'];
    $item = $entry['item'];
    $isOverdueForCard = $completedStrip ? false : $entry['isOverdue'];

    $wireKey = $keyPrefix === 'completed'
        ? ($kind === 'schoolClass'
            ? 'completed-schoolClass-'.$item->id
            : 'completed-'.$kind.'-'.$item->id)
        : ($kind === 'schoolClass'
            ? 'schoolClass-'.$item->id
            : $kind.'-'.$item->id);
@endphp

<x-workspace.list-item-card
    :kind="$kind"
    :item="$item"
    :list-filter-date="$listFilterDate"
    :filters="$filters"
    :available-tags="$tags"
    :is-overdue="$isOverdueForCard"
    :active-focus-session="$activeFocusSession"
    :default-work-duration-minutes="$defaultWorkDurationMinutes"
    :pomodoro-settings="$pomodoroSettings"
    wire:key="{{ $wireKey }}"
/>
