        mode: @js($mode ?? 'list'),
        visibleItemCount: {{ (int) ($visibleItemsInitial ?? 0) }},
        showItemCreation: false,
        itemTypePickerOpen: false,
        creationKind: 'task',
        showItemLoading: false,
        loadingStartedAt: null,
        isSubmitting: false,
        messages: {
            taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
            taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
            schoolClassEndBeforeStart: @js(__('End time must be after the start time.')),
            schoolClassNeedScheduleDates: @js(__('Choose schedule start and end dates.')),
            schoolClassNeedMeetingDate: @js(__('Choose the meeting date.')),
            schoolClassNeedWeekdays: @js(__('Select at least one weekday for a weekly class.')),
            tagAlreadyExists: @js(__('Tag already exists.')),
            tagError: @js(__('Something went wrong. Please try again.')),
            teacherAlreadyExists: @js(__('Teacher already exists.')),
            teacherError: @js(__('Something went wrong. Please try again.')),
        },
        errors: {
            dateRange: null,
        },
        tags: @js($tags),
        teachers: @js($teachers),
        projectNames: @js($projects->pluck('name', 'id')->toArray()),
        init() {
            this.$watch('showItemCreation', (value) => {
                if (! value || this.showItemLoading) {
                    return;
                }

                this.scheduleFocusCreationTitle();
            });
        },
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
            schoolClass: {
                scheduleMode: 'recurring',
                subjectName: '',
                teacherId: null,
                teacherName: '',
                scheduleStartDate: null,
                scheduleEndDate: null,
                meetingDate: null,
                startTime: null,
                endTime: null,
                recurrence: {
                    enabled: true,
                    type: 'weekly',
                    interval: 1,
                    daysOfWeek: [],
                },
            },
        },
        validateDateRange() {
            this.errors.dateRange = null;

            if (this.creationKind === 'schoolClass') {
                return this.validateSchoolClassSchedule();
            }

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
        validateSchoolClassSchedule() {
            const sc = this.formData.schoolClass;
            if (!sc.startTime || !sc.endTime) {
                return true;
            }
            if (sc.startTime >= sc.endTime) {
                this.errors.dateRange = this.messages.schoolClassEndBeforeStart;

                return false;
            }
            if (sc.scheduleMode === 'recurring') {
                if (sc.scheduleStartDate && sc.scheduleEndDate) {
                    const a = new Date(sc.scheduleStartDate);
                    const b = new Date(sc.scheduleEndDate);
                    if (!Number.isNaN(a.getTime()) && !Number.isNaN(b.getTime()) && b.getTime() < a.getTime()) {
                        this.errors.dateRange = this.messages.taskEndBeforeStart;

                        return false;
                    }
                }
            }

            return true;
        },
        schoolClassCanSubmit() {
            const sc = this.formData.schoolClass;
            if (!sc?.subjectName?.trim() || !String(sc.teacherName || '').trim() || !sc.startTime || !sc.endTime) {
                return false;
            }
            if (sc.scheduleMode === 'recurring') {
                if (sc.recurrence?.enabled !== true || !sc.recurrence?.type) {
                    return false;
                }
                const t = sc.recurrence?.type;
                const dow = sc.recurrence?.daysOfWeek;
                if (t === 'weekly' && (!Array.isArray(dow) || dow.length === 0)) {
                    return false;
                }
            } else if (sc.scheduleMode === 'one_off' && !sc.meetingDate) {
                return false;
            }

            return true;
        },
        schoolClassLoadingScheduleLabel() {
            const sc = this.formData.schoolClass;
            if (sc.scheduleMode === 'one_off') {
                return sc.meetingDate
                    ? `${sc.meetingDate} · ${sc.startTime ?? ''}–${sc.endTime ?? ''}`
                    : '{{ __('Not set') }}';
            }

            return sc.scheduleStartDate && sc.scheduleEndDate
                ? `${sc.scheduleStartDate} → ${sc.scheduleEndDate} · ${sc.startTime ?? ''}–${sc.endTime ?? ''}`
                : '{{ __('Not set') }}';
        },
        clearSchoolClassMeetingDateForRecurringChoice() {
            if (!this.formData?.schoolClass) {
                return;
            }
            this.formData.schoolClass.meetingDate = null;
            window.dispatchEvent(
                new CustomEvent('date-picker-value', {
                    bubbles: true,
                    detail: { path: 'formData.schoolClass.meetingDate', value: null },
                }),
            );
        },
        clearSchoolClassRecurrenceForOneOffChoice() {
            if (!this.formData?.schoolClass) {
                return;
            }

            this.formData.schoolClass.recurrence = { enabled: false, type: null, interval: 1, daysOfWeek: [] };

            window.dispatchEvent(
                new CustomEvent('recurring-value', {
                    bubbles: true,
                    detail: { path: 'formData.schoolClass.recurrence', value: this.formData.schoolClass.recurrence },
                }),
            );
        },
        onDatePickerUpdated(event) {
            const d = event?.detail || {};
            const path = d.path;
            const value = d.value;

            this.setFormDataByPath(path, value);

            if (this.creationKind !== 'schoolClass' || path !== 'formData.schoolClass.meetingDate') {
                return;
            }

            if (value) {
                this.formData.schoolClass.scheduleMode = 'one_off';
                this.clearSchoolClassRecurrenceForOneOffChoice();
            }
        },
        creationCardSurfaceClass() {
            if (this.creationKind === 'project') {
                return 'lic-surface-project';
            }
            if (this.creationKind === 'schoolClass') {
                return 'lic-surface-school-class';
            }
            if (this.creationKind === 'event') {
                return 'lic-surface-event';
            }
            const status = this.formData.item.status;
            if (status === 'doing') {
                return 'lic-surface-task-doing';
            }
            if (status === 'done') {
                return 'lic-surface-task-done';
            }

            return 'lic-surface-task-todo';
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
            this.newTeacherName = '';
            this.teacherPopoverOpen = false;
            this.classHoursPopoverOpen = false;
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

            this.formData.schoolClass = {
                scheduleMode: 'recurring',
                subjectName: '',
                teacherId: null,
                teacherName: '',
                scheduleStartDate: null,
                scheduleEndDate: null,
                meetingDate: null,
                startTime: null,
                endTime: null,
                recurrence: {
                    enabled: true,
                    type: 'weekly',
                    interval: 1,
                    daysOfWeek: [],
                },
            };
        },
        scheduleFocusCreationTitle() {
            const resolveInput = (root) => {
                if (!root) {
                    return null;
                }

                if (root.matches?.('input:not([type=hidden])')) {
                    return root;
                }

                return (
                    root.querySelector?.('input:not([type=hidden])') ??
                    root.querySelector?.('input') ??
                    root.shadowRoot?.querySelector?.('input:not([type=hidden])') ??
                    root.shadowRoot?.querySelector?.('input')
                );
            };

            const firstVisibleTextInput = (container) => {
                if (! container) {
                    return null;
                }

                const candidates = container.querySelectorAll(
                    'input:not([type=hidden]):not([disabled])',
                );

                for (const candidate of candidates) {
                    if (candidate.type === 'hidden') {
                        continue;
                    }

                    if (candidate.offsetParent === null) {
                        continue;
                    }

                    return candidate;
                }

                return null;
            };

            const tryFocus = () => {
                const root =
                    this.creationKind === 'project'
                        ? this.$refs.projectName
                        : this.creationKind === 'schoolClass'
                            ? this.$refs.schoolClassSubject
                            : this.$refs.taskTitle;
                let input = resolveInput(root);

                if (
                    input &&
                    input.offsetParent === null
                ) {
                    input = null;
                }

                if (! input && this.$refs.taskCreationCard) {
                    input = firstVisibleTextInput(this.$refs.taskCreationCard);
                }

                if (input && ! input.disabled) {
                    input.focus();

                    return true;
                }

                return false;
            };

            const schedule = (fn) => {
                this.$nextTick(fn);
            };

            schedule(() => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        if (tryFocus()) {
                            return;
                        }

                        let remaining = 24;

                        const retry = () => {
                            if (tryFocus() || remaining <= 0) {
                                return;
                            }

                            remaining -= 1;
                            requestAnimationFrame(retry);
                        };

                        retry();
                        window.setTimeout(() => tryFocus(), 0);
                        window.setTimeout(() => tryFocus(), 50);
                        window.setTimeout(() => tryFocus(), 220);
                    });
                });
            });
        },
        onPlusToolbarClick() {
            if (this.showItemCreation || this.showItemLoading) {
                return;
            }

            this.itemTypePickerOpen = !this.itemTypePickerOpen;
        },
        beginItemCreation(kind) {
            this.itemTypePickerOpen = false;

            const isToggleClose =
                this.showItemCreation &&
                ((kind === 'task' && this.creationKind === 'task') ||
                    (kind === 'event' && this.creationKind === 'event') ||
                    (kind === 'project' && this.creationKind === 'project') ||
                    (kind === 'schoolClass' && this.creationKind === 'schoolClass'));

            if (isToggleClose) {
                this.showItemCreation = false;

                return;
            }

            if (kind === 'task') {
                this.creationKind = 'task';
                this.formData.item.status = 'to_do';
                this.formData.item.priority = 'medium';
                this.formData.item.complexity = 'moderate';
                this.formData.item.duration = null;
                this.formData.item.allDay = false;
                this.formData.item.projectId = null;
                this.showItemCreation = true;

                return;
            }

            if (kind === 'event') {
                this.creationKind = 'event';
                this.formData.item.status = 'scheduled';
                this.formData.item.allDay = false;
                this.showItemCreation = true;

                return;
            }

            if (kind === 'project') {
                this.creationKind = 'project';
                this.showItemCreation = true;

                return;
            }

            if (kind === 'schoolClass') {
                this.creationKind = 'schoolClass';
                this.showItemCreation = true;
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
        mergedSchoolClassTeachers() {
            const available = Array.isArray(this.teachers) ? [...this.teachers] : [];
            const tid = this.formData?.schoolClass?.teacherId;
            const tname = String(this.formData?.schoolClass?.teacherName || '').trim();
            const merged = [...available].filter(t => t && t.id != null && String(t.name || '').trim() !== '');

            if (tid != null && tname !== '') {
                const hasId = merged.some(t => String(t.id) === String(tid));
                if (!hasId) {
                    merged.push({ id: tid, name: tname });
                }
            }

            const byId = new Map();
            for (const t of merged) {
                const key = String(t.id);
                if (!byId.has(key)) {
                    byId.set(key, { id: t.id, name: String(t.name || '').trim() });
                }
            }

            return Array.from(byId.values()).sort((a, b) =>
                String(a.name || '').localeCompare(String(b.name || '')),
            );
        },
        isSchoolClassTeacherSelected(teacherId) {
            const selected = this.formData?.schoolClass?.teacherId;
            if (selected == null || teacherId == null) {
                return false;
            }

            return String(selected) === String(teacherId);
        },
        schoolClassTeacherTriggerLabel() {
            const name = String(this.formData?.schoolClass?.teacherName || '').trim();

            return name || '';
        },
        teacherPopoverOpen: false,
        teacherDeleteMode: false,
        teacherPopoverPlacementVertical: 'bottom',
        teacherPopoverPlacementHorizontal: 'end',
        teacherPopoverPanelHeightEst: 240,
        teacherPopoverPanelWidthEst: 260,
        toggleTeacherDeleteMode() {
            this.teacherDeleteMode = !this.teacherDeleteMode;
        },
        toggleTeacherPopover() {
            if (this.teacherPopoverOpen) {
                return this.closeTeacherPopover(this.$refs.teacherSelectionTrigger);
            }

            this.$refs.teacherSelectionTrigger?.focus();

            const rect =
                this.$refs.teacherSelectionTrigger?.getBoundingClientRect() ?? {
                    bottom: 0,
                    top: 0,
                    left: 0,
                    right: 0,
                };
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;
            const hEst = this.teacherPopoverPanelHeightEst;
            const wEst = this.teacherPopoverPanelWidthEst;

            if (rect.bottom + hEst > vh && rect.top > hEst) {
                this.teacherPopoverPlacementVertical = 'top';
            } else {
                this.teacherPopoverPlacementVertical = 'bottom';
            }
            const endFits = rect.right <= vw && rect.right - wEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + wEst <= vw;
            if (rect.left < contentLeft) {
                this.teacherPopoverPlacementHorizontal = 'start';
            } else if (endFits) {
                this.teacherPopoverPlacementHorizontal = 'end';
            } else if (startFits) {
                this.teacherPopoverPlacementHorizontal = 'start';
            } else {
                this.teacherPopoverPlacementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            this.teacherPopoverOpen = true;
            this.$dispatch('dropdown-opened');
        },
        closeTeacherPopover(focusAfter) {
            if (!this.teacherPopoverOpen) {
                return;
            }

            this.teacherPopoverOpen = false;
            this.teacherDeleteMode = false;
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

            focusAfter && focusAfter.focus();
        },
        teacherPopoverPanelClasses() {
            const v = this.teacherPopoverPlacementVertical;
            const h = this.teacherPopoverPlacementHorizontal;
            if (v === 'top' && h === 'end') {
                return 'bottom-full right-0 mb-1';
            }
            if (v === 'top' && h === 'start') {
                return 'bottom-full left-0 mb-1';
            }
            if (v === 'bottom' && h === 'end') {
                return 'top-full right-0 mt-1';
            }
            if (v === 'bottom' && h === 'start') {
                return 'top-full left-0 mt-1';
            }
            return 'top-full right-0 mt-1';
        },
        classHoursPopoverOpen: false,
        classHoursPopoverPlacementVertical: 'bottom',
        classHoursPopoverPlacementHorizontal: 'end',
        classHoursPopoverPanelHeightEst: 200,
        classHoursPopoverPanelWidthEst: 288,
        schoolClassHoursTriggerSummary() {
            const sc = this.formData?.schoolClass;
            if (!sc?.startTime || !sc?.endTime) {
                return '';
            }
            const to12h = (hm) => {
                const m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(String(hm).trim());
                if (!m) {
                    return '';
                }
                let h = parseInt(m[1], 10);
                const min = m[2];
                const pm = h >= 12;
                if (h > 12) {
                    h -= 12;
                }
                if (h === 0) {
                    h = 12;
                }

                return `${h}:${min} ${pm ? 'PM' : 'AM'}`;
            };
            const a = to12h(sc.startTime);
            const b = to12h(sc.endTime);
            if (!a || !b) {
                return '';
            }

            return `${a} – ${b}`;
        },
        toggleClassHoursPopover() {
            if (this.classHoursPopoverOpen) {
                return this.closeClassHoursPopover(this.$refs.classHoursTrigger);
            }

            this.$refs.classHoursTrigger?.focus();

            const rect =
                this.$refs.classHoursTrigger?.getBoundingClientRect() ?? {
                    bottom: 0,
                    top: 0,
                    left: 0,
                    right: 0,
                };
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;
            const hEst = this.classHoursPopoverPanelHeightEst;
            const wEst = this.classHoursPopoverPanelWidthEst;

            if (rect.bottom + hEst > vh && rect.top > hEst) {
                this.classHoursPopoverPlacementVertical = 'top';
            } else {
                this.classHoursPopoverPlacementVertical = 'bottom';
            }
            const endFits = rect.right <= vw && rect.right - wEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + wEst <= vw;
            if (rect.left < contentLeft) {
                this.classHoursPopoverPlacementHorizontal = 'start';
            } else if (endFits) {
                this.classHoursPopoverPlacementHorizontal = 'end';
            } else if (startFits) {
                this.classHoursPopoverPlacementHorizontal = 'start';
            } else {
                this.classHoursPopoverPlacementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            this.classHoursPopoverOpen = true;
            this.$dispatch('dropdown-opened');
        },
        closeClassHoursPopover(focusAfter) {
            if (!this.classHoursPopoverOpen) {
                return;
            }

            this.classHoursPopoverOpen = false;
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

            focusAfter && focusAfter.focus();
        },
        classHoursPopoverPanelClasses() {
            const v = this.classHoursPopoverPlacementVertical;
            const h = this.classHoursPopoverPlacementHorizontal;
            if (v === 'top' && h === 'end') {
                return 'bottom-full right-0 mb-1';
            }
            if (v === 'top' && h === 'start') {
                return 'bottom-full left-0 mb-1';
            }
            if (v === 'bottom' && h === 'end') {
                return 'top-full right-0 mt-1';
            }
            if (v === 'bottom' && h === 'start') {
                return 'top-full left-0 mt-1';
            }
            return 'top-full right-0 mt-1';
        },
        selectSchoolClassTeacher(teacher) {
            if (!teacher || teacher.id == null) {
                return;
            }
            if (!this.formData?.schoolClass) {
                return;
            }
            this.formData.schoolClass.teacherId = teacher.id;
            this.formData.schoolClass.teacherName = String(teacher.name || '').trim();
            this.closeTeacherPopover(this.$refs.teacherSelectionTrigger);
        },
        newTagName: '',
        creatingTag: false,
        deletingTagIds: new Set(),
        newTeacherName: '',
        creatingTeacher: false,
        deletingTeacherIds: new Set(),
        workspaceWire() {
            // This component is rendered inside nested Livewire children (List/Kanban),
            // but the mutation methods live on the parent workspace Index component.
            // Prefer parent when available; fall back to the current component for safety.
            return $wire?.$parent ?? $wire;
        },
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
                const promise = this.workspaceWire().$call('deleteTag', tag.id);

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
                const promise = this.workspaceWire().$call('createTag', tagName);

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
        async createTeacherOptimistic(teacherNameFromEvent) {
            const teacherName =
                teacherNameFromEvent != null && teacherNameFromEvent !== ''
                    ? String(teacherNameFromEvent).trim()
                    : (this.newTeacherName || '').trim();
            if (!teacherName || this.creatingTeacher) {
                return;
            }

            this.newTeacherName = '';

            const nameLower = teacherName.toLowerCase();
            const existingTeacher = this.teachers?.find(t => (t.name || '').trim().toLowerCase() === nameLower);
            if (existingTeacher && !String(existingTeacher.id).startsWith('temp-')) {
                this.formData.schoolClass.teacherId = existingTeacher.id;
                this.formData.schoolClass.teacherName = String(existingTeacher.name || '').trim();
                $wire.dispatch('toast', {
                    type: 'info',
                    message: this.messages?.teacherAlreadyExists || 'Teacher already exists.',
                });

                return;
            }

            const tempId = `temp-${Date.now()}`;
            const teachersBackup = this.teachers ? [...this.teachers] : [];
            const schoolClassBackup = {
                teacherId: this.formData.schoolClass.teacherId,
                teacherName: this.formData.schoolClass.teacherName,
            };
            const newTeacherNameBackup = teacherName;

            try {
                if (!this.teachers) {
                    this.teachers = [];
                }
                this.teachers.push({ id: tempId, name: teacherName });
                this.teachers.sort((a, b) => a.name.localeCompare(b.name));

                this.formData.schoolClass.teacherId = tempId;
                this.formData.schoolClass.teacherName = teacherName;

                this.creatingTeacher = true;

                const promise = this.workspaceWire().$call('createTeacher', teacherName);
                await promise;
            } catch (error) {
                this.teachers = teachersBackup;
                this.formData.schoolClass.teacherId = schoolClassBackup.teacherId;
                this.formData.schoolClass.teacherName = schoolClassBackup.teacherName;
                this.newTeacherName = newTeacherNameBackup;

                $wire.dispatch('toast', { type: 'error', message: this.messages.teacherError });
            } finally {
                this.creatingTeacher = false;
            }
        },
        async deleteTeacherOptimistic(teacher) {
            if (this.deletingTeacherIds?.has(teacher.id)) {
                return;
            }

            const isTempTeacher = String(teacher.id).startsWith('temp-');

            const snapshot = { ...teacher };
            const teachersBackup = this.teachers ? [...this.teachers] : [];
            const teacherIndex = this.teachers?.findIndex(t => String(t.id) === String(teacher.id)) ?? -1;
            const schoolClassBackup = {
                teacherId: this.formData.schoolClass.teacherId,
                teacherName: this.formData.schoolClass.teacherName,
            };

            try {
                this.deletingTeacherIds = this.deletingTeacherIds || new Set();
                this.deletingTeacherIds.add(teacher.id);

                if (this.teachers && teacherIndex !== -1) {
                    this.teachers = this.teachers.filter(t => String(t.id) !== String(teacher.id));
                }

                const wasSelected = String(this.formData.schoolClass.teacherId) === String(teacher.id);
                if (wasSelected) {
                    this.formData.schoolClass.teacherId = null;
                    this.formData.schoolClass.teacherName = '';
                }

                if (isTempTeacher) {
                    return;
                }

                const promise = this.workspaceWire().$call('deleteTeacher', Number(teacher.id));
                await promise;
            } catch (error) {
                if (teacherIndex !== -1 && this.teachers) {
                    this.teachers.splice(teacherIndex, 0, snapshot);
                    this.teachers.sort((a, b) => a.name.localeCompare(b.name));
                }

                if (String(schoolClassBackup.teacherId) === String(teacher.id)) {
                    this.formData.schoolClass.teacherId = schoolClassBackup.teacherId;
                    this.formData.schoolClass.teacherName = schoolClassBackup.teacherName;
                }

                $wire.dispatch('toast', { type: 'error', message: this.messages.teacherError });
            } finally {
                this.deletingTeacherIds?.delete(teacher.id);
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
            const minLoadingMs = 150;

            this.workspaceWire().$call('createTask', payload)
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
            const minLoadingMs = 150;

            this.workspaceWire().$call('createEvent', payload)
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
            const minLoadingMs = 150;

            this.workspaceWire().$call('createProject', payload)
                .finally(() => {
                    const elapsed = Date.now() - this.loadingStartedAt;
                    const remaining = Math.max(0, minLoadingMs - elapsed);
                    setTimeout(() => {
                        this.showItemLoading = false;
                        this.isSubmitting = false;
                    }, remaining);
                });
        },
        submitSchoolClass() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.schoolClass.subjectName || !this.formData.schoolClass.subjectName.trim()) {
                return;
            }

            if (!this.formData.schoolClass.teacherName || !String(this.formData.schoolClass.teacherName).trim()) {
                return;
            }

            const sc = this.formData.schoolClass;
            if (sc.scheduleMode === 'recurring') {
                if (sc.recurrence?.enabled !== true || !sc.recurrence?.type) {
                    this.errors.dateRange = this.messages.schoolClassNeedRecurrenceType;

                    return;
                }

                const t = sc.recurrence?.type;
                const dow = sc.recurrence?.daysOfWeek;
                if (t === 'weekly' && (!Array.isArray(dow) || dow.length === 0)) {
                    this.errors.dateRange = this.messages.schoolClassNeedWeekdays;

                    return;
                }
            } else if (sc.scheduleMode === 'one_off' && !sc.meetingDate) {
                this.errors.dateRange = this.messages.schoolClassNeedMeetingDate;

                return;
            }

            if (!sc.startTime || !sc.endTime) {
                return;
            }

            if (!this.validateDateRange()) {
                return;
            }

            this.isSubmitting = true;
            this.formData.schoolClass.subjectName = this.formData.schoolClass.subjectName.trim();
            this.formData.schoolClass.teacherName = String(this.formData.schoolClass.teacherName).trim();

            this.showItemCreation = false;
            this.showItemLoading = true;
            this.loadingStartedAt = Date.now();

            const recurrencePayload =
                sc.scheduleMode === 'one_off'
                    ? { enabled: false, type: null, interval: 1, daysOfWeek: [] }
                    : JSON.parse(JSON.stringify(sc.recurrence));

            const payload = {
                scheduleMode: sc.scheduleMode,
                subjectName: sc.subjectName,
                teacherName: sc.teacherName,
                scheduleStartDate: sc.scheduleStartDate,
                scheduleEndDate: sc.scheduleEndDate,
                meetingDate: sc.meetingDate,
                startTime: sc.startTime,
                endTime: sc.endTime,
                recurrence: recurrencePayload,
            };
            const minLoadingMs = 150;

            this.workspaceWire().$call('createSchoolClass', payload)
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
        onTeacherCreated(event) {
            const { id, name } = event.detail;

            const nameLower = (name || '').toLowerCase();
            const tempTeacher = this.teachers?.find(
                t =>
                    (t.name || '').toLowerCase() === nameLower && String(t.id).startsWith('temp-'),
            );
            if (tempTeacher) {
                const tempId = tempTeacher.id;
                const tempTeacherIndex = this.teachers.findIndex(t => t.id === tempId);
                if (tempTeacherIndex !== -1) {
                    this.teachers[tempTeacherIndex] = { id, name };
                }

                if (String(this.formData?.schoolClass?.teacherId) === String(tempId)) {
                    this.formData.schoolClass.teacherId = id;
                    this.formData.schoolClass.teacherName = String(name || '').trim();
                }

                this.teachers = this.teachers.filter(
                    (t, idx, arr) => arr.findIndex(x => String(x.id) === String(t.id)) === idx,
                );
                this.teachers.sort((a, b) => a.name.localeCompare(b.name));
            } else {
                if (this.teachers && !this.teachers.find(t => String(t.id) === String(id))) {
                    this.teachers.push({ id, name });
                    this.teachers.sort((a, b) => a.name.localeCompare(b.name));
                }
            }
        },
        onTeacherDeleted(event) {
            const { id } = event.detail;

            if (this.teachers) {
                const idx = this.teachers.findIndex(t => String(t.id) === String(id));
                if (idx !== -1) {
                    this.teachers.splice(idx, 1);
                }
            }

            if (String(this.formData?.schoolClass?.teacherId) === String(id)) {
                this.formData.schoolClass.teacherId = null;
                this.formData.schoolClass.teacherName = '';
            }
        },
