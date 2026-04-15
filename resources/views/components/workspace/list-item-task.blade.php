@props([
    'item',
    'availableTags' => [],
    'updatePropertyMethod',
    'listFilterDate' => null,
    'initialStatus' => null,
    'isOverdue' => false,
    'showOverdueVisual' => null,
    'layout' => 'list',
    'embedInFocusModal' => false,
    'showFocusTrigger' => true,
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
        ['value' => 480, 'label' => '8 hours'],
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

    $durationInitialLabel = $item->duration === null ? __('Not set') : '';
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

    $subjectDisplay = trim((string) ($item->subject_name ?? ''));
    $teacherDisplay = trim((string) ($item->teacher_name ?? ''));
    $showCourseContextPill = $subjectDisplay !== '' || $teacherDisplay !== '';
    $hasTaskTags = $item->tags->isNotEmpty();
    $hasProjectParent = (bool) ($item->project_id && $item->project);
    $hasEventParent = (bool) ($item->event_id && $item->event);
    $courseContextTooltip = collect([$subjectDisplay, $teacherDisplay])
        ->filter(fn (string $v): bool => $v !== '')
        ->implode(' · ');
    $courseContextPillCompactLine = \App\Support\CourseContextPillFormatter::compactLine($subjectDisplay, $teacherDisplay)
        ?? '';

    // Server-render the initial task-progress bar state to avoid Alpine init flicker.
    // Alpine takes over after hydration via `taskFocus*` getters.
    $taskDurationSeconds = (int) (($item->duration ?? 0) * 60);
    $hasTaskDurationTarget = $taskDurationSeconds > 0;
    $taskFocusedSeconds = 0;
    if ($hasTaskDurationTarget) {
        $taskFocusedSeconds = $item->calculateFocusedWorkSecondsExcludingActive(now());
    }
    $formatCountdown = function (int $seconds): string {
        $s = max(0, (int) $seconds);
        $h = (int) floor($s / 3600);
        $m = (int) floor(($s % 3600) / 60);
        $sec = (int) ($s % 60);

        if ($h > 0) {
            return $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT);
        }

        return str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $sec, 2, '0', STR_PAD_LEFT);
    };
    $initialTaskProgressPercent = $hasTaskDurationTarget && $taskDurationSeconds > 0
        ? (int) round(min(100, max(0, ($taskFocusedSeconds / $taskDurationSeconds) * 100)))
        : 0;
    $initialTaskRemainingText = $hasTaskDurationTarget
        ? $formatCountdown(max(0, $taskDurationSeconds - $taskFocusedSeconds))
        : '';

    // Match task progress: hide Focus when done on first paint; Alpine `status` + x-show keeps updates in sync.
    $hideFocusButtonInitiallyDone = ($initialStatusValue ?? '') === 'done';

    $useKanbanCompact = ($layout ?? 'list') === 'kanban' && ! ($embedInFocusModal ?? false);
    $taskDropdownAlign = $useKanbanCompact ? 'start' : 'end';
@endphp

