<div
    class="space-y-4"
    x-data="{
        showItemCreation: false,
        creationKind: 'task',
        showItemLoading: false,
        loadingStartedAt: null,
        isSubmitting: false,
        messages: {
            taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
            taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
        },
        errors: {
            dateRange: null,
        },
        tags: @js($tags),
        projectNames: @js($projects->pluck('name', 'id')->toArray()),
        formData: {
            item: {
                title: '',
                status: 'to_do',
                priority: 'medium',
                complexity: 'moderate',
                duration: '60',
                startDatetime: null,
                endDatetime: null,
                allDay: false,
                projectId: null,
                tagIds: [],
                recurrence: {
                    enabled: false,
                    type: null,
                    interval: 1,
                    daysOfWeek: [],
                },
            },
        },
        validateDateRange() {
            this.errors.dateRange = null;

            const start = this.formData.item.startDatetime;
            const end = this.formData.item.endDatetime;

            if (!start || !end) {
                return true;
            }

            const startDate = new Date(start);
            const endDate = new Date(end);

            if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
                return true;
            }

            if (endDate.getTime() < startDate.getTime()) {
                this.errors.dateRange = this.messages.taskEndBeforeStart;
                return false;
            }

            // For tasks, enforce minimum duration on same-day start/end.
            if (this.creationKind === 'task') {
                const durationMinutes = parseInt(this.formData.item.duration ?? '0', 10);
                const isSameDay = startDate.toDateString() === endDate.toDateString();
                if (isSameDay && Number.isFinite(durationMinutes) && durationMinutes > 0) {
                    const minimumEnd = new Date(startDate.getTime() + (durationMinutes * 60 * 1000));

                    if (endDate.getTime() < minimumEnd.getTime()) {
                        this.errors.dateRange = this.messages.taskEndTooSoon.replace(':minutes', String(durationMinutes));
                        return false;
                    }
                }
            }

            return true;
        },
        resetForm() {
            // Common fields for both tasks and events
            this.formData.item.title = '';
            this.formData.item.startDatetime = null;
            this.formData.item.endDatetime = null;
            this.formData.item.tagIds = [];
            this.formData.item.recurrence = {
                enabled: false,
                type: null,
                interval: 1,
                daysOfWeek: [],
            };
            this.newTagName = '';
            this.errors.dateRange = null;

            // Kind-specific fields
            if (this.creationKind === 'task') {
                this.formData.item.status = 'to_do';
                this.formData.item.priority = 'medium';
                this.formData.item.complexity = 'moderate';
                this.formData.item.duration = '60';
                this.formData.item.allDay = false;
                this.formData.item.projectId = null;
            } else if (this.creationKind === 'event') {
                this.formData.item.status = 'scheduled';
                this.formData.item.allDay = false;
                // Events don't have priority, complexity, duration, or projectId
            }
        },
        toggleTag(tagId) {
            // Ensure tagIds array exists
            if (!this.formData.item.tagIds) {
                this.formData.item.tagIds = [];
            }

            // Convert tagId to string for consistent comparison (handles both number and string IDs)
            const tagIdStr = String(tagId);

            // Find index using string comparison to handle type mismatches
            const index = this.formData.item.tagIds.findIndex(id => String(id) === tagIdStr);

            if (index === -1) {
                // Add the tag ID (preserve original type - number if it's a number, string if it's a string)
                this.formData.item.tagIds.push(tagId);
            } else {
                // Remove the tag ID
                this.formData.item.tagIds.splice(index, 1);
            }
        },
        isTagSelected(tagId) {
            if (!this.formData.item.tagIds || !Array.isArray(this.formData.item.tagIds)) {
                return false;
            }
            // Use string comparison to handle type mismatches (number vs string IDs)
            const tagIdStr = String(tagId);
            return this.formData.item.tagIds.some(id => String(id) === tagIdStr);
        },
        getSelectedTagNames() {
            if (!this.tags || !this.formData.item.tagIds || this.formData.item.tagIds.length === 0) {
                return '';
            }
            const selectedIds = this.formData.item.tagIds;
            const selectedTags = this.tags.filter(tag => selectedIds.some(id => String(id) === String(tag.id)));
            return selectedTags.map(tag => tag.name).join(', ');
        },
        getSelectedTags() {
            if (!this.tags || !this.formData.item.tagIds || this.formData.item.tagIds.length === 0) {
                return [];
            }
            const selectedIds = this.formData.item.tagIds;
            return this.tags
                .filter(tag => selectedIds.some(id => String(id) === String(tag.id)))
                .sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        },
        newTagName: '',
        creatingTag: false,
        deletingTagIds: new Set(),
        async deleteTagOptimistic(tag) {
            // Prevent duplicate deletions
            if (this.deletingTagIds?.has(tag.id)) {
                return;
            }

            // Check if this is a temporary tag (not yet created on server)
            const isTempTag = String(tag.id).startsWith('temp-');

            // Snapshot for rollback
            const snapshot = { ...tag };
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.formData.item.tagIds];
            const tagIndex = this.tags?.findIndex(t => t.id === tag.id) ?? -1;

            try {
                // Track pending deletion
                this.deletingTagIds = this.deletingTagIds || new Set();
                this.deletingTagIds.add(tag.id);

                // Optimistic update - remove immediately
                if (this.tags && tagIndex !== -1) {
                    this.tags = this.tags.filter(t => t.id !== tag.id);
                }

                // Remove from selection if selected
                const selectedIndex = this.formData.item.tagIds?.indexOf(tag.id);
                if (selectedIndex !== undefined && selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                }

                // For temporary tags, no server call needed
                if (isTempTag) {
                    return;
                }

                // Call server for real tags
                const promise = $wire.$parent.$call('deleteTag', tag.id);

                // Handle response
                await promise;

            } catch (error) {
                // Rollback - restore tag
                if (tagIndex !== -1 && this.tags) {
                    this.tags.splice(tagIndex, 0, snapshot);
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }

                // Restore selection if it was selected
                if (tagIdsBackup.includes(tag.id) && !this.formData.item.tagIds.includes(tag.id)) {
                    this.formData.item.tagIds.push(tag.id);
                }

                $wire.dispatch('toast', { type: 'error', message: this.messages.tagError });
            } finally {
                // Always remove from pending set
                this.deletingTagIds?.delete(tag.id);
            }
        },
        async createTagOptimistic(tagNameFromEvent) {
            const tagName = (tagNameFromEvent != null && tagNameFromEvent !== '' ? String(tagNameFromEvent).trim() : (this.newTagName || '').trim());
            if (!tagName || this.creatingTag) {
                return;
            }

            this.newTagName = '';

            // If tag already exists in Alpine state (case-insensitive), select it and show toast (no server call)
            const tagNameLower = tagName.toLowerCase();
            const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
            if (existingTag && !String(existingTag.id).startsWith('temp-')) {
                if (!this.formData.item.tagIds) {
                    this.formData.item.tagIds = [];
                }
                const alreadySelected = this.formData.item.tagIds.some(id => String(id) === String(existingTag.id));
                if (!alreadySelected) {
                    this.formData.item.tagIds.push(existingTag.id);
                }
                $wire.dispatch('toast', { type: 'info', message: this.messages?.tagAlreadyExists || 'Tag already exists.' });
                return;
            }

            const tempId = `temp-${Date.now()}`;

            // Snapshot for rollback
            const tagsBackup = this.tags ? [...this.tags] : [];
            const tagIdsBackup = [...this.formData.item.tagIds];
            const newTagNameBackup = tagName;

            try {
                // Optimistic update - add tag immediately
                if (!this.tags) {
                    this.tags = [];
                }
                this.tags.push({ id: tempId, name: tagName });
                this.tags.sort((a, b) => a.name.localeCompare(b.name));

                // Auto-select the new tag
                if (!this.formData.item.tagIds.includes(tempId)) {
                    this.formData.item.tagIds.push(tempId);
                }

                this.creatingTag = true;

                // Call server
                const promise = $wire.$parent.$call('createTag', tagName);

                // Handle response - the tag-created event will update with real ID
                await promise;

            } catch (error) {
                // Rollback
                this.tags = tagsBackup;
                this.formData.item.tagIds = tagIdsBackup;
                this.newTagName = newTagNameBackup;

                $wire.dispatch('toast', { type: 'error', message: this.messages.tagError });
            } finally {
                this.creatingTag = false;
            }
        },
        submitTask() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.item.title || !this.formData.item.title.trim()) {
                return;
            }

            if (!this.validateDateRange()) {
                return;
            }

            this.isSubmitting = true;
            this.formData.item.title = this.formData.item.title.trim();

            this.showItemCreation = false;
            this.showItemLoading = true;
            this.loadingStartedAt = Date.now();

            const payload = JSON.parse(JSON.stringify(this.formData.item));
            // Split tag IDs: real IDs for payload.tagIds, temp IDs resolved by name for payload.pendingTagNames
            if (payload.tagIds && Array.isArray(payload.tagIds)) {
                const realIds = payload.tagIds
                    .filter(tagId => {
                        const idStr = String(tagId);
                        return !idStr.startsWith('temp-') && !isNaN(Number(tagId));
                    })
                    .map(tagId => Number(tagId));
                const tempIds = payload.tagIds.filter(tagId => String(tagId).startsWith('temp-'));
                const pendingNames = tempIds
                    .map(tempId => this.tags?.find(t => t.id === tempId)?.name)
                    .filter(Boolean);
                payload.tagIds = realIds;
                payload.pendingTagNames = [...new Set(pendingNames)];
            }
            if (!payload.pendingTagNames) {
                payload.pendingTagNames = [];
            }
            const minLoadingMs = 500;

            $wire.$parent.$call('createTask', payload)
                .finally(() => {
                    const elapsed = Date.now() - this.loadingStartedAt;
                    const remaining = Math.max(0, minLoadingMs - elapsed);
                    setTimeout(() => {
                        this.showItemLoading = false;
                        this.isSubmitting = false;
                    }, remaining);
                });
        },
        submitEvent() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.item.title || !this.formData.item.title.trim()) {
                return;
            }

            if (!this.validateDateRange()) {
                return;
            }

            this.isSubmitting = true;
            this.formData.item.title = this.formData.item.title.trim();

            this.showItemCreation = false;
            this.showItemLoading = true;
            this.loadingStartedAt = Date.now();

            const payload = JSON.parse(JSON.stringify(this.formData.item));
            // Split tag IDs: real IDs for payload.tagIds, temp IDs resolved by name for payload.pendingTagNames
            if (payload.tagIds && Array.isArray(payload.tagIds)) {
                const realIds = payload.tagIds
                    .filter(tagId => {
                        const idStr = String(tagId);
                        return !idStr.startsWith('temp-') && !isNaN(Number(tagId));
                    })
                    .map(tagId => Number(tagId));
                const tempIds = payload.tagIds.filter(tagId => String(tagId).startsWith('temp-'));
                const pendingNames = tempIds
                    .map(tempId => this.tags?.find(t => t.id === tempId)?.name)
                    .filter(Boolean);
                payload.tagIds = realIds;
                payload.pendingTagNames = [...new Set(pendingNames)];
            }
            if (!payload.pendingTagNames) {
                payload.pendingTagNames = [];
            }
            const minLoadingMs = 500;

            $wire.$parent.$call('createEvent', payload)
                .finally(() => {
                    const elapsed = Date.now() - this.loadingStartedAt;
                    const remaining = Math.max(0, minLoadingMs - elapsed);
                    setTimeout(() => {
                        this.showItemLoading = false;
                        this.isSubmitting = false;
                    }, remaining);
                });
        },
        formatDatetime(datetimeString) {
            const notSet = 'Not set';
            if (!datetimeString) return notSet;
            try {
                const date = new Date(datetimeString);
                if (isNaN(date.getTime())) return notSet;
                const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                return dateStr + ' ' + timeStr;
            } catch (e) {
                return notSet;
            }
        },
        statusLabel(status) {
            switch (status) {
                case 'to_do':
                    return '{{ __('To Do') }}';
                case 'doing':
                    return '{{ __('Doing') }}';
                case 'done':
                    return '{{ __('Done') }}';
                default:
                    return '{{ __('To Do') }}';
            }
        },
        eventStatusLabel(status) {
            switch (status) {
                case 'scheduled':
                    return '{{ __('Scheduled') }}';
                case 'ongoing':
                    return '{{ __('Ongoing') }}';
                case 'tentative':
                    return '{{ __('Tentative') }}';
                case 'completed':
                    return '{{ __('Completed') }}';
                case 'cancelled':
                    return '{{ __('Cancelled') }}';
                default:
                    return '{{ __('Scheduled') }}';
            }
        },
        priorityLabel(priority) {
            switch (priority) {
                case 'low':
                    return '{{ __('Low') }}';
                case 'medium':
                    return '{{ __('Medium') }}';
                case 'high':
                    return '{{ __('High') }}';
                case 'urgent':
                    return '{{ __('Urgent') }}';
                default:
                    return '{{ __('Medium') }}';
            }
        },
        complexityLabel(complexity) {
            switch (complexity) {
                case 'simple':
                    return '{{ __('Simple') }}';
                case 'moderate':
                    return '{{ __('Moderate') }}';
                case 'complex':
                    return '{{ __('Complex') }}';
                default:
                    return '{{ __('Moderate') }}';
            }
        },
        recurrenceLabel(recurrence) {
            if (!recurrence?.enabled || !recurrence?.type) return '';
            const labels = { daily: 'DAILY', weekly: 'WEEKLY', monthly: 'MONTHLY', yearly: 'YEARLY' };
            const dayDisplayLabels = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
            if (recurrence.type === 'weekly' && Array.isArray(recurrence.daysOfWeek) && recurrence.daysOfWeek.length > 0) {
                const dayNames = recurrence.daysOfWeek.map(d => dayDisplayLabels[d]).join(', ');
                const intervalPart = (recurrence.interval ?? 1) === 1 ? 'WEEKLY' : `EVERY ${recurrence.interval} WEEKS`;
                return `${intervalPart} (${dayNames})`;
            }
            if ((recurrence.interval ?? 1) === 1) return labels[recurrence.type] || recurrence.type;
            const typePlural = { daily: 'DAYS', weekly: 'WEEKS', monthly: 'MONTHS', yearly: 'YEARS' }[recurrence.type] || '';
            return typePlural ? `EVERY ${recurrence.interval} ${typePlural}` : (labels[recurrence.type] || recurrence.type);
        },
        formatDurationLabel(duration) {
            const value = String(duration ?? '');

            switch (value) {
                case '15':
                    return '15 {{ __('min') }}';
                case '30':
                    return '30 {{ __('min') }}';
                case '60':
                    return '1 {{ __('hour') }}';
                case '90':
                    return '1.5 {{ \Illuminate\Support\Str::plural(__('hour'), 2) }}';
                case '120':
                    return '2 {{ \Illuminate\Support\Str::plural(__('hour'), 2) }}';
                case '180':
                    return '3 {{ \Illuminate\Support\Str::plural(__('hour'), 3) }}';
                case '240':
                    return '4 {{ \Illuminate\Support\Str::plural(__('hour'), 4) }}';
                case '480':
                    return '8+ {{ \Illuminate\Support\Str::plural(__('hour'), 8) }}';
                default:
                    return '{{ __('Not set') }}';
            }
        },
        getStatusBadgeClass(status) {
            const map = { to_do: 'bg-gray-800/10 text-gray-800', doing: 'bg-blue-800/10 text-blue-800', done: 'bg-green-800/10 text-green-800' };
            return map[status] || map.to_do;
        },
        getEventStatusBadgeClass(status) {
            const map = {
                scheduled: 'bg-blue-800/10 text-blue-800',
                cancelled: 'bg-red-800/10 text-red-800',
                completed: 'bg-green-800/10 text-green-800',
                tentative: 'bg-yellow-800/10 text-yellow-800',
                ongoing: 'bg-purple-800/10 text-purple-800',
            };
            return map[status] || map.scheduled;
        },
        getPriorityBadgeClass(priority) {
            const map = { low: 'bg-gray-800/10 text-gray-800', medium: 'bg-yellow-800/10 text-yellow-800', high: 'bg-orange-800/10 text-orange-800', urgent: 'bg-red-800/10 text-red-800' };
            return map[priority] || map.medium;
        },
        getComplexityBadgeClass(complexity) {
            const map = { simple: 'bg-green-800/10 text-green-800', moderate: 'bg-yellow-800/10 text-yellow-800', complex: 'bg-red-800/10 text-red-800' };
            return map[complexity] || map.moderate;
        },
        getRecurrenceBadgeClass(type) {
            const map = { daily: 'bg-blue-800/10 text-blue-800', weekly: 'bg-purple-800/10 text-purple-800', monthly: 'bg-indigo-800/10 text-indigo-800', yearly: 'bg-pink-800/10 text-pink-800' };
            return map[type] || 'bg-gray-800/10 text-gray-800';
        },
        setFormDataByPath(path, value) {
            const pathParts = path.split('.');
            let target = this;
            for (let i = 0; i < pathParts.length - 1; i++) {
                if (!target[pathParts[i]]) {
                    target[pathParts[i]] = {};
                }
                target = target[pathParts[i]];
            }
            target[pathParts[pathParts.length - 1]] = value;
            if (typeof this.validateDateRange === 'function') {
                this.validateDateRange();
            }
        },
        onTagCreated(event) {
            const { id, name } = event.detail;

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
            } else {
                if (this.tags && !this.tags.find(tag => tag.id === id)) {
                    this.tags.push({ id, name });
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }
            }
        },
        onTagDeleted(event) {
            const { id } = event.detail;

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
                }
            }
        },
    }"
    @task-created="resetForm()"
    @event-created="resetForm()"
    @tag-created.window="onTagCreated($event)"
    @tag-deleted.window="onTagDeleted($event)"
    @date-picker-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @recurring-selection-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @item-form-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @tag-toggled="toggleTag($event.detail.tagId)"
    @tag-create-request="createTagOptimistic($event.detail.tagName)"
    @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
    x-effect="
        formData.item.startDatetime;
        formData.item.endDatetime;
        creationKind === 'task' ? formData.item.duration : null;
        validateDateRange();
    "
