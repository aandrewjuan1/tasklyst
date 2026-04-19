@php
    $scheduleDateFields = [
        ['label' => __('Schedule starts'), 'model' => 'formData.schoolClass.scheduleStartDate', 'datePickerLabel' => __('First day in range')],
        ['label' => __('Schedule ends'), 'model' => 'formData.schoolClass.scheduleEndDate', 'datePickerLabel' => __('Last day in range')],
    ];
@endphp

<div class="flex w-full min-w-0 flex-col gap-1" data-item-creation-safe>
    <span class="text-xs font-medium text-muted-foreground">{{ __('Teacher') }}</span>
    <x-workspace.teacher-selection position="bottom" align="end" />
</div>

<div class="flex w-full flex-col gap-1.5" data-item-creation-safe>
    <span class="text-xs font-medium text-muted-foreground">{{ __('Schedule') }}</span>
    <div class="flex flex-wrap items-center gap-2">
        <div
            class="group/sc inline-flex min-w-0 shrink"
            :data-schedule-mode="formData.schoolClass.scheduleMode"
            x-bind:inert="isSubmitting"
            @recurring-selection-opened="if ($event.detail?.path === 'formData.schoolClass.recurrence') { formData.schoolClass.scheduleMode = 'recurring'; clearSchoolClassMeetingDateForRecurringChoice(); }"
            @recurring-selection-updated="if ($event.detail?.path === 'formData.schoolClass.recurrence') { clearSchoolClassMeetingDateForRecurringChoice(); }"
        >
            <x-recurring-selection
                model="formData.schoolClass.recurrence"
                kind="schoolClass"
                position="bottom"
                align="end"
                :school-class-creation="true"
            />
        </div>
        <div
            class="group/sc inline-flex min-w-0 shrink"
            :data-schedule-mode="formData.schoolClass.scheduleMode"
            x-bind:inert="isSubmitting"
            @date-picker-opened="if ($event.detail?.path === 'formData.schoolClass.meetingDate') { formData.schoolClass.scheduleMode = 'one_off' }"
        >
            <x-date-picker
                :label="__('Meeting date')"
                :trigger-label="__('One meeting')"
                model="formData.schoolClass.meetingDate"
                type="date"
                position="bottom"
                align="end"
                :school-class-meeting-day="true"
            />
        </div>
    </div>
</div>

<template x-if="formData.schoolClass.scheduleMode === 'recurring'">
    <div class="flex w-full flex-col gap-3" data-item-creation-safe>
        <div class="flex w-full flex-wrap gap-2">
            @foreach ($scheduleDateFields as $dateField)
                <x-date-picker
                    :triggerLabel="$dateField['label']"
                    :label="$dateField['datePickerLabel']"
                    :model="$dateField['model']"
                    type="date"
                    position="bottom"
                    align="end"
                />
            @endforeach
        </div>

        <x-workspace.school-class-hours-selection />
    </div>
</template>

<template x-if="formData.schoolClass.scheduleMode === 'one_off'">
    <div class="flex w-full flex-col gap-3" data-item-creation-safe>
        <x-workspace.school-class-hours-selection />
    </div>
</template>