<div
    wire:ignore
    @task-status-updated.window="if ($event.detail?.itemId == itemId) status = $event.detail.status"
    @workspace-item-property-updated.window="if ($event.detail?.kind === 'task' && Number($event.detail.itemId) === Number(itemId)) applyWorkspaceItemPropertyUpdate($event.detail)"
    x-data="{
        embedInFocusModal: @js($embedInFocusModal),
        listItemCard: null,
        taskProgressSectionShown: false,
        isModalFocusLocked() {
            return !!(
                this.embedInFocusModal
                && this.listItemCard
                && (this.listItemCard.isFocused || this.listItemCard.isBreakFocused)
            );
        },
        syncListItemCardScope() {
            const alpine = typeof window !== 'undefined' ? window.Alpine : null;
            const rootEl = this.$el.closest('.list-item-card');
            let card = null;
            if (rootEl && alpine && typeof alpine.$data === 'function') {
                try { card = alpine.$data(rootEl); } catch (e) {}
            }
            if (!card && rootEl && rootEl._x_dataStack && rootEl._x_dataStack.length) {
                card = rootEl._x_dataStack[rootEl._x_dataStack.length - 1];
            }
            if (!card && this.itemId != null && alpine?.store) {
                try {
                    const store = alpine.store('listItemCards');
                    if (store && typeof store === 'object' && store[this.itemId]) {
                        card = store[this.itemId];
                    }
                } catch (e) {}
            }
            this.listItemCard = card;
            this.taskProgressSectionShown = !!(card && card.shouldShowTaskProgress && String(this.status ?? '') === 'doing' && !this.embedInFocusModal && !card.isFocusModalOpen);
        },
        dispatchWindowEvent(name, detail) {
            if (typeof window === 'undefined') {
                return;
            }
            window.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
        },
        syncDatePickersFromTaskState() {
            if (!this.embedInFocusModal) {
                return;
            }
            this.dispatchWindowEvent('date-picker-value', {
                path: 'startDatetime',
                value: this.startDatetime,
                itemId: this.itemId,
            });
            this.dispatchWindowEvent('date-picker-value', {
                path: 'endDatetime',
                value: this.endDatetime,
                itemId: this.itemId,
            });
        },
        syncRecurringFromCardState() {
            if (!this.embedInFocusModal || !this.listItemCard) {
                return;
            }
            const c = this.listItemCard;
            if (c.kind !== 'task' && c.kind !== 'event') {
                return;
            }
            if (c.recurrence === undefined) {
                return;
            }
            this.dispatchWindowEvent('recurring-value', {
                path: 'recurrence',
                value: JSON.parse(JSON.stringify(c.recurrence)),
                itemId: this.itemId,
            });
        },
        syncTaskFieldsFromParentCard() {
            if (!this.embedInFocusModal || !this.listItemCard || this.listItemCard.kind !== 'task') {
                return;
            }
            const c = this.listItemCard;
            if (c.taskStatus !== undefined) {
                this.status = c.taskStatus;
            }
            this.priority = c.taskPriority ?? null;
            this.complexity = c.taskComplexity ?? null;
            this.duration = c.taskDurationMinutes != null ? c.taskDurationMinutes : null;
            if (c.taskStartDatetime !== undefined) {
                this.startDatetime = c.taskStartDatetime;
            }
            if (c.taskEndDatetime !== undefined) {
                this.endDatetime = c.taskEndDatetime;
            }
            if (c.recurrence !== undefined && c.recurrence !== null) {
                this.recurrence = JSON.parse(JSON.stringify(c.recurrence));
            }
            this.syncDatePickersFromTaskState();
            this.syncRecurringFromCardState();
        },
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
                if (!isTempTag && tag.name && this.itemTitle) {
                    const msg = this.tagMessages.tagRemovedFromItem
                        .replace(':tag', tag.name)
                        .replace(':type', this.itemTypeLabel)
                        .replace(':item', this.itemTitle);
                    $wire.$dispatch('toast', { type: 'success', message: msg });
                } else if (!isTempTag && tag.name) {
                    const msg = this.tagMessages.tagDeleted.replace(':tag', tag.name);
                    $wire.$dispatch('toast', { type: 'success', message: msg });
                }
            } catch (err) {
                this.tags = tagsBackup;
                this.formData.item.tagIds = tagIdsBackup;
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
                }
            }
        },
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        tagMessages: {
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
            tagRemovedFromItem: @js(__('Tag ":tag" removed from :type ":item".')),
            tagDeleted: @js(__('Tag ":tag" deleted.')),
        },
        itemTitle: @js($item->title ?? ''),
        itemTypeLabel: @js(__('Task')),
        priorityFieldLabel: @js(__('Priority')),
        complexityFieldLabel: @js(__('Complexity')),
        durationFieldLabel: @js(__('Duration')),
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
        durationLabels: { min: @js(__('min')), hour: @js(__('hour')), hours: @js(\Illuminate\Support\Str::plural(__('hour'), 2)), notSet: @js(__('Not set')) },
        formatDurationLabel(minutes) {
            if (minutes == null) return this.durationLabels.notSet;
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
                if (this.itemId != null) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-item-property-updated', {
                            detail: {
                                kind: 'task',
                                itemId: this.itemId,
                                property,
                                value,
                                startDatetime: this.startDatetime,
                                endDatetime: this.endDatetime,
                            },
                            bubbles: true,
                        }),
                    );
                }

                if (property === 'status') {
                    const opt = this.getOption(this.statusOptions, value);
                    $dispatch('task-status-updated', {
                        itemId: this.itemId,
                        status: value,
                        statusLabel: opt?.label ?? '',
                        statusClass: opt ? 'bg-' + opt.color + '/10 text-' + opt.color : 'bg-muted text-muted-foreground',
                    });
                }

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
                    if (property === 'status') {
                        const rollbackOpt = this.getOption(this.statusOptions, snapshot.status);
                        $dispatch('task-status-updated', {
                            itemId: this.itemId,
                            status: snapshot.status,
                            statusLabel: rollbackOpt?.label ?? '',
                            statusClass: rollbackOpt ? 'bg-' + rollbackOpt.color + '/10 text-' + rollbackOpt.color : 'bg-muted text-muted-foreground',
                        });
                    }
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
                if (property === 'status') {
                    const rollbackOpt = this.getOption(this.statusOptions, snapshot.status);
                    $dispatch('task-status-updated', {
                        itemId: this.itemId,
                        status: snapshot.status,
                        statusLabel: rollbackOpt?.label ?? '',
                        statusClass: rollbackOpt ? 'bg-' + rollbackOpt.color + '/10 text-' + rollbackOpt.color : 'bg-muted text-muted-foreground',
                    });
                }
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
        applyWorkspaceItemPropertyUpdate(detail) {
            if (!detail || !detail.property) return;
            const property = detail.property;
            const value = detail.value;
            if (property === 'status') {
                if (value != null) {
                    this.status = value;
                }
                return;
            }
            if (property === 'priority') this.priority = value;
            else if (property === 'complexity') this.complexity = value;
            else if (property === 'duration') this.duration = value;
            else if (property === 'startDatetime') this.startDatetime = detail.startDatetime ?? value;
            else if (property === 'endDatetime') this.endDatetime = detail.endDatetime ?? value;
            else if (property === 'recurrence') this.recurrence = value;
            else if (property === 'tagIds') {
                if (!this.formData || !this.formData.item) {
                    this.formData = { item: { tagIds: [] } };
                }
                this.formData.item.tagIds = Array.isArray(value) ? [...value] : [];
            }
        },
    }"
    x-init="$nextTick(() => { if (embedInFocusModal) { syncListItemCardScope(); syncTaskFieldsFromParentCard(); } })"
    x-effect="syncListItemCardScope(); if (embedInFocusModal && listItemCard) { syncTaskFieldsFromParentCard(); }"
    @class([
        'contents' => ! $useKanbanCompact,
        'flex w-full min-w-0 flex-col gap-2' => $useKanbanCompact,
    ])
    @date-picker-opened="handleDatePickerOpened($event)"
    @date-picker-value-changed="handleDatePickerValueChanged($event)"
    @date-picker-updated="handleDatePickerUpdated($event)"
    @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
    @tag-created.window="onTagCreated($event)"
    @tag-deleted.window="onTagDeleted($event)"
