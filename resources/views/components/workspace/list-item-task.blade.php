@props([
    'item',
    'availableTags' => [],
    'updatePropertyMethod',
    'listFilterDate' => null,
    'initialStatus' => null,
])

@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

    $statusOptions = [
        ['value' => 'to_do', 'label' => __('To Do'), 'color' => \App\Enums\TaskStatus::ToDo->color()],
        ['value' => 'doing', 'label' => __('Doing'), 'color' => \App\Enums\TaskStatus::Doing->color()],
        ['value' => 'done', 'label' => __('Done'), 'color' => \App\Enums\TaskStatus::Done->color()],
    ];

    $priorityOptions = [
        ['value' => 'low', 'label' => __('Low'), 'color' => \App\Enums\TaskPriority::Low->color()],
        ['value' => 'medium', 'label' => __('Medium'), 'color' => \App\Enums\TaskPriority::Medium->color()],
        ['value' => 'high', 'label' => __('High'), 'color' => \App\Enums\TaskPriority::High->color()],
        ['value' => 'urgent', 'label' => __('Urgent'), 'color' => \App\Enums\TaskPriority::Urgent->color()],
    ];

    $complexityOptions = [
        ['value' => 'simple', 'label' => __('Simple'), 'color' => \App\Enums\TaskComplexity::Simple->color()],
        ['value' => 'moderate', 'label' => __('Moderate'), 'color' => \App\Enums\TaskComplexity::Moderate->color()],
        ['value' => 'complex', 'label' => __('Complex'), 'color' => \App\Enums\TaskComplexity::Complex->color()],
    ];

    $durationOptions = [
        ['value' => 15, 'label' => '15 min'],
        ['value' => 30, 'label' => '30 min'],
        ['value' => 60, 'label' => '1 hour'],
        ['value' => 120, 'label' => '2 hours'],
        ['value' => 240, 'label' => '4 hours'],
        ['value' => 480, 'label' => '8+ hours'],
    ];

    $initialStatusValue = $initialStatus ?? $item->status?->value;
    $statusInitialOption = collect($statusOptions)->firstWhere('value', $initialStatusValue);
    $priorityInitialOption = collect($priorityOptions)->firstWhere('value', $item->priority?->value);
    $complexityInitialOption = collect($complexityOptions)->firstWhere('value', $item->complexity?->value);

    $statusInitialClass = $statusInitialOption
        ? 'bg-' . $statusInitialOption['color'] . '/10 text-' . $statusInitialOption['color']
        : 'bg-muted text-muted-foreground';
    $priorityInitialClass = $priorityInitialOption
        ? 'bg-' . $priorityInitialOption['color'] . '/10 text-' . $priorityInitialOption['color']
        : 'bg-muted text-muted-foreground';
    $complexityInitialClass = $complexityInitialOption
        ? 'bg-' . $complexityInitialOption['color'] . '/10 text-' . $complexityInitialOption['color']
        : 'bg-muted text-muted-foreground';

    $durationInitialLabel = '';
    if ($item->duration !== null) {
        $m = (int) $item->duration;
        if ($m < 60) {
            $durationInitialLabel = $m . ' ' . __('min');
        } else {
            $hours = (int) ceil($m / 60);
            $remainder = $m % 60;
            $hourWord = $hours === 1 ? __('hour') : \Illuminate\Support\Str::plural(__('hour'), 2);
            $durationInitialLabel = $hours . ' ' . $hourWord;
            if ($remainder) {
                $durationInitialLabel .= ' ' . $remainder . ' ' . __('min');
            }
        }
    }

    $startDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
    $endDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');

    $recurrenceInitial = [
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
    ];

    if ($item->recurringTask) {
        $rt = $item->recurringTask;
        $daysOfWeek = $rt->days_of_week ? (json_decode($rt->days_of_week, true) ?? []) : [];
        $recurrenceInitial = [
            'enabled' => true,
            'type' => $rt->recurrence_type?->value,
            'interval' => $rt->interval ?? 1,
            'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
        ];
    }

    $currentUserId = auth()->id();
    $currentUserIsOwner = $currentUserId && (int) $item->user_id === (int) $currentUserId;
    $hasCollaborators = ($item->collaborators ?? collect())->count() > 0;
    $isCollaboratedView = $hasCollaborators && ! $currentUserIsOwner;
    $canEdit = auth()->user()?->can('update', $item) ?? false;
    $canEditRecurrence = $currentUserIsOwner && $canEdit;
    $canEditDates = $currentUserIsOwner && $canEdit;
    $canEditTags = $currentUserIsOwner && $canEdit;
@endphp

