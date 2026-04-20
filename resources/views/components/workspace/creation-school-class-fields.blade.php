@php
    $scheduleDateFields = [
        [
            'label' => __('Class starts'),
            'model' => 'formData.schoolClass.scheduleStartDate',
            'datePickerLabel' => __('First day in range'),
            'triggerTooltip' => __('Set the first day in range.'),
        ],
        [
            'label' => __('Class ends'),
            'model' => 'formData.schoolClass.scheduleEndDate',
            'datePickerLabel' => __('Last day in range'),
            'triggerTooltip' => __('Set the last day this class meets.'),
        ],
    ];
@endphp

<div class="contents" data-item-creation-safe>
    <x-workspace.teacher-selection position="bottom" align="end" />

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

    <template x-if="formData.schoolClass.scheduleMode === 'recurring'">
        <div class="contents" data-item-creation-safe>
            @foreach ($scheduleDateFields as $dateField)
                <flux:tooltip :content="$dateField['triggerTooltip']">
                    <span class="inline-flex">
                        <x-date-picker
                            :triggerLabel="$dateField['label']"
                            :label="$dateField['datePickerLabel']"
                            :model="$dateField['model']"
                            type="date"
                            position="bottom"
                            align="end"
                        />
                    </span>
                </flux:tooltip>
            @endforeach

            <x-workspace.school-class-hours-selection />
        </div>
    </template>

    <template x-if="formData.schoolClass.scheduleMode === 'one_off'">
        <div class="contents" data-item-creation-safe>
            <x-workspace.school-class-hours-selection />
        </div>
    </template>
</div>
