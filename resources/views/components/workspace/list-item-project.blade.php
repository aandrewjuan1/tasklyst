@props([
    'item',
    'updatePropertyMethod',
])

@php
    $projectStartDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
    $projectEndDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');
@endphp

<div
    wire:ignore
    x-data="{
        itemId: @js($item->id),
        updatePropertyMethod: @js($updatePropertyMethod),
        startDatetime: @js($projectStartDatetimeInitial),
        endDatetime: @js($projectEndDatetimeInitial),
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        editDateRangeError: null,
        datePickerOriginals: {},
        dateRangeMessages: {
            projectEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
        },
        validateEditDateRange(startVal, endVal) {
            this.editDateRangeError = null;
            if (!startVal || !endVal) {
                return true;
            }
            const startDate = new Date(startVal);
            const endDate = new Date(endVal);
            if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
                return true;
            }
            if (endDate.getTime() < startDate.getTime()) {
                this.editDateRangeError = this.dateRangeMessages.projectEndBeforeStart;
                return false;
            }
            return true;
        },
        async updateProperty(property, value) {
            const snapshot = {
                startDatetime: this.startDatetime,
                endDatetime: this.endDatetime,
            };

            try {
                if (property === 'startDatetime') {
                    this.startDatetime = value;
                } else if (property === 'endDatetime') {
                    this.endDatetime = value;
                }

                $dispatch('item-property-updated', { property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });

                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value);
                if (!ok) {
                    this.startDatetime = snapshot.startDatetime;
                    this.endDatetime = snapshot.endDatetime;
                    $dispatch('item-update-rollback');
                    $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                    return false;
                }
                return true;
            } catch (err) {
                this.startDatetime = snapshot.startDatetime;
                this.endDatetime = snapshot.endDatetime;
                $dispatch('item-update-rollback');
                $wire.$dispatch('toast', { type: 'error', message: err.message || this.editErrorToast });
                return false;
            }
        },
        handleDatePickerOpened(e) {
            e.stopPropagation();
            const path = e.detail.path;
            this.datePickerOriginals[path] = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
        },
        handleDatePickerValueChanged(e) {
            e.stopPropagation();
            const path = e.detail.path;
            const value = e.detail.value;
            const startVal = path === 'startDatetime' ? value : this.startDatetime;
            const endVal = path === 'endDatetime' ? value : this.endDatetime;
            this.validateEditDateRange(startVal, endVal);
        },
        getDatePickerOriginalValue(path) {
            if (path in this.datePickerOriginals) {
                return this.datePickerOriginals[path];
            }
            return path === 'startDatetime' ? this.startDatetime : this.endDatetime;
        },
        dispatchDatePickerRevert(target, path, value) {
            const valueToRevert = value ?? this.getDatePickerOriginalValue(path);
            target.dispatchEvent(new CustomEvent('date-picker-revert', {
                detail: { path, value: valueToRevert ?? null },
                bubbles: true,
            }));
        },
        async handleDatePickerUpdated(e) {
            e.stopPropagation();
            const path = e.detail.path;
            const value = e.detail.value;
            const startVal = path === 'startDatetime' ? value : this.startDatetime;
            const endVal = path === 'endDatetime' ? value : this.endDatetime;
            const isValid = this.validateEditDateRange(startVal, endVal);
            if (!isValid) {
                this.dispatchDatePickerRevert(e.target, path);
                this.editDateRangeError = null;
                return;
            }
            this.editDateRangeError = null;
            const ok = await this.updateProperty(path, value);
            if (!ok) {
                const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                this.dispatchDatePickerRevert(e.target, path, realValue);
            }
        },
    }"
    class="contents"
    @date-picker-opened="handleDatePickerOpened($event)"
    @date-picker-value-changed="handleDatePickerValueChanged($event)"
    @date-picker-updated="handleDatePickerUpdated($event)"
>
    <x-date-picker
        model="startDatetime"
        type="datetime-local"
        :triggerLabel="__('Start')"
        :label="__('Start Date')"
        position="top"
        align="end"
        :initial-value="$projectStartDatetimeInitial"
        data-task-creation-safe
    />

    <x-date-picker
        model="endDatetime"
        type="datetime-local"
        :triggerLabel="__('End')"
        :label="__('End Date')"
        position="top"
        align="end"
        :initial-value="$projectEndDatetimeInitial"
        data-task-creation-safe
    />

    <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
        <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
        <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
    </div>

    <x-workspace.collaborators-badge :count="$item->collaborators->count()" />
</div>
