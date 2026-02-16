@foreach ([['label' => __('Start'), 'model' => 'formData.project.startDatetime', 'datePickerLabel' => __('Start Date')], ['label' => __('End'), 'model' => 'formData.project.endDatetime', 'datePickerLabel' => __('End Date')]] as $dateField)
    <div>
        <x-date-picker
            :triggerLabel="$dateField['label']"
            :label="$dateField['datePickerLabel']"
            :model="$dateField['model']"
            type="datetime-local"
            position="bottom"
            align="end"
        />
    </div>
@endforeach
