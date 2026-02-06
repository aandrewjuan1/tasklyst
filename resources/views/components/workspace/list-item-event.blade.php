@props([
    'item',
    'availableTags' => [],
    'updatePropertyMethod',
])

@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

    $eventStatusOptions = [
        ['value' => \App\Enums\EventStatus::Scheduled->value, 'label' => __('Scheduled'), 'color' => \App\Enums\EventStatus::Scheduled->color()],
        ['value' => \App\Enums\EventStatus::Ongoing->value, 'label' => __('Ongoing'), 'color' => \App\Enums\EventStatus::Ongoing->color()],
        ['value' => \App\Enums\EventStatus::Tentative->value, 'label' => __('Tentative'), 'color' => \App\Enums\EventStatus::Tentative->color()],
        ['value' => \App\Enums\EventStatus::Completed->value, 'label' => __('Completed'), 'color' => \App\Enums\EventStatus::Completed->color()],
        ['value' => \App\Enums\EventStatus::Cancelled->value, 'label' => __('Cancelled'), 'color' => \App\Enums\EventStatus::Cancelled->color()],
    ];

    $eventStatusInitialOption = collect($eventStatusOptions)->firstWhere('value', $item->status?->value);

    $eventStatusInitialClass = $eventStatusInitialOption
        ? 'bg-' . $eventStatusInitialOption['color'] . '/10 text-' . $eventStatusInitialOption['color']
        : 'bg-muted text-muted-foreground';

    $eventAllDayInitialClass = $item->all_day
        ? 'bg-emerald-500/10 text-emerald-500 shadow-sm'
        : 'bg-muted text-muted-foreground';

    $eventStartDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
    $eventEndDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');

    $eventRecurrenceInitial = [
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ];

    if ($item->recurringEvent) {
        $re = $item->recurringEvent;
        $daysOfWeek = $re->days_of_week ? (json_decode($re->days_of_week, true) ?? []) : [];
        $eventRecurrenceInitial = [
            'enabled' => true,
            'type' => $re->recurrence_type?->value,
            'interval' => $re->interval ?? 1,
            'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
        ];
    }
@endphp