>
    @php
        $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
    @endphp
    <div class="relative z-10">
        <flux:dropdown position="right" align="start">
            <flux:button icon:trailing="plus-circle" data-item-creation-safe>
                {{ __('Add') }}
            </flux:button>

            <flux:menu>
                <flux:menu.item
                    icon="rectangle-stack"
                    @click="
                        if (showItemCreation && creationKind === 'task') {
                            showItemCreation = false;
                        } else {
                            creationKind = 'task';
                            formData.item.status = 'to_do';
                            formData.item.priority = 'medium';
                            formData.item.complexity = 'moderate';
                            formData.item.duration = '60';
                            formData.item.allDay = false;
                            formData.item.projectId = null;
                            showItemCreation = true;
                            $nextTick(() => $refs.taskTitle?.focus());
                        }
                    "
                >
                    {{ __('Task') }}
                </flux:menu.item>
                <flux:menu.item
                    icon="calendar-days"
                    @click="
                        if (showItemCreation && creationKind === 'event') {
                            showItemCreation = false;
                        } else {
                            creationKind = 'event';
                            formData.item.status = 'scheduled';
                            formData.item.allDay = false;
                            showItemCreation = true;
                            $nextTick(() => $refs.taskTitle?.focus());
                        }
                    "
                >
                    {{ __('Event') }}
                </flux:menu.item>
                <flux:menu.item variant="danger" icon="clipboard-document-list">
                    {{ __('Project') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        <div
            x-show="showItemCreation"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-[0.98]"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-[0.98]"
            x-ref="taskCreationCard"
            @click.outside="
                const target = $event.target;
                const isSafe = target.closest('[data-item-creation-safe]');
                // Also treat Flux dropdown trigger/panel as safe so switching
                // between Task and Event from the Add menu does not close the card.
                const isDropdownContext =
                    target.closest('.absolute.z-50') ||
                    target.closest('[data-flux-dropdown]') ||
                    target.closest('[data-flux-menu]');

                if (!isSafe && !isDropdownContext) {
                    showItemCreation = false;
                }
            "
            class="relative mt-4 flex flex-col gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3 shadow-md ring-1 ring-border/20"
            x-cloak
        >
            <div class="flex items-start justify-between gap-3">
                <form
                    class="min-w-0 flex-1"
                    @submit.prevent="creationKind === 'task' ? submitTask() : submitEvent()"
                >
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <x-recurring-selection
                                compactWhenDisabled
                                position="top"
                                align="end"
                            />
                            <span
                                class="inline-flex w-fit items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                                x-show="creationKind === 'task'"
                                x-cloak
                            >
                                {{ __('Task') }}
                            </span>
                            <span
                                class="inline-flex w-fit items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                                x-show="creationKind === 'event'"
                                x-cloak
                            >
                                {{ __('Event') }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:input
                                x-model="formData.item.title"
                                x-ref="taskTitle"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Enter title...') }}"
                                class="flex-1 text-sm font-medium"
                                @keydown.enter.prevent="if (!isSubmitting && formData.item.title && formData.item.title.trim()) { creationKind === 'task' ? submitTask() : submitEvent(); }"
                            />

                            <flux:button
                                type="button"
                                variant="primary"
                                icon="paper-airplane"
                                class="shrink-0 rounded-full"
                                x-bind:disabled="isSubmitting || !formData.item.title || !formData.item.title.trim()"
                                @click="creationKind === 'task' ? submitTask() : submitEvent()"
                            />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-workspace.creation-task-fields />
                            <x-workspace.creation-event-fields />

                            @foreach ([['label' => __('Start'), 'model' => 'formData.item.startDatetime', 'datePickerLabel' => __('Start Date')], ['label' => __('End'), 'model' => 'formData.item.endDatetime', 'datePickerLabel' => __('End Date')]] as $dateField)
                                <x-date-picker
                                    :triggerLabel="$dateField['label']"
                                    :label="$dateField['datePickerLabel']"
                                    :model="$dateField['model']"
                                    type="datetime-local"
                                    position="bottom"
                                    align="end"
                                />
                            @endforeach

                            <div class="flex w-full items-center gap-1.5" x-show="errors.dateRange" x-cloak>
                                <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
                                <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="errors.dateRange"></p>
                            </div>
                        </div>

                        <div class="w-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]">
                            <span class="inline-flex shrink-0 items-center gap-1 font-semibold uppercase tracking-wide text-muted-foreground">
                                <flux:icon name="tag" class="size-3" />
                                {{ __('Tags') }}:
                            </span>
                            <x-workspace.tag-selection position="bottom" align="end" />
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div
        x-show="showItemLoading"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-[0.98]"
        x-transition:enter-end="opacity-100 scale-100"
        data-test="task-loading-card"
        class="mt-4 flex flex-col overflow-hidden rounded-xl border border-border/60 bg-background/60 shadow-sm backdrop-blur opacity-60"
    >
        <div
            class="relative h-px w-full overflow-hidden bg-zinc-300 dark:bg-zinc-600"
            aria-hidden="true"
        >
            <div class="loading-bar-track absolute left-0 top-0 h-full w-1/3 max-w-[120px] rounded-full bg-zinc-500 dark:bg-zinc-400"></div>
        </div>
        <div class="flex flex-col gap-2 px-3 pt-3 pb-2">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <p class="truncate text-lg font-semibold leading-tight" x-text="formData.item.title"></p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    class="cursor-default inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-medium transition-[box-shadow,transform] duration-150 ease-out"
                    :class="(formData.item.recurrence?.enabled && formData.item.recurrence?.type) ? 'border-indigo-500/25 bg-indigo-500/10 text-indigo-700 shadow-sm dark:text-indigo-300 dark:border-indigo-400/25' : 'border-border/60 bg-muted text-muted-foreground'"
                >
                    <flux:icon name="arrow-path" class="size-3" />
                    <span x-show="formData.item.recurrence?.enabled && formData.item.recurrence?.type" class="inline-flex items-baseline gap-1" x-cloak>
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Repeats') }}:</span>
                        <span class="text-xs" x-text="recurrenceLabel(formData.item.recurrence)"></span>
                    </span>
                    <flux:icon name="chevron-down" class="size-3" x-show="formData.item.recurrence?.enabled && formData.item.recurrence?.type" x-cloak></flux:icon>
                </span>
                <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    <span x-text="creationKind === 'task' ? '{{ __('Task') }}' : '{{ __('Event') }}'"></span>
                </span>
                <flux:button size="xs" icon="ellipsis-horizontal" disabled class="pointer-events-none opacity-70" />
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
            <template x-if="creationKind === 'task'">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                        :class="getStatusBadgeClass(formData.item.status)"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Status') }}:</span>
                            <span class="uppercase" x-text="statusLabel(formData.item.status)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                        :class="getPriorityBadgeClass(formData.item.priority)"
                    >
                        <flux:icon name="bolt" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Priority') }}:</span>
                            <span class="uppercase" x-text="priorityLabel(formData.item.priority)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                        :class="getComplexityBadgeClass(formData.item.complexity)"
                    >
                        <flux:icon name="squares-2x2" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Complexity') }}:</span>
                            <span class="uppercase" x-text="complexityLabel(formData.item.complexity)"></span>
                        </span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                        <flux:icon name="clock" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Duration') }}:</span>
                            <span class="uppercase" x-text="formatDurationLabel(formData.item.duration)"></span>
                        </span>
                    </span>
                </div>
            </template>
            <template x-if="creationKind === 'event'">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                        :class="getEventStatusBadgeClass(formData.item.status)"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Status') }}:</span>
                            <span class="uppercase" x-text="eventStatusLabel(formData.item.status)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                        :class="formData.item.allDay ? 'bg-emerald-500/10 text-emerald-500 shadow-sm' : 'bg-muted text-muted-foreground'"
                    >
                        <flux:icon name="sun" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('All Day') }}:</span>
                            <span class="uppercase" x-text="formData.item.allDay ? '{{ __('Yes') }}' : '{{ __('No') }}'"></span>
                        </span>
                    </span>
                </div>
            </template>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Start') }}:</span>
                    <span class="text-xs uppercase" x-text="formData.item.startDatetime ? formatDatetime(formData.item.startDatetime) : '{{ __('Not set') }}'"></span>
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70" x-text="creationKind === 'task' ? '{{ __('Due') }}:' : '{{ __('End') }}:'"></span>
                    <span class="text-xs uppercase" x-text="formData.item.endDatetime ? formatDatetime(formData.item.endDatetime) : '{{ __('Not set') }}'"></span>
                </span>
            </span>
            <span
                x-show="formData.item.projectId && projectNames[formData.item.projectId]"
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10"
            >
                <flux:icon name="folder" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Project') }}:</span>
                    <span class="truncate max-w-[120px] uppercase" x-text="projectNames[formData.item.projectId] || ''"></span>
                </span>
            </span>
        </div>
        <div class="flex w-full shrink-0 flex-wrap items-center gap-2 border-t border-border/50 pt-1.5 mt-1 text-[10px]">
            <span class="inline-flex shrink-0 items-center gap-1 font-semibold uppercase tracking-wide text-muted-foreground">
                <flux:icon name="tag" class="size-3" />
                {{ __('Tags') }}:
            </span>
            <template x-for="tag in getSelectedTags()" :key="tag.id">
                <span class="inline-flex items-center rounded-sm border border-black/10 px-2.5 py-1 text-xs font-medium dark:border-white/10 bg-muted text-muted-foreground" x-text="tag.name"></span>
            </template>
            <span
                x-show="!(formData.item.tagIds && formData.item.tagIds.length > 0)"
                class="inline-flex items-center rounded-sm border border-border/60 bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
            >{{ __('None') }}</span>
        </div>
        </div>
    </div>

    @php
        $date = $selectedDate ? \Illuminate\Support\Carbon::parse($selectedDate) : now();
        $emptyDateLabel = $date->isToday()
            ? __('today')
            : ($date->isTomorrow()
                ? __('tomorrow')
                : ($date->isYesterday()
                    ? __('yesterday')
                    : $date->translatedFormat('l, F j, Y')));

        $items = collect()
            ->merge($projects->map(fn ($item) => ['kind' => 'project', 'item' => $item]))
            ->merge($events->map(fn ($item) => ['kind' => 'event', 'item' => $item]))
            ->merge($tasks->map(fn ($item) => ['kind' => 'task', 'item' => $item]))
            ->sortByDesc(fn (array $entry) => $entry['item']->created_at)
            ->values();

        $totalItemsCount = $items->count();
    @endphp
    @if($items->isEmpty() && $overdue->isEmpty())
        <div class="mt-6 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur">
            <div class="flex items-center gap-2">
                <flux:icon name="calendar-days" class="size-5 text-muted-foreground/50" />
                <flux:text class="text-sm font-medium text-muted-foreground">
                    {{ __('No tasks, projects, or events for :date', ['date' => $emptyDateLabel]) }}
                </flux:text>
            </div>
            <flux:text class="text-xs text-muted-foreground/70">
                {{ __('Add a task, project, or event for this day to get started') }}
            </flux:text>
        </div>
    @else
        <div
            class="space-y-4"
            x-data="{
                visibleItemCount: {{ $totalItemsCount }},
                overdueCount: {{ $overdue->count() }},
                showEmptyState: false,
                emptyStateTimeout: null,
                init() {
                    this.$watch('visibleItemCount', () => this.syncEmptyState());
                    this.$watch('overdueCount', () => this.syncEmptyState());
                    this.syncEmptyState();
                },
                syncEmptyState() {
                    const shouldBeEmpty = this.visibleItemCount === 0 && this.overdueCount === 0;
                    if (shouldBeEmpty) {
                        if (this.emptyStateTimeout) return;
                        this.emptyStateTimeout = setTimeout(() => {
                            this.showEmptyState = true;
                            this.emptyStateTimeout = null;
                        }, 200);
                    } else {
                        if (this.emptyStateTimeout) {
                            clearTimeout(this.emptyStateTimeout);
                            this.emptyStateTimeout = null;
                        }
                        this.showEmptyState = false;
                    }
                },
                handleListItemHidden(e) {
                    const fromOverdue = e.detail?.fromOverdue ?? false;
                    const requestRefresh = e.detail?.requestRefresh ?? false;
                    if (fromOverdue) {
                        this.overdueCount--;
                    } else {
                        this.visibleItemCount--;
                    }
                    if (requestRefresh) {
                        const delay = fromOverdue && this.overdueCount === 0 ? 150 : 0;
                        setTimeout(() => $dispatch('list-refresh-requested'), delay);
                    }
                },
                handleListItemShown(e) {
                    if (e.detail?.fromOverdue) {
                        this.overdueCount++;
                    } else {
                        this.visibleItemCount++;
                    }
                }
            }"
            @list-item-hidden.window="handleListItemHidden($event)"
            @list-item-shown.window="handleListItemShown($event)"
        >
            @if($overdue->isNotEmpty())
            <div
                x-show="overdueCount > 0"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-[0.98]"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-[0.98]"
                class="mt-4 space-y-3 border-y border-red-500/40 dark:border-red-400/30 py-4"
                data-test="overdue-container"
            >
                <div class="flex flex-col gap-0.5">
                    <div class="flex items-center gap-2">
                        <flux:icon name="exclamation-triangle" class="size-5 text-red-500/70 dark:text-red-400/70" />
                        <flux:text class="text-sm font-medium text-red-700/90 dark:text-red-400/90">
                            {{ __('Overdue') }}
                        </flux:text>
                    </div>
                    <flux:text class="text-xs text-muted-foreground/70">
                        {{ __('Tasks and events past their due date') }}
                    </flux:text>
                </div>
                <div class="space-y-3">
                    @foreach ($overdue as $entry)
                        <x-workspace.list-item-card
                            :kind="$entry['kind']"
                            :item="$entry['item']"
                            :list-filter-date="null"
                            :available-tags="$tags"
                            :is-overdue="true"
                            wire:key="overdue-{{ $entry['kind'] }}-{{ $entry['item']->id }}"
                        />
                    @endforeach
                </div>
            </div>
            @endif
            <div
                x-show="showEmptyState"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-[0.98]"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-[0.98]"
                class="mt-6 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur"
            >
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar-days" class="size-5 text-muted-foreground/50" />
                    <flux:text class="text-sm font-medium text-muted-foreground">
                        {{ __('No tasks, projects, or events are currently visible for :date', ['date' => $emptyDateLabel]) }}
                    </flux:text>
                </div>
                <flux:text class="text-xs text-muted-foreground/70">
                    {{ __('Try adjusting item dates or filters, or add a new task, project, or event for this day') }}
                </flux:text>
            </div>
            <div x-show="visibleItemCount > 0" class="space-y-4">
                <div class="space-y-3">
                    @foreach ($items as $entry)
                        <x-workspace.list-item-card
                            :kind="$entry['kind']"
                            :item="$entry['item']"
                            :list-filter-date="$selectedDate"
                            :available-tags="$tags"
                            wire:key="{{ $entry['kind'] }}-{{ $entry['item']->id }}"
                        />
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
