@props([
    'kind',
    'item',
    'listFilterDate' => null,
    'filters' => [],
    'availableTags' => [],
    'isOverdue' => false,
])

@php
    $kind = strtolower((string) $kind);

    $title = match ($kind) {
        'project' => $item->name,
        'event' => $item->title,
        'task' => $item->title,
        default => '',
    };

    $description = match ($kind) {
        'project' => $item->description,
        'event' => $item->description,
        'task' => $item->description,
        default => null,
    };

    $type = match ($kind) {
        'project' => __('Project'),
        'event' => __('Event'),
        'task' => __('Task'),
        default => null,
    };

    $deleteMethod = match ($kind) {
        'project' => 'deleteProject',
        'event' => 'deleteEvent',
        'task' => 'deleteTask',
        default => null,
    };

    $updatePropertyMethod = match ($kind) {
        'project' => 'updateProjectProperty',
        'event' => 'updateEventProperty',
        'task' => 'updateTaskProperty',
        default => null,
    };

    $owner = $item->user ?? null;
    $hasCollaborators = ($item->collaborators ?? collect())->count() > 0;
    $currentUserIsOwner = auth()->id() && $owner && (int) auth()->id() === (int) $owner->id;
    $showOwnerBadge = $hasCollaborators && ! $currentUserIsOwner && $owner;
    $canEdit = auth()->user()?->can('update', $item) ?? false;
    $canEditTags = $currentUserIsOwner && $canEdit;
    $canEditDates = $currentUserIsOwner && $canEdit;
    $canEditRecurrence = $currentUserIsOwner && $canEdit;
    $canDelete = $currentUserIsOwner && $canEdit;

    if ($kind === 'task') {
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

        $effectiveStatus = $item->effectiveStatusForDate ?? $item->status;
        $statusInitialOption = collect($statusOptions)->firstWhere('value', $effectiveStatus?->value);
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
    }

    if ($kind === 'event') {
        $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

        $eventStatusOptions = [
            ['value' => \App\Enums\EventStatus::Scheduled->value, 'label' => __('Scheduled'), 'color' => \App\Enums\EventStatus::Scheduled->color()],
            ['value' => \App\Enums\EventStatus::Ongoing->value, 'label' => __('Ongoing'), 'color' => \App\Enums\EventStatus::Ongoing->color()],
            ['value' => \App\Enums\EventStatus::Tentative->value, 'label' => __('Tentative'), 'color' => \App\Enums\EventStatus::Tentative->color()],
            ['value' => \App\Enums\EventStatus::Completed->value, 'label' => __('Completed'), 'color' => \App\Enums\EventStatus::Completed->color()],
            ['value' => \App\Enums\EventStatus::Cancelled->value, 'label' => __('Cancelled'), 'color' => \App\Enums\EventStatus::Cancelled->color()],
        ];

        $eventEffectiveStatus = $item->effectiveStatusForDate ?? $item->status;
        $eventStatusInitialOption = collect($eventStatusOptions)->firstWhere('value', $eventEffectiveStatus?->value);

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
    }

    $headerRecurrenceInitial = match ($kind) {
        'task' => $recurrenceInitial ?? null,
        'event' => $eventRecurrenceInitial ?? null,
        default => null,
    };
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
    wire:ignore
    x-data="{
        alpineReady: false,
        deletingInProgress: false,
        dateChangeHidingCard: false,
        clientOverdue: false,
        clientNotOverdue: false,
        hideCard: false,
        dropdownOpenCount: 0,
        kind: @js($kind),
        listFilterDate: @js($listFilterDate),
        filters: @js($filters ?? []),
        canEdit: @js($canEdit),
        canDelete: @js($canDelete),
        deleteMethod: @js($deleteMethod),
        itemId: @js($item->id),
        isRecurringTask: @js($kind === 'task' && (bool) $item->recurringTask),
        hasRecurringEvent: @js($kind === 'event' && (bool) $item->recurringEvent),
        recurrence: @js($headerRecurrenceInitial),
        deleteErrorToast: @js(__('Something went wrong. Please try again.')),
        isEditingTitle: false,
        editedTitle: @js($title),
        titleSnapshot: null,
        savingTitle: false,
        justCanceledTitle: false,
        savedViaEnter: false,
        updatePropertyMethod: @js($updatePropertyMethod),
        titleProperty: @js(match($kind) { 'project' => 'name', default => 'title' }),
        titleErrorToast: @js(__('Title cannot be empty.')),
        titleUpdateErrorToast: @js(__('Something went wrong updating the title.')),
        recurrenceUpdateErrorToast: @js(__('Something went wrong. Please try again.')),
        descriptionUpdateErrorToast: @js(__('Couldn\'t save :property. Try again.', ['property' => __('Description')])),
        isEditingDescription: false,
        editedDescription: @js($description ?? ''),
        descriptionSnapshot: null,
        savingDescription: false,
        justCanceledDescription: false,
        savedDescriptionViaEnter: false,
        descriptionProperty: 'description',
        addDescriptionLabel: @js(__('Add description')),
        isOverdue: @js($isOverdue),
        hideFromList() {
            if (this.hideCard) {
                return;
            }
            this.hideCard = true;
            $dispatch('list-item-hidden', { fromOverdue: this.isOverdue });
        },
        /**
         * Determine if a task is still relevant for the current list filter date.
         *
         * This mirrors the backend Task::scopeRelevantForDate logic:
         * - tasks with no dates are always relevant
         * - tasks with only an end date are relevant up to and including that end date
         * - tasks with a start (and optional end) are relevant if their window overlaps the day
         */
        isTaskStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'task' || !this.listFilterDate) {
                return true;
            }

            const filterDate = String(this.listFilterDate).slice(0, 10);

            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };

            const start = parseDateTime(startDatetime);
            const end = parseDateTime(endDatetime);

            // No dates at all => always relevant (matches no-dates branch in scopeRelevantForDate).
            if (!start && !end) {
                return true;
            }

            const startOfDay = new Date(filterDate + 'T00:00:00');
            const endOfDay = new Date(filterDate + 'T23:59:59.999');
            const startOfDayMs = startOfDay.getTime();
            const endOfDayMs = endOfDay.getTime();

            // Only end date: relevant while end date (by calendar date) is on/after the filter date.
            if (!start && end) {
                try {
                    const endDate = end.toISOString().slice(0, 10);
                    return endDate >= filterDate;
                } catch (_) {
                    return true;
                }
            }

            // From here we have a start; end may be null.
            if (!start) {
                // If parsing failed for start, don't aggressively hide the card.
                return true;
            }

            const startMs = start.getTime();

            // Start falls within the day window.
            if (startMs >= startOfDayMs && startMs <= endOfDayMs) {
                return true;
            }

            // Started before this day – keep if it is still running through this day.
            if (startMs <= startOfDayMs) {
                if (!end) {
                    // Open-ended task.
                    return true;
                }
                const endMs = end.getTime();
                return endMs >= endOfDayMs;
            }

            return false;
        },
        /**
         * Determine if an event is still relevant for the current list filter date.
         *
         * Mirrors Event::scopeActiveForDate:
         * - no dates => always relevant
         * - only end date => relevant while end date (by calendar date) is on/after the filter date
         * - start/end window overlaps the day => relevant
         */
        isEventStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'event' || !this.listFilterDate) {
                return true;
            }

            const filterDate = String(this.listFilterDate).slice(0, 10);

            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };

            const start = parseDateTime(startDatetime);
            const end = parseDateTime(endDatetime);

            // No dates at all => always relevant.
            if (!start && !end) {
                return true;
            }

            const startOfDay = new Date(filterDate + 'T00:00:00');
            const endOfDay = new Date(filterDate + 'T23:59:59.999');
            const startOfDayMs = startOfDay.getTime();
            const endOfDayMs = endOfDay.getTime();

            // Only end date: relevant while end date (by calendar date) is on/after the filter date.
            if (!start && end) {
                try {
                    const endDate = end.toISOString().slice(0, 10);
                    return endDate >= filterDate;
                } catch (_) {
                    return true;
                }
            }

            // From here we have a start; end may be null.
            if (!start) {
                return true;
            }

            const startMs = start.getTime();

            // Start falls within the day window.
            if (startMs >= startOfDayMs && startMs <= endOfDayMs) {
                return true;
            }

            // Started before this day – keep if it is still active through this day.
            if (startMs <= startOfDayMs) {
                if (!end) {
                    return true;
                }
                const endMs = end.getTime();
                return endMs >= endOfDayMs;
            }

            return false;
        },
        /**
         * Determine if a project is still relevant for the current list filter date.
         *
         * Mirrors Project::scopeActiveForDate:
         * - no dates => always relevant
         * - only end date => relevant while end date (by calendar date) is on/after the filter date
         * - start/end window overlaps the day => relevant
         */
        isProjectStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'project' || !this.listFilterDate) {
                return true;
            }

            const filterDate = String(this.listFilterDate).slice(0, 10);

            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };

            const start = parseDateTime(startDatetime);
            const end = parseDateTime(endDatetime);

            // No dates at all => always relevant.
            if (!start && !end) {
                return true;
            }

            const startOfDay = new Date(filterDate + 'T00:00:00');
            const endOfDay = new Date(filterDate + 'T23:59:59.999');
            const startOfDayMs = startOfDay.getTime();
            const endOfDayMs = endOfDay.getTime();

            // Only end date: relevant while end date (by calendar date) is on/after the filter date.
            if (!start && end) {
                try {
                    const endDate = end.toISOString().slice(0, 10);
                    return endDate >= filterDate;
                } catch (_) {
                    return true;
                }
            }

            // From here we have a start; end may be null.
            if (!start) {
                return true;
            }

            const startMs = start.getTime();

            // Start falls within the day window.
            if (startMs >= startOfDayMs && startMs <= endOfDayMs) {
                return true;
            }

            // Started before this day – keep if it is still active through this day.
            if (startMs <= startOfDayMs) {
                if (!end) {
                    return true;
                }
                const endMs = end.getTime();
                return endMs >= endOfDayMs;
            }

            return false;
        },
        /**
         * Determine if an item is still overdue (end date before today).
         * Mirrors Task::scopeOverdue / Event::scopeOverdue.
         */
        isStillOverdue(startDatetime, endDatetime) {
            const today = new Date();
            const todayStr = today.toISOString().slice(0, 10);

            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };

            const end = parseDateTime(endDatetime);
            if (!end) {
                return false;
            }
            try {
                const endDateStr = end.toISOString().slice(0, 10);
                return endDateStr < todayStr;
            } catch (_) {
                return true;
            }
        },
        /**
         * Determine if the card should hide after a property update.
         * Centralized logic for all filters: date, priority, status, complexity, tags, recurrence.
         */
        shouldHideAfterPropertyUpdate(detail) {
            const { property, value, startDatetime: detailStart, endDatetime: detailEnd } = detail;
            const f = this.filters ?? {};

            // Date relevance: always check for date updates (uses listFilterDate, not filters)
            if (['startDatetime', 'endDatetime'].includes(property)) {
                const start = detailStart ?? null;
                const end = detailEnd ?? null;
                if (this.kind === 'task') {
                    if (this.isOverdue) {
                        return false;
                    }
                    if (this.isStillOverdue(start, end)) {
                        return false;
                    }
                    return !this.isTaskStillRelevantForList(start, end);
                }
                if (this.kind === 'event') {
                    if (this.isOverdue) {
                        return false;
                    }
                    if (this.isStillOverdue(start, end)) {
                        return false;
                    }
                    return !this.isEventStillRelevantForList(start, end);
                }
                if (this.kind === 'project') {
                    return !this.isProjectStillRelevantForList(start, end);
                }
            }

            // Non-date filters: only check when filters are active
            if (!f?.hasActiveFilters) {
                return false;
            }

            if (this.kind === 'task') {
                if (f.taskPriority && property === 'priority' && value !== f.taskPriority) return true;
                if (f.taskStatus && property === 'status' && value !== f.taskStatus) return true;
                if (f.taskComplexity && property === 'complexity' && value !== f.taskComplexity) return true;
            }

            if (this.kind === 'event') {
                if (f.eventStatus && property === 'status' && value !== f.eventStatus) return true;
            }

            if (f.tagIds?.length && property === 'tagIds') {
                const ids = Array.isArray(value) ? value : [];
                const hasMatch = ids.some((id) => f.tagIds.includes(Number(id)) || f.tagIds.includes(String(id)));
                if (!hasMatch) return true;
            }

            if (f.recurring === 'recurring' && property === 'recurrence' && !value?.enabled) return true;
            if (f.recurring === 'oneTime' && property === 'recurrence' && value?.enabled) return true;

            if (property === 'status') {
                if (this.kind === 'task' && value === 'done') return true;
                if (this.kind === 'event' && ['completed', 'cancelled'].includes(value)) return true;
            }

            return false;
        },
        async deleteItem() {
            if (!this.canDelete || this.deletingInProgress || this.hideCard || !this.deleteMethod || this.itemId == null) return;

            const wasOverdue = this.isOverdue;
            this.deletingInProgress = true;

            try {
                // Phase 1: Optimistic update – hide immediately (no refresh yet)
                this.hideFromList(false);

                // Phase 2: Call server
                const ok = await $wire.$parent.$call(this.deleteMethod, this.itemId);

                if (!ok) {
                    // Phase 3: Rollback – show card again
                    this.hideCard = false;
                    $dispatch('list-item-shown', { fromOverdue: wasOverdue });
                    $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                // Rollback – show card again
                this.hideCard = false;
                $dispatch('list-item-shown', { fromOverdue: wasOverdue });
                $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
            } finally {
                this.deletingInProgress = false;
            }
        },
        startEditingTitle() {
            if (!this.canEdit || this.deletingInProgress || !this.updatePropertyMethod) return;
            this.titleSnapshot = this.editedTitle;
            this.isEditingTitle = true;
            this.$nextTick(() => {
                const input = this.$refs.titleInput;
                if (input) {
                    input.focus();
                    // Position cursor at the end instead of selecting all
                    const length = input.value.length;
                    input.setSelectionRange(length, length);
                }
            });
        },
        cancelEditingTitle() {
            this.justCanceledTitle = true;
            this.savedViaEnter = false;
            this.editedTitle = this.titleSnapshot;
            this.isEditingTitle = false;
            this.titleSnapshot = null;
            // Reset flag after a short delay to allow blur events to settle
            setTimeout(() => { this.justCanceledTitle = false; }, 100);
        },
        async saveTitle() {
            if (this.deletingInProgress || !this.updatePropertyMethod || !this.itemId || this.savingTitle || this.justCanceledTitle) return;
            
            const trimmedTitle = (this.editedTitle || '').trim();
            // 1) Empty titles are forbidden – show error and revert without calling backend
            if (!trimmedTitle) {
                $wire.$dispatch('toast', { type: 'error', message: this.titleErrorToast });
                this.cancelEditingTitle();
                return;
            }

            // Snapshot for rollback (use original value from when editing started)
            const snapshot = this.titleSnapshot;
            const originalTrimmed = (snapshot ?? '').toString().trim();

            // 2) Do not submit if nothing actually changed (no backend call, simply exit edit mode)
            if (trimmedTitle === originalTrimmed) {
                this.editedTitle = snapshot;
                this.isEditingTitle = false;
                this.titleSnapshot = null;
                return;
            }

            // Prevent concurrent saves
            this.savingTitle = true;
            
            try {
                // Optimistic update - update immediately (x-model already updated it, but ensure trimmed)
                this.editedTitle = trimmedTitle;
                
                // Call server
                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, this.titleProperty, trimmedTitle, false);
                
                if (!ok) {
                    // Rollback on failure
                    this.editedTitle = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.titleUpdateErrorToast });
                } else {
                    // Success - exit edit mode
                    this.isEditingTitle = false;
                    this.titleSnapshot = null;
                }
            } catch (error) {
                // Rollback on error
                this.editedTitle = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.titleUpdateErrorToast });
            } finally {
                this.savingTitle = false;
                // Reset savedViaEnter flag after a short delay to allow blur events to settle
                if (this.savedViaEnter) {
                    setTimeout(() => { this.savedViaEnter = false; }, 100);
                }
            }
        },
        handleEnterKey() {
            this.savedViaEnter = true;
            this.saveTitle();
        },
        handleBlur() {
            if (!this.savedViaEnter && !this.justCanceledTitle) {
                this.saveTitle();
            }
        },
        startEditingDescription() {
            if (!this.canEdit || this.deletingInProgress || !this.updatePropertyMethod) return;
            this.descriptionSnapshot = this.editedDescription;
            this.isEditingDescription = true;
        },
        cancelEditingDescription() {
            this.justCanceledDescription = true;
            this.savedDescriptionViaEnter = false;
            this.editedDescription = this.descriptionSnapshot ?? '';
            this.isEditingDescription = false;
            this.descriptionSnapshot = null;
            setTimeout(() => { this.justCanceledDescription = false; }, 100);
        },
        async saveDescription() {
            if (this.deletingInProgress || !this.updatePropertyMethod || !this.itemId || this.savingDescription || this.justCanceledDescription) return;

            const trimmedDesc = (this.editedDescription ?? '').toString().trim();
            const snapshot = this.descriptionSnapshot ?? '';
            const originalTrimmed = (snapshot ?? '').toString().trim();

            if (trimmedDesc === originalTrimmed) {
                this.editedDescription = snapshot;
                this.isEditingDescription = false;
                this.descriptionSnapshot = null;
                return;
            }

            this.savingDescription = true;

            try {
                this.editedDescription = trimmedDesc;

                const valueToSave = trimmedDesc === '' ? null : trimmedDesc;
                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, this.descriptionProperty, valueToSave, false);

                if (!ok) {
                    this.editedDescription = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.descriptionUpdateErrorToast });
                } else {
                    this.isEditingDescription = false;
                    this.descriptionSnapshot = null;
                }
            } catch (error) {
                this.editedDescription = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.descriptionUpdateErrorToast });
            } finally {
                this.savingDescription = false;
                if (this.savedDescriptionViaEnter) {
                    setTimeout(() => { this.savedDescriptionViaEnter = false; }, 100);
                }
            }
        },
        handleDescriptionKeydown(e) {
            if (e.key === 'Escape') {
                this.cancelEditingDescription();
            } else if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.savedDescriptionViaEnter = true;
                this.saveDescription();
            }
        },
        handleDescriptionBlur() {
            if (!this.savedDescriptionViaEnter && !this.justCanceledDescription) {
                this.saveDescription();
            }
        },
        async updateRecurrence(value) {
            if (!this.updatePropertyMethod || !this.itemId) {
                return;
            }

            const snapshot = this.recurrence;

            this.recurrence = value;

            try {
                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, 'recurrence', value, false);
                if (!ok) {
                    this.recurrence = snapshot;
                    $dispatch('recurring-revert', { path: 'recurrence', value: snapshot });
                    $wire.$dispatch('toast', { type: 'error', message: this.recurrenceUpdateErrorToast });
                    return;
                }

                $dispatch('recurring-value', { path: 'recurrence', value });
            } catch (e) {
                this.recurrence = snapshot;
                $dispatch('recurring-revert', { path: 'recurrence', value: snapshot });
                $wire.$dispatch('toast', { type: 'error', message: this.recurrenceUpdateErrorToast });
            }
        },
    }"
    x-init="alpineReady = true"
    x-show="!hideCard"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-[0.98]"
    @dropdown-opened="dropdownOpenCount++"
    @dropdown-closed="dropdownOpenCount--"
    @recurring-selection-updated="
        if ($event.detail && $event.detail.path === 'recurrence') {
            updateRecurrence($event.detail.value);
        }
    "
    @item-property-updated="
        if (shouldHideAfterPropertyUpdate($event.detail)) {
            dateChangeHidingCard = true;
            hideFromList(false);
        } else {
            dateChangeHidingCard = false;
            const d = $event.detail;
            if (['startDatetime', 'endDatetime'].includes(d?.property) && (kind === 'task' || kind === 'event')) {
                const stillOverdue = isStillOverdue(d?.startDatetime ?? null, d?.endDatetime ?? null);
                if (!isOverdue && stillOverdue) {
                    clientOverdue = true;
                    clientNotOverdue = false;
                } else if (isOverdue && !stillOverdue) {
                    clientOverdue = false;
                    clientNotOverdue = true;
                }
            }
        }
    "
    @item-update-rollback="
        if (hideCard) {
            hideCard = false;
            $dispatch('list-item-shown', { fromOverdue: isOverdue });
        }
    "
    @collaboration-self-left="hideFromList()"
    :class="{ 'relative z-50': dropdownOpenCount > 0, 'pointer-events-none opacity-60': deletingInProgress }"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p 
                x-show="!isEditingTitle"
                @click="canEdit && startEditingTitle()"
                class="truncate text-lg font-semibold leading-tight transition-opacity"
                :class="canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default'"
                x-text="editedTitle"
            >
                {{ $title }}
            </p>
            <input
                x-show="isEditingTitle"
                x-cloak
                x-ref="titleInput"
                x-model="editedTitle"
                @keydown.enter.prevent="handleEnterKey()"
                @keydown.escape="cancelEditingTitle()"
                @blur="handleBlur()"
                wire:ignore
                class="w-full min-w-0 text-lg font-semibold leading-tight rounded-md bg-muted/20 px-1 py-0.5 -mx-1 -my-0.5 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10"
                type="text"
            />

            <div class="mt-0.5" x-effect="isEditingDescription && $nextTick(() => requestAnimationFrame(() => { const el = $refs.descriptionInput; if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }))">
                {{-- Server-rendered first paint --}}
                <div x-show="!alpineReady">
                    @if(trim((string) ($description ?? '')) !== '')
                        <p
                            class="line-clamp-2 text-xs text-foreground/70 {{ $canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default' }} transition-opacity"
                        >{{ $description ?? '' }}</p>
                    @elseif($canEdit)
                        <button
                            type="button"
                            class="text-xs text-muted-foreground hover:text-foreground/70 transition-colors inline-flex items-center gap-1 cursor-pointer"
                        >
                            <flux:icon name="plus" class="size-3" />
                            <span>{{ __('Add description') }}</span>
                        </button>
                    @endif
                </div>

                {{-- Alpine reactive (replaces server content when hydrated) --}}
                <div x-show="alpineReady && !isEditingDescription" x-cloak>
                    <p
                        x-show="editedDescription"
                        @click="canEdit && startEditingDescription()"
                        class="line-clamp-2 text-xs text-foreground/70 transition-opacity"
                        :class="canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default'"
                        x-text="editedDescription"
                    ></p>
                    <button
                        x-show="canEdit && !editedDescription"
                        type="button"
                        @click="startEditingDescription()"
                        class="text-xs text-muted-foreground hover:text-foreground/70 transition-colors inline-flex items-center gap-1 cursor-pointer"
                    >
                        <flux:icon name="plus" class="size-3" />
                        <span x-text="addDescriptionLabel"></span>
                    </button>
                </div>

                <textarea
                    x-show="isEditingDescription"
                    x-cloak
                    x-ref="descriptionInput"
                    x-model="editedDescription"
                    x-on:keydown="handleDescriptionKeydown($event)"
                    x-on:blur="handleDescriptionBlur()"
                    wire:ignore
                    rows="2"
                    class="w-full min-w-0 text-xs rounded-md bg-muted/20 px-2 py-1 -mx-1 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10 resize-none"
                    placeholder="{{ __('Add a description...') }}"
                ></textarea>
            </div>
        </div>

        @if($type || ($currentUserIsOwner && $deleteMethod))
            <div class="ml-2 flex items-center gap-1.5 shrink-0">
                @if($type)
                    <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ $type }}
                    </span>
                @endif

                @if($currentUserIsOwner && $deleteMethod)
                    <flux:dropdown>
                        <flux:button size="xs" icon="ellipsis-horizontal" />

                        <flux:menu>
                            <flux:menu.separator />

                            <flux:menu.item
                                icon="clock"
                            >
                                {{ __('Activity Logs') }}
                            </flux:menu.item>

                            <flux:menu.item
                                variant="danger"
                                icon="trash"
                                @click.throttle.250ms="deleteItem()"
                            >
                                Delete
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        @endif
    </div>

    @if($type)
        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
            @if(in_array($kind, ['task', 'event'], true))
                <x-recurring-selection
                    model="recurrence"
                    :initial-value="$headerRecurrenceInitial"
                    :kind="$kind"
                    :readonly="!$canEditRecurrence"
                    compactWhenDisabled
                    hideWhenDisabled
                    position="top"
                    align="end"
                />
            @endif

            <x-workspace.collaborators-popover
                :item="$item"
                :kind="$kind"
                position="top"
                align="end"
            />

            @if($showOwnerBadge)
                <flux:tooltip content="{{ __('Owner') }}: {{ $owner->name }}">
                    <span
                        class="inline-flex items-center gap-1 rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
                    >
                        <flux:icon name="user" class="size-3 shrink-0" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Owner') }}:
                            </span>
                            <span class="truncate max-w-24">{{ $owner->name }}</span>
                        </span>
                    </span>
                </flux:tooltip>
            @endif

            @if(! $currentUserIsOwner)
                @if($canEdit)
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-600 dark:border-emerald-400/50 dark:text-emerald-400"
                    >
                        <flux:icon name="pencil-square" class="size-3 shrink-0" />
                        <span>{{ __('Can edit') }}</span>
                    </span>
                @else
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                    >
                        <flux:icon name="eye" class="size-3 shrink-0" />
                        <span>{{ __('View only') }}</span>
                    </span>
                @endif
            @endif

            @if(in_array($kind, ['task', 'event'], true))
                <span
                    x-show="(isOverdue || clientOverdue) && !clientNotOverdue"
                    x-cloak
                    @if(!$isOverdue) style="display: none" @endif
                    class="inline-flex items-center gap-1 rounded-full border border-red-500/40 bg-red-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-red-700 dark:border-red-400/40 dark:bg-red-500/10 dark:text-red-400"
                >
                    <flux:icon name="exclamation-triangle" class="size-3 shrink-0" />
                    {{ __('Overdue') }}
                </span>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
    @if($kind === 'project')
        <x-workspace.list-item-project :item="$item" :update-property-method="$updatePropertyMethod" :readonly="!$canEditDates" />

        <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-amber-500/10 px-2.5 py-0.5 font-medium text-amber-500 dark:border-white/10">
            <flux:icon name="list-bullet" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Tasks') }}:
                </span>
                <span>
                    {{ $item->tasks->count() }}
                </span>
            </span>
        </span>
    </div>
    @elseif($kind === 'event')
        <div
            wire:ignore
            x-data="{
                canEdit: @js($canEdit),
                itemId: @js($item->id),
                updatePropertyMethod: @js($updatePropertyMethod),
                listFilterDate: @js($listFilterDate),
                isRecurringEvent: @js((bool) $item->recurringEvent),
                status: @js($eventEffectiveStatus?->value ?? $item->status?->value),
                allDay: @js($item->all_day),
                startDatetime: @js($eventStartDatetimeInitial),
                endDatetime: @js($eventEndDatetimeInitial),
                recurrence: @js($eventRecurrenceInitial),
                statusOptions: @js($eventStatusOptions ?? []),
                formData: { item: { tagIds: @js($item->tags->pluck('id')->values()->all()) } },
                tags: @js($availableTags),
                newTagName: '',
                creatingTag: false,
                deletingTagIds: new Set(),
                editErrorToast: @js(__('Something went wrong. Please try again.')),
                tagMessages: {
                    tagAlreadyExists: @js(__('Tag already exists.')),
                    tagError: @js(__('Something went wrong. Please try again.')),
                    tagRemovedFromItem: @js(__('Tag ":tag" removed from :type ":item".')),
                    tagDeleted: @js(__('Tag ":tag" deleted.')),
                },
                itemTitle: @js($item->title ?? ''),
                itemTypeLabel: @js($kind === 'task' ? __('Task') : __('Event')),
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
                    if (existingTag && !String(existingTag.id).startsWith('temp-')) {
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
                    const tagIndex = this.tags?.findIndex(t => String(t.id) === String(tag.id)) ?? -1;
                    try {
                        this.deletingTagIds = this.deletingTagIds || new Set();
                        this.deletingTagIds.add(tag.id);
                        
                        // Optimistically remove from available tags list
                        if (this.tags && tagIndex !== -1) {
                            this.tags = this.tags.filter(t => String(t.id) !== String(tag.id));
                        }
                        
                        // Delete tag globally (not just from this item)
                        if (!isTempTag) {
                            await $wire.$parent.$call('deleteTag', tag.id, true);
                            // Show success message
                            if (tag.name) {
                                const msg = this.tagMessages.tagDeleted.replace(':tag', tag.name);
                                $wire.$dispatch('toast', { type: 'success', message: msg });
                            }
                            // The onTagDeleted handler will remove it from formData.item.tagIds
                        } else {
                            // For temp tags, just remove from local state
                            const selectedIndex = this.formData.item.tagIds?.indexOf(tag.id);
                            if (selectedIndex !== undefined && selectedIndex !== -1) {
                                this.formData.item.tagIds.splice(selectedIndex, 1);
                            }
                        }
                    } catch (err) {
                        // Rollback on error
                        if (tagIndex !== -1 && this.tags) {
                            this.tags.splice(tagIndex, 0, snapshot);
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
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
                    // Remove from available tags list
                    if (this.tags) {
                        const tagIndex = this.tags.findIndex(tag => tag.id === id);
                        if (tagIndex !== -1) {
                            this.tags.splice(tagIndex, 1);
                        }
                    }
                    // Remove from this item's selected tags (local state only)
                    // The backend has already removed it from the database
                    if (this.formData?.item?.tagIds) {
                        const selectedIndex = this.formData.item.tagIds.indexOf(id);
                        if (selectedIndex !== -1) {
                            this.formData.item.tagIds.splice(selectedIndex, 1);
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
                    if (!this.canEdit) return false;
                    if (property === 'tagIds') {
                        $dispatch('item-property-updated', { property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });
                        try {
                            const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, silentSuccessToast);
                            if (!ok) {
                                $dispatch('item-update-rollback');
                                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                                return false;
                            }
                            return true;
                        } catch (err) {
                            $dispatch('item-update-rollback');
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

                        $dispatch('item-property-updated', { property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });

                        const occurrenceDate = (property === 'status' && this.isRecurringEvent && this.listFilterDate) ? this.listFilterDate : null;
                        const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, false, occurrenceDate);
                        if (!ok) {
                            this.status = snapshot.status;
                            this.allDay = snapshot.allDay;
                            this.startDatetime = snapshot.startDatetime;
                            this.endDatetime = snapshot.endDatetime;
                            this.recurrence = snapshot.recurrence;
                            $dispatch('item-update-rollback');
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
                    const card = this.$parent?.$parent ?? this.$parent;
                    if (path === 'endDatetime' && card?.isStillOverdue) {
                        const stillOverdue = card.isStillOverdue(null, value);
                        card.clientOverdue = stillOverdue;
                        card.clientNotOverdue = !stillOverdue;
                    }
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
            <x-simple-select-dropdown position="top" align="end" :readonly="!$canEdit">
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
                        @if($canEdit)
                            <flux:icon name="chevron-down" class="size-3" />
                        @endif
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

        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $eventAllDayInitialClass }}"
            :class="[allDay ? 'bg-emerald-500/10 text-emerald-500 shadow-sm' : 'bg-muted text-muted-foreground', !canEdit && 'cursor-default pointer-events-none']"
            @click="if (canEdit) { const next = !allDay; allDay = next; updateProperty('allDay', next); }"
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
            :readonly="!$canEditDates"
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
            :overdue="$isOverdue"
            :readonly="!$canEditDates"
            data-task-creation-safe
        />

        <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
            <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
            <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
        </div>

        @if($currentUserIsOwner || $item->tags->isNotEmpty())
            <div class="w-full basis-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]">
                <div
                    @tag-toggled.stop="toggleTag($event.detail.tagId)"
                    @tag-create-request.stop="createTagOptimistic($event.detail.tagName)"
                    @tag-delete-request.stop="deleteTagOptimistic($event.detail.tag)"
                >
                    <x-workspace.tag-selection position="top" align="end" :selected-tags="$item->tags" :readonly="!$canEditTags" />
                </div>
            </div>
        @endif

        </div>
    </div>
    @elseif($kind === 'task')
        <div
            wire:ignore
            x-data="{
                canEdit: @js($canEdit),
                itemId: @js($item->id),
                updatePropertyMethod: @js($updatePropertyMethod),
                listFilterDate: @js($listFilterDate),
                isRecurringTask: @js((bool) $item->recurringTask),
                status: @js($effectiveStatus?->value ?? $item->status?->value),
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
                    const tagIndex = this.tags?.findIndex(t => t.id === tag.id) ?? -1;
                    try {
                        this.deletingTagIds = this.deletingTagIds || new Set();
                        this.deletingTagIds.add(tag.id);
                        
                        // Optimistically remove from available tags list
                        if (this.tags && tagIndex !== -1) this.tags = this.tags.filter(t => t.id !== tag.id);
                        
                        // Delete tag globally (not just from this item)
                        if (!isTempTag) {
                            await $wire.$parent.$call('deleteTag', tag.id, true);
                            // Show success message
                            if (tag.name) {
                                const msg = this.tagMessages.tagDeleted.replace(':tag', tag.name);
                                $wire.$dispatch('toast', { type: 'success', message: msg });
                            }
                            // The onTagDeleted handler will remove it from formData.item.tagIds
                        } else {
                            // For temp tags, just remove from local state
                            const selectedIndex = this.formData.item.tagIds?.indexOf(tag.id);
                            if (selectedIndex !== undefined && selectedIndex !== -1) this.formData.item.tagIds.splice(selectedIndex, 1);
                        }
                    } catch (err) {
                        // Rollback on error
                        if (tagIndex !== -1 && this.tags) {
                            this.tags.splice(tagIndex, 0, snapshot);
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
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
                        // Tag was created elsewhere (e.g. creation form); only keep our tags list in sync for the dropdown, do not persist this task's tagIds
                        if (this.tags && !this.tags.find(tag => tag.id === id)) {
                            this.tags.push({ id, name });
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
                        }
                    }
                },
                onTagDeleted(event) {
                    const { id } = event.detail || {};
                    // Remove from available tags list
                    if (this.tags) {
                        const tagIndex = this.tags.findIndex(tag => tag.id === id);
                        if (tagIndex !== -1) {
                            this.tags.splice(tagIndex, 1);
                        }
                    }
                    // Remove from this item's selected tags (local state only)
                    // The backend has already removed it from the database
                    if (this.formData?.item?.tagIds) {
                        const selectedIndex = this.formData.item.tagIds.indexOf(id);
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
                itemTypeLabel: @js($kind === 'task' ? __('Task') : __('Event')),
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
                    if (!this.canEdit) return false;
                    if (property === 'tagIds') {
                        $dispatch('item-property-updated', { property, value, startDatetime: this.startDatetime, endDatetime: this.endDatetime });
                        try {
                            const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, silentSuccessToast);
                            if (!ok) {
                                $dispatch('item-update-rollback');
                                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                                return false;
                            }
                            return true;
                        } catch (err) {
                            $dispatch('item-update-rollback');
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
                    const card = this.$parent?.$parent ?? this.$parent;
                    if (path === 'endDatetime' && card?.isStillOverdue) {
                        const stillOverdue = card.isStillOverdue(null, value);
                        card.clientOverdue = stillOverdue;
                        card.clientNotOverdue = !stillOverdue;
                    }
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
            class="contents"
            @date-picker-opened="handleDatePickerOpened($event)"
            @date-picker-value-changed="handleDatePickerValueChanged($event)"
            @date-picker-updated="handleDatePickerUpdated($event)"
            @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
            @tag-created.window="onTagCreated($event)"
            @tag-deleted.window="onTagDeleted($event)"
        >
        @if($item->status)
            <x-simple-select-dropdown position="top" align="end" :readonly="!$canEdit">
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
                        @if($canEdit)
                            <flux:icon name="chevron-down" class="size-3" />
                        @endif
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
        @endif

        @if($item->priority)
            <x-simple-select-dropdown position="top" align="end" :readonly="!$canEdit">
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
                        @if($canEdit)
                            <flux:icon name="chevron-down" class="size-3" />
                        @endif
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
        @endif

        @if($item->complexity)
            <x-simple-select-dropdown position="top" align="end" :readonly="!$canEdit">
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
                        @if($canEdit)
                            <flux:icon name="chevron-down" class="size-3" />
                        @endif
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
        @endif

        @if(! is_null($item->duration))
            <x-simple-select-dropdown position="top" align="end" :readonly="!$canEdit">
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
                        @if($canEdit)
                            <flux:icon name="chevron-down" class="size-3" />
                        @endif
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
            :overdue="$isOverdue"
            :readonly="!$canEditDates"
            data-task-creation-safe
        />

        <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
            <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
            <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
        </div>

        @if($currentUserIsOwner || $item->tags->isNotEmpty())
            <div class="w-full basis-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]">
                <div
                    @tag-toggled.stop="toggleTag($event.detail.tagId)"
                    @tag-create-request.stop="createTagOptimistic($event.detail.tagName)"
                    @tag-delete-request.stop="deleteTagOptimistic($event.detail.tag)"
                >
                    <x-workspace.tag-selection position="top" align="end" :selected-tags="$item->tags" :readonly="!$canEditTags" />
                </div>
            </div>
        @endif

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

        @if($item->completed_at)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-700 dark:border-white/10">
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Completed') }}:
                    </span>
                    <span class="opacity-80">
                        {{ $item->completed_at->format('Y-m-d') }}
                    </span>
                </span>
            </span>
        @endif
    </div>
    @endif

    <x-workspace.comments :item="$item" :kind="$kind" :readonly="!$canEdit" />
</div>