<div
    wire:ignore
    @task-status-updated.window="if ($event.detail?.itemId == itemId) status = $event.detail.status"
    x-data="{
        itemId: @js($item->id),
        updatePropertyMethod: @js($updatePropertyMethod),
        listFilterDate: @js($listFilterDate),
        isRecurringTask: @js((bool) $item->recurringTask),
        status: @js($initialStatusValue),
        priority: @js($item->priority?->value),
        complexity: @js($item->complexity?->value),
        duration: @js($item->duration),
        startDatetime: @js($startDatetimeInitial),
        endDatetime: @js($endDatetimeInitial),
        recurrence: @js($recurrenceInitial),
        statusOptions: @js($statusOptions),
        priorityOptions: @js($priorityOptions),
        complexityOptions: @js($complexityOptions),
        durationOptions: @js($durationOptions),
        formData: { item: { tagIds: @js($item->tags->pluck('id')->values()->all()) } },
        tags: @js($availableTags),
        newTagName: '',
        creatingTag: false,
        deletingTagIds: new Set(),
        isTagSelected(tagId) {
            if (!this.formData?.item?.tagIds || !Array.isArray(this.formData.item.tagIds)) return false;
            const tagIdStr = String(tagId);
            return this.formData.item.tagIds.some(id => String(id) === tagIdStr);
        },
        async toggleTag(tagId) {
            if (!this.formData.item.tagIds) this.formData.item.tagIds = [];
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
            if (!tagName || this.creatingTag) return;
            this.newTagName = '';
            const tagNameLower = tagName.toLowerCase();
            const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
            if (existingTag && !String(existingTag.id).startsWith('temp-')) {
                if (!this.formData.item.tagIds) this.formData.item.tagIds = [];
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
                if (!this.tags) this.tags = [];
                this.tags.push({ id: tempId, name: tagName });
                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                if (!this.formData.item.tagIds.includes(tempId)) this.formData.item.tagIds.push(tempId);
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
            if (this.deletingTagIds?.has(tag.id)) return;
            const isTempTag = String(tag.id).startsWith('temp-');
            const snapshot = { ...tag };
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.formData.item.tagIds];
            const tagIndex = this.tags?.findIndex(t => String(t.id) === String(tag.id)) ?? -1;
            try {
                this.deletingTagIds = this.deletingTagIds || new Set();
                this.deletingTagIds.add(tag.id);
                if (this.tags && tagIndex !== -1) {
                    this.tags = this.tags.filter(t => String(t.id) !== String(tag.id));
                }
                const selectedIndex = Array.isArray(this.formData.item.tagIds)
                    ? this.formData.item.tagIds.findIndex(id => String(id) === String(tag.id))
                    : -1;
                if (selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                }
                if (!isTempTag) {
                    await $wire.$parent.$call('deleteTag', tag.id, true);
                }
                const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
                await this.updateProperty('tagIds', realTagIds, true);
                if (!isTempTag && tag.name && this.itemTitle) {
                    const msg = this.tagMessages.tagRemovedFromItem
                        .replace(':tag', tag.name)
                        .replace(':type', this.itemTypeLabel)
                        .replace(':item', this.itemTitle);
                    $wire.$dispatch('toast', { type: 'success', message: msg });
                }
            } catch (err) {
                if (tagIndex !== -1 && this.tags) {
                    this.tags.splice(tagIndex, 0, snapshot);
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }
                const wasSelected = tagIdsBackup.some(id => String(id) === String(tag.id));
                const isCurrentlySelected = Array.isArray(this.formData.item.tagIds)
                    ? this.formData.item.tagIds.some(id => String(id) === String(tag.id))
                    : false;
                if (wasSelected && !isCurrentlySelected) {
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
                if (tempTagIndex !== -1) this.tags[tempTagIndex] = { id, name };
                if (this.formData?.item?.tagIds) {
                    const tempIdIndex = this.formData.item.tagIds.indexOf(tempId);
                    if (tempIdIndex !== -1) this.formData.item.tagIds[tempIdIndex] = id;
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
                const tagIndex = this.tags.findIndex(tag => String(tag.id) === String(id));
                if (tagIndex !== -1) {
                    this.tags.splice(tagIndex, 1);
                }
            }
            if (this.formData?.item?.tagIds) {
                const selectedIndex = this.formData.item.tagIds.findIndex(tagId => String(tagId) === String(id));
                if (selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                    const realTagIds = this.formData.item.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                    this.updateProperty('tagIds', realTagIds);
                }
            }
        },
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        tagMessages: {
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
            tagRemovedFromItem: @js(__('Tag ":tag" removed from :type ":item".')),
        },
        itemTitle: @js($item->title ?? ''),
        itemTypeLabel: @js(__('Task')),
        editDateRangeError: null,
        datePickerOriginals: {},
        dateRangeMessages: {
            taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
            taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
        },
        validateEditDateRange(startVal, endVal, durationMinutes) {
            this.editDateRangeError = null;
            if (!startVal || !endVal) return true;
            const startDate = new Date(startVal);
            const endDate = new Date(endVal);
            if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return true;
            if (endDate.getTime() < startDate.getTime()) {
                this.editDateRangeError = this.dateRangeMessages.taskEndBeforeStart;
                return false;
            }
            const isSameDay = startDate.toDateString() === endDate.toDateString();
            if (isSameDay && Number.isFinite(durationMinutes) && durationMinutes > 0) {
                const minimumEnd = new Date(startDate.getTime() + (durationMinutes * 60 * 1000));
                if (endDate.getTime() < minimumEnd.getTime()) {
                    this.editDateRangeError = this.dateRangeMessages.taskEndTooSoon.replace(':minutes', String(durationMinutes));
                    return false;
                }
            }
            return true;
        },
        getOption(options, value) {
            return options.find(o => o.value === value);
        },
        durationLabels: { min: @js(__('min')), hour: @js(__('hour')), hours: @js(\Illuminate\Support\Str::plural(__('hour'), 2)) },
        formatDurationLabel(minutes) {
            if (minutes == null) return '';
            const m = Number(minutes);
            if (m < 59) return m + ' ' + this.durationLabels.min;
            const hours = Math.ceil(m / 60);
            const remainder = m % 60;
            const hourWord = hours === 1 ? this.durationLabels.hour : this.durationLabels.hours;
            let s = hours + ' ' + hourWord;
            if (remainder) s += ' ' + remainder + ' ' + this.durationLabels.min;
            return s;
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
                priority: this.priority,
                complexity: this.complexity,
                duration: this.duration,
                startDatetime: this.startDatetime,
                endDatetime: this.endDatetime,
                recurrence: JSON.parse(JSON.stringify(this.recurrence)),
            };
            try {
                if (property === 'status') this.status = value;
                else if (property === 'priority') this.priority = value;
                else if (property === 'complexity') this.complexity = value;
                else if (property === 'duration') this.duration = value;
                else if (property === 'startDatetime') this.startDatetime = value;
                else if (property === 'endDatetime') this.endDatetime = value;
                else if (property === 'recurrence') this.recurrence = value;

                $dispatch('item-property-updated', { property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });

                const occurrenceDate = (property === 'status' && this.isRecurringTask && this.listFilterDate) ? this.listFilterDate : null;
                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, false, occurrenceDate);
                if (!ok) {
                    this.status = snapshot.status;
                    this.priority = snapshot.priority;
                    this.complexity = snapshot.complexity;
                    this.duration = snapshot.duration;
                    this.startDatetime = snapshot.startDatetime;
                    this.endDatetime = snapshot.endDatetime;
                    this.recurrence = snapshot.recurrence;
                    $dispatch('item-update-rollback');
                    $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                    return false;
                }
                if (property === 'duration') {
                    $dispatch('task-duration-updated', { itemId: this.itemId, durationMinutes: value });
                }
                return true;
            } catch (err) {
                this.status = snapshot.status;
                this.priority = snapshot.priority;
                this.complexity = snapshot.complexity;
                this.duration = snapshot.duration;
                this.startDatetime = snapshot.startDatetime;
                this.endDatetime = snapshot.endDatetime;
                this.recurrence = snapshot.recurrence;
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
            const durationMinutes = parseInt(this.duration ?? '0', 10);
            this.validateEditDateRange(startVal, endVal, durationMinutes);
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
            const durationMinutes = parseInt(this.duration ?? '0', 10);
            const isValid = this.validateEditDateRange(startVal, endVal, durationMinutes);
            if (!isValid) {
                this.dispatchDatePickerRevert(e.target, path);
                this.editDateRangeError = null;
                return;
            }
            this.editDateRangeError = null;
            if (path === 'startDatetime' || path === 'endDatetime') {
                window.dispatchEvent(new CustomEvent('task-date-update-started', {
                    detail: { taskId: this.itemId, startDatetime: startVal, endDatetime: endVal },
                    bubbles: true,
                }));
                $dispatch('task-date-updated', { startDatetime: startVal, endDatetime: endVal });
            }
            const ok = await this.updateProperty(path, value);
            if (!ok) {
                const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                this.dispatchDatePickerRevert(e.target, path, realValue);
                if (path === 'startDatetime' || path === 'endDatetime') {
                    window.dispatchEvent(new CustomEvent('task-date-update-failed', {
                        detail: { taskId: this.itemId },
                        bubbles: true,
                    }));
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
        @if($canEdit)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $statusInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Status') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : (status || '')">{{ $statusInitialOption ? $statusInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($statusOptions as $opt)
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
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10 {{ $statusInitialClass }}">
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Status') }}:
                    </span>
                    <span class="uppercase">
                        {{ $statusInitialOption ? $statusInitialOption['label'] : '' }}
                    </span>
                </span>
            </span>
        @endif
    @endif

    @if($item->priority)
        @if($canEdit)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $priorityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(priorityOptions, priority) ? 'bg-' + getOption(priorityOptions, priority).color + '/10 text-' + getOption(priorityOptions, priority).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="bolt" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Priority') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : (priority || '')">{{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($priorityOptions as $opt)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': priority === '{{ $opt['value'] }}' }"
                            @click="updateProperty('priority', '{{ $opt['value'] }}')"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10 {{ $priorityInitialClass }}">
                <flux:icon name="bolt" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Priority') }}:
                    </span>
                    <span class="uppercase">
                        {{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}
                    </span>
                </span>
            </span>
        @endif
    @endif

    @if($item->complexity)
        @if($canEdit)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $complexityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(complexityOptions, complexity) ? 'bg-' + getOption(complexityOptions, complexity).color + '/10 text-' + getOption(complexityOptions, complexity).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="squares-2x2" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Complexity') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : (complexity || '')">{{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($complexityOptions as $opt)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': complexity === '{{ $opt['value'] }}' }"
                            @click="updateProperty('complexity', '{{ $opt['value'] }}')"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10 {{ $complexityInitialClass }}">
                <flux:icon name="squares-2x2" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Complexity') }}:
                    </span>
                    <span class="uppercase">
                        {{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}
                    </span>
                </span>
            </span>
        @endif
    @endif

    @if(! is_null($item->duration))
        @if($canEdit)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
                        :class="{ 'shadow-md scale-[1.02]': open }"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="clock" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Duration') }}:
                            </span>
                            <span class="uppercase" x-text="formatDurationLabel(duration)">{{ $durationInitialLabel }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($durationOptions as $dur)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': duration == {{ $dur['value'] }} }"
                            @click="updateProperty('duration', {{ $dur['value'] }})"
                        >
                            {{ $dur['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Duration') }}:
                    </span>
                    <span class="uppercase">
                        {{ $durationInitialLabel }}
                    </span>
                </span>
            </span>
        @endif
    @endif

    <x-date-picker
        model="startDatetime"
        type="datetime-local"
        :triggerLabel="__('Start')"
        :label="__('Start Date')"
        position="top"
        align="end"
        :initial-value="$startDatetimeInitial"
        :readonly="!$canEditDates"
        data-task-creation-safe
    />

    <x-date-picker
        model="endDatetime"
        type="datetime-local"
        :triggerLabel="__('Due')"
        :label="__('End Date')"
        position="top"
        align="end"
        :initial-value="$endDatetimeInitial"
        :readonly="!$canEditDates"
        data-task-creation-safe
    />

    <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
        <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
        <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
    </div>

    @php
        $hideTagsSection = $isCollaboratedView && $item->tags->isEmpty();
    @endphp

    @unless($hideTagsSection)
        <div class="w-full basis-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]">
            @if($item->tags->isNotEmpty())
                <span class="inline-flex shrink-0 items-center gap-1 font-semibold uppercase tracking-wide text-muted-foreground">
                    <flux:icon name="tag" class="size-3" />
                    {{ __('Tags') }}:
                </span>
            @endif
            <div
                @tag-toggled="toggleTag($event.detail.tagId)"
                @tag-create-request="createTagOptimistic($event.detail.tagName)"
                @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
            >
                <x-workspace.tag-selection position="top" align="end" :selected-tags="$item->tags" :readonly="!$canEditTags" />
            </div>
        </div>
    @endunless
</div>

@if($item->project)
    <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10">
        <flux:icon name="folder" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Project') }}:
            </span>
            <span class="truncate max-w-[120px] uppercase">{{ $item->project->name }}</span>
        </span>
    </span>
@endif

@if($item->event)
    <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-purple-500/10 px-2.5 py-0.5 font-medium text-purple-500 dark:border-white/10">
        <flux:icon name="calendar" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Event') }}:
            </span>
            <span class="truncate max-w-[120px] uppercase">{{ $item->event->title }}</span>
        </span>
    </span>
@endif

