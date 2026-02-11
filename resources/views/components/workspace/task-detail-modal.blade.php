@props([
    'availableTags' => [],
    'projectNames' => [],
    'listFilterDate' => null,
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
@endphp

<div
    x-data="{
        open: false,
        itemId: null,
        title: '',
        description: '',
        status: 'to_do',
        priority: 'medium',
        complexity: 'moderate',
        duration: 60,
        startDatetime: null,
        endDatetime: null,
        tagIds: [],
        projectId: null,
        eventId: null,
        isOverdue: false,
        recurrence: {
            enabled: false,
            type: null,
            interval: 1,
            daysOfWeek: [],
        },
        isRecurringTask: false,
        listFilterDate: @js($listFilterDate),
        statusOptions: @js($statusOptions),
        priorityOptions: @js($priorityOptions),
        complexityOptions: @js($complexityOptions),
        durationOptions: @js($durationOptions),
        tags: @js($availableTags),
        projectNames: @js($projectNames),
        newTagName: '',
        creatingTag: false,
        deletingTagIds: new Set(),
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        tagMessages: {
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
            tagRemovedFromItem: @js(__('Tag \":tag\" removed from task \":item\".')),
        },
        editDateRangeError: null,
        datePickerOriginals: {},
        dateRangeMessages: {
            taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
            taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
        },
        durationLabels: {
            min: @js(__('min')),
            hour: @js(__('hour')),
            hours: @js(\Illuminate\Support\Str::plural(__('hour'), 2))
        },
        openModal(detail) {
            this.itemId = detail.id;
            this.title = detail.title || '';
            this.description = detail.description || '';
            this.status = detail.status || 'to_do';
            this.priority = detail.priority || 'medium';
            this.complexity = detail.complexity || 'moderate';
            this.duration = detail.duration ?? 60;
            this.startDatetime = detail.startDatetime || null;
            this.endDatetime = detail.endDatetime || null;
            this.tagIds = Array.isArray(detail.tagIds) ? [...detail.tagIds] : [];
            this.projectId = detail.projectId || null;
            this.eventId = detail.eventId || null;
            this.isOverdue = detail.isOverdue || false;
            this.recurrence = detail.recurrence || {
                enabled: false,
                type: null,
                interval: 1,
                daysOfWeek: [],
            };
            this.isRecurringTask = this.recurrence.enabled;
            this.editDateRangeError = null;
            this.datePickerOriginals = {};
            this.open = true;
            $flux.modal('task-detail').show();
        },
        closeModal() {
            this.open = false;
            $flux.modal('task-detail').close();
        },
        getOption(options, value) {
            return options.find(o => o.value === value);
        },
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
        isTagSelected(tagId) {
            if (!this.tagIds || !Array.isArray(this.tagIds)) return false;
            const tagIdStr = String(tagId);
            return this.tagIds.some(id => String(id) === tagIdStr);
        },
        async toggleTag(tagId) {
            if (!this.tagIds) this.tagIds = [];
            const tagIdsBackup = [...this.tagIds];
            const tagIdStr = String(tagId);
            const index = this.tagIds.findIndex(id => String(id) === tagIdStr);
            if (index === -1) {
                this.tagIds.push(tagId);
            } else {
                this.tagIds.splice(index, 1);
            }
            const realTagIds = this.tagIds.filter(id => !String(id).startsWith('temp-'));
            const ok = await this.updateProperty('tagIds', realTagIds);
            if (!ok) {
                this.tagIds = tagIdsBackup;
            }
        },
        async createTagOptimistic(tagNameFromEvent) {
            const tagName = (tagNameFromEvent != null && tagNameFromEvent !== '' ? String(tagNameFromEvent).trim() : (this.newTagName || '').trim());
            if (!tagName || this.creatingTag) return;
            this.newTagName = '';
            const tagNameLower = tagName.toLowerCase();
            const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
            if (existingTag && !String(existingTag.id).startsWith('temp-')) {
                if (!this.tagIds) this.tagIds = [];
                const alreadySelected = this.tagIds.some(id => String(id) === String(existingTag.id));
                if (!alreadySelected) {
                    this.tagIds.push(existingTag.id);
                    const realTagIds = this.tagIds.filter(id => !String(id).startsWith('temp-'));
                    await this.updateProperty('tagIds', realTagIds);
                }
                $wire.$dispatch('toast', { type: 'info', message: this.tagMessages.tagAlreadyExists });
                return;
            }
            const tempId = 'temp-' + Date.now();
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.tagIds];
            const newTagNameBackup = tagName;
            try {
                if (!this.tags) this.tags = [];
                this.tags.push({ id: tempId, name: tagName });
                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                if (!this.tagIds.includes(tempId)) this.tagIds.push(tempId);
                this.creatingTag = true;
                await $wire.$parent.$call('createTag', tagName, true);
            } catch (err) {
                this.tags = tagsBackup;
                this.tagIds = tagIdsBackup;
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
            const tagIdsBackup = [...this.tagIds];
            const tagIndex = this.tags?.findIndex(t => String(t.id) === String(tag.id)) ?? -1;
            try {
                this.deletingTagIds = this.deletingTagIds || new Set();
                this.deletingTagIds.add(tag.id);
                if (this.tags && tagIndex !== -1) this.tags = this.tags.filter(t => String(t.id) !== String(tag.id));
                const selectedIndex = Array.isArray(this.tagIds)
                    ? this.tagIds.findIndex(id => String(id) === String(tag.id))
                    : -1;
                if (selectedIndex !== -1) this.tagIds.splice(selectedIndex, 1);
                if (!isTempTag) {
                    await $wire.$parent.$call('deleteTag', tag.id, true);
                }
                const realTagIds = this.tagIds.filter(id => !String(id).startsWith('temp-'));
                await this.updateProperty('tagIds', realTagIds, true);
                if (!isTempTag && tag.name && this.title) {
                    const msg = this.tagMessages.tagRemovedFromItem
                        .replace(':tag', tag.name)
                        .replace(':item', this.title);
                    $wire.$dispatch('toast', { type: 'success', message: msg });
                }
            } catch (err) {
                if (tagIndex !== -1 && this.tags) {
                    this.tags.splice(tagIndex, 0, snapshot);
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }
                const wasSelected = tagIdsBackup.some(id => String(id) === String(tag.id));
                const isCurrentlySelected = Array.isArray(this.tagIds)
                    ? this.tagIds.some(id => String(id) === String(tag.id))
                    : false;
                if (wasSelected && !isCurrentlySelected) {
                    this.tagIds.push(tag.id);
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
                if (this.tagIds) {
                    const tempIdIndex = this.tagIds.indexOf(tempId);
                    if (tempIdIndex !== -1) this.tagIds[tempIdIndex] = id;
                }
                this.tags = this.tags.filter((tag, idx, arr) => arr.findIndex(t => String(t.id) === String(tag.id)) === idx);
                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                const realTagIds = this.tagIds.filter(tid => !String(tid).startsWith('temp-'));
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
            if (this.tagIds) {
                const selectedIndex = this.tagIds.indexOf(id);
                if (selectedIndex !== -1) {
                    this.tagIds.splice(selectedIndex, 1);
                    const realTagIds = this.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                    this.updateProperty('tagIds', realTagIds);
                }
            }
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
        async updateProperty(property, value, silentSuccessToast = false) {
            if (property === 'tagIds') {
                try {
                    const ok = await $wire.$parent.$call('updateTaskProperty', this.itemId, property, value, silentSuccessToast);
                    if (!ok) {
                        $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                        return false;
                    }
                    $dispatch('item-property-updated', { itemId: this.itemId, kind: 'task', property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });
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
                const occurrenceDate = (property === 'status' && this.isRecurringTask && this.listFilterDate) ? this.listFilterDate : null;
                const ok = await $wire.$parent.$call('updateTaskProperty', this.itemId, property, value, false, occurrenceDate);
                if (!ok) {
                    this.status = snapshot.status;
                    this.priority = snapshot.priority;
                    this.complexity = snapshot.complexity;
                    this.duration = snapshot.duration;
                    this.startDatetime = snapshot.startDatetime;
                    this.endDatetime = snapshot.endDatetime;
                    this.recurrence = snapshot.recurrence;
                    $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                    return false;
                }
                $dispatch('item-property-updated', { itemId: this.itemId, kind: 'task', property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });
                return true;
            } catch (err) {
                this.status = snapshot.status;
                this.priority = snapshot.priority;
                this.complexity = snapshot.complexity;
                this.duration = snapshot.duration;
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
            const ok = await this.updateProperty(path, value);
            if (!ok) {
                const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                this.dispatchDatePickerRevert(e.target, path, realValue);
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
    @task-detail-open.window="openModal($event.detail.item)"
    @tag-created.window="onTagCreated($event)"
    @tag-deleted.window="onTagDeleted($event)"
    @date-picker-opened="handleDatePickerOpened($event)"
    @date-picker-value-changed="handleDatePickerValueChanged($event)"
    @date-picker-updated="handleDatePickerUpdated($event)"
    @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
>
    <flux:modal name="task-detail" variant="flyout" class="w-full max-w-2xl">
        <div class="flex flex-col gap-6 p-6">
            {{-- Header --}}
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <flux:heading size="lg" x-text="title"></flux:heading>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span x-show="isOverdue" class="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                            <flux:icon name="exclamation-triangle" class="size-3.5" />
                            {{ __('Overdue') }}
                        </span>
                        <span x-show="projectId" class="inline-flex items-center gap-1">
                            <flux:icon name="folder" class="size-3.5" />
                            <span x-text="projectNames[projectId] || ''"></span>
                        </span>
                    </div>
                </div>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="x-mark"
                    @click="closeModal()"
                />
            </div>

            {{-- Description --}}
            <div x-show="description" class="text-sm text-muted-foreground" x-text="description"></div>

            {{-- Properties Grid --}}
            <div class="grid grid-cols-2 gap-4">
                {{-- Status --}}
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Status') }}</flux:text>
                    <x-simple-select-dropdown>
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex w-full items-center gap-1.5 rounded-md border border-border/60 px-3 py-2 text-sm font-medium transition-colors hover:bg-muted/50"
                                :class="getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground'"
                                aria-haspopup="menu"
                            >
                                <flux:icon name="check-circle" class="size-4" />
                                <span x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : status"></span>
                                <flux:icon name="chevron-down" class="ml-auto size-4" />
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
                </div>

                {{-- Priority --}}
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Priority') }}</flux:text>
                    <x-simple-select-dropdown>
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex w-full items-center gap-1.5 rounded-md border border-border/60 px-3 py-2 text-sm font-medium transition-colors hover:bg-muted/50"
                                :class="getOption(priorityOptions, priority) ? 'bg-' + getOption(priorityOptions, priority).color + '/10 text-' + getOption(priorityOptions, priority).color : 'bg-muted text-muted-foreground'"
                                aria-haspopup="menu"
                            >
                                <flux:icon name="bolt" class="size-4" />
                                <span x-text="getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : priority"></span>
                                <flux:icon name="chevron-down" class="ml-auto size-4" />
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
                </div>

                {{-- Complexity --}}
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Complexity') }}</flux:text>
                    <x-simple-select-dropdown>
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex w-full items-center gap-1.5 rounded-md border border-border/60 px-3 py-2 text-sm font-medium transition-colors hover:bg-muted/50"
                                :class="getOption(complexityOptions, complexity) ? 'bg-' + getOption(complexityOptions, complexity).color + '/10 text-' + getOption(complexityOptions, complexity).color : 'bg-muted text-muted-foreground'"
                                aria-haspopup="menu"
                            >
                                <flux:icon name="squares-2x2" class="size-4" />
                                <span x-text="getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : complexity"></span>
                                <flux:icon name="chevron-down" class="ml-auto size-4" />
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
                </div>

                {{-- Duration --}}
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Duration') }}</flux:text>
                    <x-simple-select-dropdown>
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex w-full items-center gap-1.5 rounded-md border border-border/60 bg-muted px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted/80"
                                aria-haspopup="menu"
                            >
                                <flux:icon name="clock" class="size-4" />
                                <span x-text="formatDurationLabel(duration)"></span>
                                <flux:icon name="chevron-down" class="ml-auto size-4" />
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
                </div>
            </div>

            {{-- Recurrence --}}
            <div class="space-y-2">
                <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Recurrence') }}</flux:text>
                <x-recurring-selection
                    model="recurrence"
                    :initial-value="[]"
                    kind="task"
                />
            </div>

            {{-- Dates --}}
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Start Date') }}</flux:text>
                    <x-date-picker
                        model="startDatetime"
                        type="datetime-local"
                        :triggerLabel="__('Start')"
                        :label="__('Start Date')"
                        :initial-value="null"
                        data-task-creation-safe
                    />
                </div>

                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Due Date') }}</flux:text>
                    <x-date-picker
                        model="endDatetime"
                        type="datetime-local"
                        :triggerLabel="__('Due')"
                        :label="__('End Date')"
                        :initial-value="null"
                        data-task-creation-safe
                    />
                </div>
            </div>

            <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
                <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
                <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
            </div>

            {{-- Tags --}}
            <div class="space-y-2">
                <flux:text size="sm" class="font-medium text-muted-foreground">{{ __('Tags') }}</flux:text>
                <div
                    @tag-toggled="toggleTag($event.detail.tagId)"
                    @tag-create-request="createTagOptimistic($event.detail.tagName)"
                    @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
                >
                    <x-workspace.tag-selection :selected-tags="[]" />
                </div>
            </div>
        </div>
    </flux:modal>
</div>