>
    @if($useKanbanCompact)
        <div class="flex w-full min-w-0 flex-wrap items-center gap-2">
            <x-date-picker
                model="startDatetime"
                type="datetime-local"
                :triggerLabel="__('Start')"
                :label="__('Start Date')"
                position="top"
                align="start"
                :initial-value="$startDatetimeInitial"
                :item-id="$item->id"
                :readonly="!$canEditDates"
                compact
                data-task-creation-safe
            />
            <x-date-picker
                model="endDatetime"
                type="datetime-local"
                :triggerLabel="__('Due')"
                :label="__('End Date')"
                position="top"
                align="start"
                :initial-value="$endDatetimeInitial"
                :overdue="$showOverdueVisual ?? $isOverdue"
                :item-id="$item->id"
                :readonly="!$canEditDates"
                compact
                data-task-creation-safe
            />
            <div class="flex w-full basis-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
                <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600" />
                <p class="text-xs font-medium text-red-600" x-text="editDateRangeError"></p>
            </div>
        </div>
        <div class="flex w-full min-w-0 flex-wrap items-center gap-2">
            @if($item->priority)
                @if($canEdit)
                    <x-simple-select-dropdown position="top" align="start">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $priorityInitialClass }}"
                                x-effect="$el.className = 'inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(priorityOptions, priority) ? 'bg-' + getOption(priorityOptions, priority).color + '/10 text-' + getOption(priorityOptions, priority).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                                aria-haspopup="menu"
                                x-bind:aria-label="priorityFieldLabel + ': ' + (getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : (priority || ''))"
                            >
                                <flux:icon name="bolt" class="size-3 shrink-0" />
                                <span class="max-w-[5.5rem] truncate text-[11px] font-semibold uppercase" x-text="getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : (priority || '')">{{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}</span>
                                <flux:icon name="chevron-down" class="size-3 shrink-0 focus-hide-chevron" />
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
                    <span
                        class="inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold {{ $priorityInitialClass }}"
                        title="{{ __('Priority') }}: {{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}"
                    >
                        <flux:icon name="bolt" class="size-3 shrink-0" />
                        <span class="max-w-[5.5rem] truncate text-[11px] font-semibold uppercase">
                            {{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}
                        </span>
                    </span>
                @endif
            @endif

            @if($item->complexity)
                @if($canEdit)
                    <x-simple-select-dropdown position="top" align="start">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $complexityInitialClass }}"
                                x-effect="$el.className = 'inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(complexityOptions, complexity) ? 'bg-' + getOption(complexityOptions, complexity).color + '/10 text-' + getOption(complexityOptions, complexity).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                                aria-haspopup="menu"
                                x-bind:aria-label="complexityFieldLabel + ': ' + (getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : (complexity || ''))"
                            >
                                <flux:icon name="squares-2x2" class="size-3 shrink-0" />
                                <span class="max-w-[6rem] truncate text-[11px] font-semibold uppercase" x-text="getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : (complexity || '')">{{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}</span>
                                <flux:icon name="chevron-down" class="size-3 shrink-0 focus-hide-chevron" />
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
                    <span
                        class="inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-1 font-semibold {{ $complexityInitialClass }}"
                        title="{{ __('Complexity') }}: {{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}"
                    >
                        <flux:icon name="squares-2x2" class="size-3 shrink-0" />
                        <span class="max-w-[6rem] truncate text-[11px] font-semibold uppercase">
                            {{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}
                        </span>
                    </span>
                @endif
            @endif

            @if($canEdit)
                <x-simple-select-dropdown position="top" align="start">
                    <x-slot:trigger>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border px-2 py-1 font-medium transition-[box-shadow,transform] duration-150 ease-out"
                            :class="[
                                duration == null ? 'border-border/40 bg-muted/50 text-muted-foreground' : 'border-border/60 bg-muted text-muted-foreground',
                                open ? 'shadow-md scale-[1.02]' : '',
                            ]"
                            aria-haspopup="menu"
                            x-bind:aria-label="durationFieldLabel + ': ' + formatDurationLabel(duration)"
                        >
                            <flux:icon name="clock" class="size-3 shrink-0" />
                            <span class="max-w-[6rem] truncate text-[11px] font-semibold uppercase" x-text="formatDurationLabel(duration)">{{ $durationInitialLabel }}</span>
                            <flux:icon name="chevron-down" class="size-3 shrink-0 focus-hide-chevron" />
                        </button>
                    </x-slot:trigger>

                    <div
                        class="flex flex-col py-1"
                        x-data="{
                            customDurationValue: '',
                            customDurationUnit: 'minutes',
                            maxDurationMinutes: @js(\App\Support\Validation\TaskPayloadValidation::MAX_DURATION_MINUTES),
                            applyCustomDuration() {
                                const n = parseInt(this.customDurationValue, 10);
                                if (!Number.isFinite(n) || n <= 0) return;
                                let minutes = this.customDurationUnit === 'hours' ? n * 60 : n;
                                const max = Number(this.maxDurationMinutes) || 1440;
                                if (minutes > max) {
                                    minutes = max;
                                }
                                updateProperty('duration', minutes);
                            },
                        }"
                    >
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': duration == null }"
                            @click="updateProperty('duration', null)"
                        >
                            {{ __('Not set') }}
                        </button>
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

                        <div class="mt-1 border-t border-border/60 pt-2 px-3 pb-1 text-xs text-muted-foreground">
                            <div class="mb-1 text-[11px] font-medium">
                                {{ __('Custom duration') }}
                            </div>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="1"
                                    step="1"
                                    x-model.number="customDurationValue"
                                    placeholder="30"
                                    @click.stop
                                    @blur="applyCustomDuration()"
                                    @keydown.enter.prevent.stop="applyCustomDuration()"
                                    class="h-8 w-16 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue"
                                />
                                <div class="inline-flex overflow-hidden rounded-full border border-zinc-200 bg-zinc-50 text-[11px] shadow-sm">
                                    <button
                                        type="button"
                                        class="px-2 py-1 transition-colors"
                                        :class="customDurationUnit === 'minutes'
                                            ? 'bg-brand-blue text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100'"
                                        @click.stop.prevent="customDurationUnit = 'minutes'; applyCustomDuration()"
                                    >
                                        {{ __('Min') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-1 transition-colors"
                                        :class="customDurationUnit === 'hours'
                                            ? 'bg-brand-blue text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100'"
                                        @click.stop.prevent="customDurationUnit = 'hours'; applyCustomDuration()"
                                    >
                                        {{ __('Hours') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-simple-select-dropdown>
            @else
                <span
                    class="inline-flex items-center gap-1 rounded-full border border-border/40 bg-muted/50 px-2 py-1 font-medium text-muted-foreground"
                    title="{{ __('Duration') }}: {{ $durationInitialLabel }}"
                >
                    <flux:icon name="clock" class="size-3 shrink-0" />
                    <span class="max-w-[6rem] truncate text-[11px] font-semibold uppercase">
                        {{ $durationInitialLabel }}
                    </span>
                </span>
            @endif

            @if($showCourseContextPill)
                <flux:tooltip content="{{ $courseContextTooltip }}" position="top" align="start">
                    <span
                        tabindex="0"
                        class="inline-flex max-w-[min(100%,12rem)] cursor-default items-center gap-1 rounded-full border border-border/60 bg-muted px-2 py-1 font-medium text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <flux:icon name="book-open" class="size-3 shrink-0" />
                        <span class="min-w-0 truncate text-[10px] font-semibold uppercase leading-tight">
                            {{ $courseContextPillCompactLine }}
                        </span>
                    </span>
                </flux:tooltip>
            @endif

        </div>
    @endif

    <div
        @class([
            'flex w-full flex-wrap items-center gap-2' => ! $useKanbanCompact,
        ])
        x-bind:aria-disabled="isModalFocusLocked()"
        :class="isModalFocusLocked() ? 'pointer-events-none select-none opacity-75' : ''"
    >
    @if($item->status && ! $useKanbanCompact)
        @if(($layout ?? 'list') === 'list' && $showFocusTrigger && $canEdit && ! ($embedInFocusModal ?? false))
            <div class="flex flex-wrap items-center gap-2">
                <div
                    x-show="status !== 'done' && (!listItemCard || (!listItemCard.isFocused && !listItemCard.isBreakFocused))"
                    @if($hideFocusButtonInitiallyDone) style="display: none;" @endif
                    class="shrink-0"
                >
                    <flux:tooltip :content="__('Start focus mode')">
                        <button
                            type="button"
                            x-ref="focusTrigger"
                            @click.stop="listItemCard && listItemCard.enterFocusReady()"
                            class="workspace-focus-trigger"
                        >
                            <flux:icon name="bolt" class="size-4 shrink-0" />
                            <span>{{ __('Focus') }}</span>
                        </button>
                    </flux:tooltip>
                </div>
                <x-simple-select-dropdown position="top" align="end">
                    <x-slot:trigger>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $statusInitialClass }}"
                            x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                            aria-haspopup="menu"
                        >
                            <flux:icon name="check-circle" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Status') }}:
                                </span>
                                <span class="uppercase" x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : (status || '')">{{ $statusInitialOption ? $statusInitialOption['label'] : '' }}</span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
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
        @elseif($canEdit)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $statusInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Status') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : (status || '')">{{ $statusInitialOption ? $statusInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
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
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold {{ $statusInitialClass }}">
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

    @if(! $useKanbanCompact)
    @if($item->priority)
        @if($canEdit)
            <x-simple-select-dropdown position="top" align="{{ $taskDropdownAlign }}">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $priorityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(priorityOptions, priority) ? 'bg-' + getOption(priorityOptions, priority).color + '/10 text-' + getOption(priorityOptions, priority).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="bolt" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Priority') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : (priority || '')">{{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
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
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold {{ $priorityInitialClass }}">
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
            <x-simple-select-dropdown position="top" align="{{ $taskDropdownAlign }}">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out {{ $complexityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out ' + (getOption(complexityOptions, complexity) ? 'bg-' + getOption(complexityOptions, complexity).color + '/10 text-' + getOption(complexityOptions, complexity).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="squares-2x2" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Complexity') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : (complexity || '')">{{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
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
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold {{ $complexityInitialClass }}">
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

    @if($canEdit)
        <x-simple-select-dropdown position="top" align="{{ $taskDropdownAlign }}">
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
                    <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
                </button>
            </x-slot:trigger>

            <div
                class="flex flex-col py-1"
                x-data="{
                    customDurationValue: '',
                    customDurationUnit: 'minutes',
                    maxDurationMinutes: @js(\App\Support\Validation\TaskPayloadValidation::MAX_DURATION_MINUTES),
                    applyCustomDuration() {
                        const n = parseInt(this.customDurationValue, 10);
                        if (!Number.isFinite(n) || n <= 0) return;
                        let minutes = this.customDurationUnit === 'hours' ? n * 60 : n;
                        const max = Number(this.maxDurationMinutes) || 1440;
                        if (minutes > max) {
                            minutes = max;
                        }
                        updateProperty('duration', minutes);
                    },
                }"
            >
                <button
                    type="button"
                    class="{{ $dropdownItemClass }}"
                    :class="{ 'font-semibold text-foreground': duration == null }"
                    @click="updateProperty('duration', null)"
                >
                    {{ __('Not set') }}
                </button>
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

                    <div class="mt-1 border-t border-border/60 pt-2 px-3 pb-1 text-xs text-muted-foreground">
                        <div class="mb-1 text-[11px] font-medium">
                            {{ __('Custom duration') }}
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                type="number"
                                min="1"
                                step="1"
                                x-model.number="customDurationValue"
                                placeholder="30"
                                @click.stop
                                @blur="applyCustomDuration()"
                                @keydown.enter.prevent.stop="applyCustomDuration()"
                                class="h-8 w-16 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue"
                            />
                            <div class="inline-flex overflow-hidden rounded-full border border-zinc-200 bg-zinc-50 text-[11px] shadow-sm">
                                <button
                                    type="button"
                                    class="px-2 py-1 transition-colors"
                                    :class="customDurationUnit === 'minutes'
                                        ? 'bg-brand-blue text-white'
                                        : 'text-zinc-600 hover:bg-zinc-100'"
                                    @click.stop.prevent="customDurationUnit = 'minutes'; applyCustomDuration()"
                                >
                                    {{ __('Min') }}
                                </button>
                                <button
                                    type="button"
                                    class="px-2 py-1 transition-colors"
                                    :class="customDurationUnit === 'hours'
                                        ? 'bg-brand-blue text-white'
                                        : 'text-zinc-600 hover:bg-zinc-100'"
                                    @click.stop.prevent="customDurationUnit = 'hours'; applyCustomDuration()"
                                >
                                    {{ __('Hours') }}
                                </button>
                            </div>
                        </div>
                    </div>
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

    <x-date-picker
        model="startDatetime"
        type="datetime-local"
        :triggerLabel="__('Start')"
        :label="__('Start Date')"
        position="top"
        align="{{ $taskDropdownAlign }}"
        :initial-value="$startDatetimeInitial"
        :item-id="$item->id"
        :readonly="!$canEditDates"
        data-task-creation-safe
    />

    <x-date-picker
        model="endDatetime"
        type="datetime-local"
        :triggerLabel="__('Due')"
        :label="__('End Date')"
        position="top"
        align="{{ $taskDropdownAlign }}"
        :initial-value="$endDatetimeInitial"
        :overdue="$showOverdueVisual ?? $isOverdue"
        :item-id="$item->id"
        :readonly="!$canEditDates"
        data-task-creation-safe
    />

    <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
        <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600" />
        <p class="text-xs font-medium text-red-600" x-text="editDateRangeError"></p>
    </div>

    @endif

    @php
    $hideTagsSection = ($isCollaboratedView && $item->tags->isEmpty())
        || ($embedInFocusModal ?? false);
    @endphp

    @if($canEdit)
        <div class="w-full basis-full mt-1 flex flex-col gap-2">
            @if(($layout ?? 'list') === 'kanban' && ! $embedInFocusModal)
            <div
                class="flex items-center"
                x-show="status !== 'done' && (!listItemCard || (!listItemCard.isFocused && !listItemCard.isBreakFocused))"
                @if($hideFocusButtonInitiallyDone) style="display: none;" @endif
            >
                <flux:tooltip :content="__('Start focus mode')">
                    <button
                        type="button"
                        x-ref="focusTrigger"
                        @click.stop="listItemCard && listItemCard.enterFocusReady()"
                        class="workspace-focus-trigger"
                    >
                        <flux:icon name="bolt" class="size-4 shrink-0" />
                        <span>{{ __('Focus') }}</span>
                    </button>
                </flux:tooltip>
            </div>
            @endif
            <div
                x-show="taskProgressSectionShown"
                x-cloak
                class="w-full"
            >
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ __('Task progress') }}</span>
                        <span class="text-xs tabular-nums text-zinc-600 dark:text-zinc-300" x-text="listItemCard ? listItemCard.taskFocusProgressPercentText : ''">
                            {{ $initialTaskProgressPercent }}%
                        </span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" :aria-valuenow="listItemCard ? Math.round(listItemCard.taskFocusProgressPercentTotal) : 0" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Task progress') }}">
                        <div
                            class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width,background-color] duration-300 ease-linear"
                            style="width: {{ $initialTaskProgressPercent }}%; min-width: {{ $initialTaskProgressPercent > 0 ? '2px' : '0' }};"
                            :style="listItemCard ? ('width: ' + Math.round(listItemCard.taskFocusProgressPercentTotal) + '%; min-width: ' + (Math.round(listItemCard.taskFocusProgressPercentTotal) > 0 ? '2px' : '0')) : ''"
                        ></div>
                    </div>
                    <span class="text-xs text-zinc-500" x-text="listItemCard ? (listItemCard.taskFocusRemainingText + ' {{ __('left') }}') : ''">
                        {{ $initialTaskRemainingText }} {{ __('left') }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    @unless($hideTagsSection)
        <div @class([
            'w-full basis-full flex flex-wrap items-center gap-2 border-t border-border/50 text-[10px]',
            'pt-1 mt-0.5' => $useKanbanCompact,
            'pt-1.5 mt-1' => ! $useKanbanCompact,
        ])>
            <div
                @tag-toggled="toggleTag($event.detail.tagId)"
                @tag-create-request="createTagOptimistic($event.detail.tagName)"
                @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
            >
                <x-workspace.tag-selection
                    position="top"
                    :align="$useKanbanCompact ? 'start' : 'end'"
                    :selected-tags="$item->tags"
                    :readonly="!$canEditTags"
                    :compact="$useKanbanCompact"
                />
            </div>
        </div>
    @endunless
</div>

@unless($useKanbanCompact)
<div class="flex flex-wrap items-center gap-2">
    @if($showCourseContextPill)
        <flux:tooltip content="{{ $courseContextTooltip }}" position="top" align="start">
            <span
                tabindex="0"
                class="inline-flex cursor-default items-start gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <flux:icon name="book-open" class="size-3 shrink-0 mt-0.5" />
                <span class="max-w-[220px] truncate text-[10px] font-semibold uppercase leading-tight">
                    {{ $courseContextPillCompactLine }}
                </span>
            </span>
        </flux:tooltip>
    @endif

    @unless(($layout ?? 'list') === 'kanban')
    @if($canEdit)
        @if(! (($embedInFocusModal ?? false) && ! $hasProjectParent))
            <x-workspace.project-parent-popover
                :task-id="$item->id"
                :current-project-id="$item->project_id"
                :current-project-name="$item->project?->name"
            >
                <span
                    x-show="kind === 'task'"
                    class="lic-project-chip"
                >
                    <flux:icon name="folder" class="size-3" />
                    <span
                        class="inline-flex items-baseline gap-1"
                        style="{{ ($item->project_id && $item->project) ? '' : 'display: none;' }}"
                        x-show="showProjectPill"
                    >
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-90">{{ __('Project') }}:</span>
                        <span class="truncate max-w-[120px] uppercase" x-text="itemProjectName ?? ''">{{ $item->project?->name ?? '' }}</span>
                    </span>
                    <span
                        class="inline-flex items-baseline gap-1 text-[10px] font-semibold uppercase tracking-wide opacity-90"
                        style="{{ ($item->project_id && $item->project) ? 'display: none;' : '' }}"
                        x-show="!showProjectPill"
                    >
                        {{ __('Put in project') }}
                    </span>
                </span>
            </x-workspace.project-parent-popover>
        @endif

        @if(! (($embedInFocusModal ?? false) && ! $hasEventParent))
            <x-workspace.event-parent-popover
                :task-id="$item->id"
                :current-event-id="$item->event_id"
                :current-event-title="$item->event?->title"
            >
                <span
                    x-show="kind === 'task'"
                    class="lic-event-chip"
                >
                    <flux:icon name="calendar" class="size-3" />
                    <span
                        class="inline-flex items-baseline gap-1"
                        style="{{ ($item->event_id && $item->event) ? '' : 'display: none;' }}"
                        x-show="showEventPill"
                    >
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Event') }}:</span>
                        <span class="truncate max-w-[120px] uppercase" x-text="itemEventTitle ?? ''">{{ $item->event?->title ?? '' }}</span>
                    </span>
                    <span
                        class="inline-flex items-baseline gap-1 text-[10px] font-semibold uppercase tracking-wide opacity-70"
                        style="{{ ($item->event_id && $item->event) ? 'display: none;' : '' }}"
                        x-show="!showEventPill"
                    >
                        {{ __('Put in event') }}
                    </span>
                </span>
            </x-workspace.event-parent-popover>
        @endif
    @else
        <span
            x-show="kind === 'task' && showProjectPill"
            x-cloak
            class="lic-project-chip"
        >
            <flux:icon name="folder" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-90">
                    {{ __('Project') }}:
                </span>
                <span class="truncate max-w-[120px] uppercase">{{ $item->project?->name ?? '' }}</span>
            </span>
        </span>

        <span
            x-show="kind === 'task' && showEventPill"
            x-cloak
            class="lic-event-chip"
        >
            <flux:icon name="calendar" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Event') }}:
                </span>
                <span class="truncate max-w-[120px] uppercase">{{ $item->event?->title ?? '' }}</span>
            </span>
        </span>
    @endif
    @endunless
</div>
@endunless
    </div>

