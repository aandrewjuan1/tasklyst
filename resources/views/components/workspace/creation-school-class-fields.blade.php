@php
    $dateFields = [
        ['label' => __('Start'), 'model' => 'formData.schoolClass.startDatetime', 'datePickerLabel' => __('Start Date')],
        ['label' => __('End'), 'model' => 'formData.schoolClass.endDatetime', 'datePickerLabel' => __('End Date')],
    ];
@endphp

<input
    type="text"
    x-model="formData.schoolClass.teacherName"
    data-item-creation-safe
    x-bind:disabled="isSubmitting"
    placeholder="{{ __('Teacher') }}"
    autocomplete="off"
    aria-label="{{ __('Teacher') }}"
    class="w-full min-w-0 rounded-md border border-border/60 bg-background/60 px-2.5 py-1.5 text-sm text-foreground shadow-sm ring-1 ring-border/40 placeholder:text-muted-foreground focus:border-0 focus:outline-none focus:ring-2 focus:ring-brand-blue/35 dark:border-border/50 dark:bg-zinc-900/40 dark:ring-border/35"
/>

@foreach ($dateFields as $dateField)
    <x-date-picker
        :triggerLabel="$dateField['label']"
        :label="$dateField['datePickerLabel']"
        :model="$dateField['model']"
        type="datetime-local"
        position="bottom"
        align="end"
    />
@endforeach

<div class="flex w-full flex-wrap items-center gap-2" data-item-creation-safe>
    <x-recurring-selection
        model="formData.schoolClass.recurrence"
        kind="schoolClass"
        position="bottom"
        align="end"
        :compact-when-disabled="true"
    />
</div>
