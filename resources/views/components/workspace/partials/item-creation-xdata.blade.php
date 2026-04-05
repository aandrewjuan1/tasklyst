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
                duration: null,
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
            project: {
                name: '',
                description: null,
                startDatetime: null,
                endDatetime: null,
            },
        },
        validateDateRange() {
            this.errors.dateRange = null;

            const dates = this.creationKind === 'project'
                ? { start: this.formData.project.startDatetime, end: this.formData.project.endDatetime }
                : { start: this.formData.item.startDatetime, end: this.formData.item.endDatetime };
            const start = dates.start;
            const end = dates.end;

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
                this.formData.item.duration = null;
                this.formData.item.allDay = false;
                this.formData.item.projectId = null;
            } else if (this.creationKind === 'event') {
                this.formData.item.status = 'scheduled';
                this.formData.item.allDay = false;
                // Events don't have priority, complexity, duration, or projectId
            } else if (this.creationKind === 'project') {
                this.formData.project.name = '';
                this.formData.project.description = null;
                this.formData.project.startDatetime = null;
                this.formData.project.endDatetime = null;
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
            const tagIndex = this.tags?.findIndex(t => String(t.id) === String(tag.id)) ?? -1;

            try {
                // Track pending deletion
                this.deletingTagIds = this.deletingTagIds || new Set();
                this.deletingTagIds.add(tag.id);

                // Optimistic update - remove immediately
                if (this.tags && tagIndex !== -1) {
                    this.tags = this.tags.filter(t => String(t.id) !== String(tag.id));
                }

                // Remove from selection if selected
                const selectedIndex = Array.isArray(this.formData.item.tagIds)
                    ? this.formData.item.tagIds.findIndex(id => String(id) === String(tag.id))
                    : -1;
                if (selectedIndex !== -1) {
                    this.formData.item.tagIds.splice(selectedIndex, 1);
                }

                // For temporary tags, no server call needed
                if (isTempTag) {
                    return;
                }

                // Call server for real tags
                const promise = window.workspaceShellWire($wire).$call('deleteTag', tag.id);

                // Handle response
                await promise;

            } catch (error) {
                // Rollback - restore tag
                if (tagIndex !== -1 && this.tags) {
                    this.tags.splice(tagIndex, 0, snapshot);
                    this.tags.sort((a, b) => a.name.localeCompare(b.name));
                }

                // Restore selection if it was selected
                const wasSelected = tagIdsBackup.some(id => String(id) === String(tag.id));
                const isCurrentlySelected = Array.isArray(this.formData.item.tagIds)
                    ? this.formData.item.tagIds.some(id => String(id) === String(tag.id))
                    : false;
                if (wasSelected && !isCurrentlySelected) {
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
                const promise = window.workspaceShellWire($wire).$call('createTag', tagName);

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

            window.workspaceShellWire($wire).$call('createTask', payload)
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

            window.workspaceShellWire($wire).$call('createEvent', payload)
                .finally(() => {
                    const elapsed = Date.now() - this.loadingStartedAt;
                    const remaining = Math.max(0, minLoadingMs - elapsed);
                    setTimeout(() => {
                        this.showItemLoading = false;
                        this.isSubmitting = false;
                    }, remaining);
                });
        },
        submitProject() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.project.name || !this.formData.project.name.trim()) {
                return;
            }

            if (!this.validateDateRange()) {
                return;
            }

            this.isSubmitting = true;
            this.formData.project.name = this.formData.project.name.trim();

            this.showItemCreation = false;
            this.showItemLoading = true;
            this.loadingStartedAt = Date.now();

            const payload = {
                name: this.formData.project.name,
                description: this.formData.project.description || null,
                startDatetime: this.formData.project.startDatetime || null,
                endDatetime: this.formData.project.endDatetime || null,
            };
            const minLoadingMs = 500;

            window.workspaceShellWire($wire).$call('createProject', payload)
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
                    return '8 {{ \Illuminate\Support\Str::plural(__('hour'), 8) }}';
                default: {
                    const m = Number(duration);
                    if (!Number.isFinite(m) || m <= 0) {
                        return '{{ __('Not set') }}';
                    }
                    if (m < 60) {
                        return m + ' {{ __('min') }}';
                    }
                    const hours = Math.floor(m / 60);
                    const remainder = m % 60;
                    const hourWord = hours === 1
                        ? '{{ __('hour') }}'
                        : '{{ \Illuminate\Support\Str::plural(__('hour'), 2) }}';
                    let label = hours + ' ' + hourWord;
                    if (remainder > 0) {
                        label += ' ' + remainder + ' {{ __('min') }}';
                    }
                    return label;
                }
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