<div
    wire:ignore
    x-data="{
        itemId: @js($item->id),
        updatePropertyMethod: @js($updatePropertyMethod),
        status: @js($item->status?->value),
        allDay: @js($item->all_day),
        startDatetime: @js($eventStartDatetimeInitial),
        endDatetime: @js($eventEndDatetimeInitial),
        recurrence: @js($eventRecurrenceInitial),
        statusOptions: @js($eventStatusOptions),
        formData: { item: { tagIds: @js($item->tags->pluck('id')->values()->all()) } },
        tags: @js($availableTags),
        newTagName: '',
        creatingTag: false,
        deletingTagIds: new Set(),
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        tagMessages: {
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
        },
        editDateRangeError: null,
        datePickerOriginals: {},
        dateRangeMessages: {
            eventEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
        },
        isTagSelected(tagId) {
            if (!this.formData?.item?.tagIds || !Array.isArray(this.formData.item.tagIds)) {
                return false;
            }
            const tagIdStr = String(tagId);
            return this.formData.item.tagIds.some(id => String(id) === tagIdStr);
        },
        async toggleTag(tagId) {
            if (!this.formData.item.tagIds) {
                this.formData.item.tagIds = [];
            }
            const tagIdsBackup = [...this.formData.item.tagIds];
            const tagIdStr = String(tagId);
            const index = this.formData.item.tagIds.findIndex(id => String(id) === tagIdStr);
            if (index === -1) {
                this.formData.item.tagIds.push(tagId);
            } else {
                this.formData.item.tagIds.splice(index, 1);
            }
            const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
            const ok = await this.updateProperty('tagIds', realTagIds);
            if (!ok) {
                this.formData.item.tagIds = tagIdsBackup;
            }
        },
        async createTagOptimistic(tagNameFromEvent) {
            const tagName = (tagNameFromEvent != null && tagNameFromEvent !== '' ? String(tagNameFromEvent).trim() : (this.newTagName || '').trim());
            if (!tagName || this.creatingTag) {
                return;
            }
            this.newTagName = '';
            const tagNameLower = tagName.toLowerCase();
            const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
            if (existingTag) {
                if (!this.formData.item.tagIds) {
                    this.formData.item.tagIds = [];
                }
                const alreadySelected = this.formData.item.tagIds.some(id => String(id) === String(existingTag.id));
                if (!alreadySelected) {
                    this.formData.item.tagIds.push(existingTag.id);
                    const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
                    await this.updateProperty('tagIds', realTagIds);
                }
                $wire.$dispatch('toast', { type: 'info', message: this.tagMessages.tagAlreadyExists });
                return;
            }
            const tempId = 'temp-' + Date.now();
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.formData.item.tagIds];
            const newTagNameBackup = tagName;
            try {
                if (!this.tags) {
                    this.tags = [];
                }
                this.tags.push({ id: tempId, name: tagName });
                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                if (!this.formData.item.tagIds.includes(tempId)) {
                    this.formData.item.tagIds.push(tempId);
                }
                this.creatingTag = true;
                await $wire.$parent.$call('createTag', tagName, true);
            } catch (err) {
                this.tags = tagsBackup;
                this.formData.item.tagIds = tagIdsBackup;
                this.newTagName = newTagNameBackup;
                $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
            } finally {
                this.creatingTag = false;
            }
        },
        async deleteTagOptimistic(tag) {
            if (this.deletingTagIds?.has(tag.id)) {
                return;
            }
            const isTempTag = String(tag.id).startsWith('temp-');
            const snapshot = { ...tag };
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.formData.item.tagIds];
            const tagIndex = this.tags?.findIndex(t => t.id === tag.id) ?? -1;
            try {
                this.deletingTagIds = this.deletingTagIds || new Set();
                this.deletingTagIds.add(tag.id);
                if (this.tags && tagIndex !== -1) {
                    this.tags = this.tags.filter(t => t.id !== tag.id);
                }
                const selectedIndex = this.formData.item.tagIds?.indexOf(tag.id);
                if (selectedIndex !== undefined && selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                }
                if (!isTempTag) {
                    await $wire.$parent.$call('deleteTag', tag.id);
                }
                const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
                await this.updateProperty('tagIds', realTagIds, true);
            } catch (err) {
                if (tagIndex !== -1 && this.tags) {
                    this.tags.splice(tagIndex, 0, snapshot);
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }
                if (tagIdsBackup.includes(tag.id) && !this.formData.item.tagIds.includes(tag.id)) {
                    this.formData.item.tagIds.push(tag.id);
                }
                $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
            } finally {
                this.deletingTagIds?.delete(tag.id);
            }
        },
        onTagCreated(event) {
            const { id, name } = event.detail || {};
            const nameLower = (name || '').toLowerCase();
            const tempTag = this.tags?.find(tag => (tag.name || '').toLowerCase() === nameLower && String(tag.id).startsWith('temp-'));
            if (tempTag) {
                const tempId = tempTag.id;
                const tempTagIndex = this.tags.findIndex(tag => tag.id === tempId);
                if (tempTagIndex !== -1) {
                    this.tags[tempTagIndex] = { id, name };
                }
                if (this.formData?.item?.tagIds) {
                    const tempIdIndex = this.formData.item.tagIds.indexOf(tempId);
                    if (tempIdIndex !== -1) {
                        this.formData.item.tagIds[tempIdIndex] = id;
                    }
                }
                this.tags = this.tags.filter((tag, idx, arr) => arr.findIndex(t => String(t.id) === String(tag.id)) === idx);
                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                const realTagIds = this.formData.item.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                this.updateProperty('tagIds', realTagIds);
            } else {
                if (this.tags && !this.tags.find(tag => tag.id === id)) {
                    this.tags.push({ id, name });
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }
            }
        },
        onTagDeleted(event) {
            const { id } = event.detail || {};
            if (this.tags) {
                const tagIndex = this.tags.findIndex(tag => tag.id === id);
                if (tagIndex !== -1) {
                    this.tags.splice(tagIndex, 1);
                }
            }
            if (this.formData?.item?.tagIds) {
                const selectedIndex = this.formData.item.tagIds.indexOf(id);
                if (selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                    const realTagIds = this.formData.item.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                    this.updateProperty('tagIds', realTagIds);
                }
            }
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
                this.editDateRangeError = this.dateRangeMessages.eventEndBeforeStart;
                return false;
            }
            return true;
        },
        getOption(options, value) {
            return options.find(o => o.value === value);
        },
        async updateProperty(property, value, silentSuccessToast = false) {
            if (property === 'tagIds') {
                try {
                    const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, silentSuccessToast);
                    if (!ok) {
                        $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                        return false;
                    }
                    return true;
                } catch (err) {
                    $wire.$dispatch('toast', { type: 'error', message: err.message || this.editErrorToast });
                    return false;
                }
            }

            const snapshot = {
                status: this.status,
                allDay: this.allDay,
                startDatetime: this.startDatetime,
                endDatetime: this.endDatetime,
                recurrence: JSON.parse(JSON.stringify(this.recurrence)),
            };

            try {
                if (property === 'status') {
                    this.status = value;
                } else if (property === 'allDay') {
                    this.allDay = value;
                } else if (property === 'startDatetime') {
                    this.startDatetime = value;
                } else if (property === 'endDatetime') {
                    this.endDatetime = value;
                } else if (property === 'recurrence') {
                    this.recurrence = value;
                }

                const promise = $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value);
                const ok = await promise;
                if (!ok) {
                    this.status = snapshot.status;
                    this.allDay = snapshot.allDay;
                    this.startDatetime = snapshot.startDatetime;
                    this.endDatetime = snapshot.endDatetime;
                    this.recurrence = snapshot.recurrence;
                    $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                    return false;
                }

                return true;
            } catch (err) {
                this.status = snapshot.status;
                this.allDay = snapshot.allDay;
                this.startDatetime = snapshot.startDatetime;
                this.endDatetime = snapshot.endDatetime;
                this.recurrence = snapshot.recurrence;
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
            if (path === 'startDatetime' || path === 'endDatetime') {
                $dispatch('event-date-updated', { startDatetime: startVal, endDatetime: endVal });
            }
            const ok = await this.updateProperty(path, value);
            if (!ok) {
                const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                this.dispatchDatePickerRevert(e.target, path, realValue);
                if (path === 'startDatetime' || path === 'endDatetime') {
                    window.dispatchEvent(new CustomEvent('event-date-update-failed', { detail: { eventId: this.itemId }, bubbles: true }));
                }
            }
        },
        async handleRecurringSelectionUpdated(e) {
            e.stopPropagation();
            const value = e.detail.value;
            const ok = await this.updateProperty('recurrence', value);
            if (!ok) {
                const realValue = this.recurrence;
                e.target.dispatchEvent(new CustomEvent('recurring-revert', {
                    detail: { path: 'recurrence', value: realValue ?? null },
                    bubbles: true,
                }));
            }
        },
    }"
    class="contents"
    @date-picker-opened="handleDatePickerOpened($event)"
    @date-picker-value-changed="handleDatePickerValueChanged($event)"
    @date-picker-updated="handleDatePickerUpdated($event)"
    @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
    @tag-created.window="onTagCreated($event)"
    @tag-deleted.window="onTagDeleted($event)"
>
    @if($item->status)
        <x-simple-select-dropdown position="top" align="end">
            <x-slot:trigger>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $eventStatusInitialClass }}"
                    x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                    aria-haspopup="menu"
                >
                    <flux:icon name="check-circle" class="size-3" />
                    <span class="inline-flex items-baseline gap-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                            {{ __('Status') }}:
                        </span>
                        <span class="uppercase" x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : (status || '')">{{ $eventStatusInitialOption ? $eventStatusInitialOption['label'] : '' }}</span>
                    </span>
                    <flux:icon name="chevron-down" class="size-3" />
                </button>
            </x-slot:trigger>

            <div class="flex flex-col py-1">
                @foreach ($eventStatusOptions as $opt)
                    <button
                        type="button"
                        class="{{ $dropdownItemClass }}"
                        :class="{ 'font-semibold text-foreground': status === '{{ $opt['value'] }}' }"
                        @click="updateProperty('status', '{{ $opt['value'] }}')"
                    >
                        {{ $opt['label'] }}
                    </button>
                @endforeach
            </div>
        </x-simple-select-dropdown>
    @endif

    @if($item->recurringEvent)
        <x-recurring-selection
            model="recurrence"
            :initial-value="$eventRecurrenceInitial"
            triggerLabel="{{ __('Recurring') }}"
            position="top"
            align="end"
        />
    @endif

    <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $eventAllDayInitialClass }}"
        :class="allDay ? 'bg-emerald-500/10 text-emerald-500 shadow-sm' : 'bg-muted text-muted-foreground'"
        @click="
            const next = !allDay;
            allDay = next;
            updateProperty('allDay', next);
        "
    >
        <flux:icon name="sun" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('All Day') }}:
            </span>
            <span class="uppercase" x-text="allDay ? '{{ __('Yes') }}' : '{{ __('No') }}'">
                {{ $item->all_day ? __('Yes') : __('No') }}
            </span>
        </span>
    </button>

    <x-date-picker
        model="startDatetime"
        type="datetime-local"
        :triggerLabel="__('Start')"
        :label="__('Start Date')"
        position="top"
        align="end"
        :initial-value="$eventStartDatetimeInitial"
        data-task-creation-safe
    />

    <x-date-picker
        model="endDatetime"
        type="datetime-local"
        :triggerLabel="__('End')"
        :label="__('End Date')"
        position="top"
        align="end"
        :initial-value="$eventEndDatetimeInitial"
        data-task-creation-safe
    />

    <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
        <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
        <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
    </div>

    <div class="w-full basis-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]">
        <span class="inline-flex shrink-0 items-center gap-1 font-semibold uppercase tracking-wide text-muted-foreground">
            <flux:icon name="tag" class="size-3" />
            {{ __('Tags') }}:
        </span>
        <div
            @tag-toggled="toggleTag($event.detail.tagId)"
            @tag-create-request="createTagOptimistic($event.detail.tagName)"
            @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
        >
            <x-workspace.tag-selection position="top" align="end" :selected-tags="$item->tags" />
        </div>
    </div>
</div>

<x-workspace.collaborators-badge :count="$item->collaborators->count()" />

