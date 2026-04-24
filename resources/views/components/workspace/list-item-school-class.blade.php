@props([
    'schoolClass' => null,
    'teachers' => [],
    'updatePropertyMethod' => 'updateSchoolClassProperty',
])

@php
    /** @var \App\Models\SchoolClass $schoolClass */
    $recurring = $schoolClass->recurringSchoolClass;
    $teacher = $schoolClass->teacher;
    $teacherName = trim((string) ($teacher?->name ?? ''));
    $teacherId = $teacher?->id;
    $startDatetimeInitial = $schoolClass->start_datetime?->format('Y-m-d');
    $endDatetimeInitial = ($recurring?->end_datetime ?? $schoolClass->end_datetime)?->format('Y-m-d');
    $startTimeInitial = $schoolClass->start_time ? \Illuminate\Support\Carbon::parse($schoolClass->start_time)->format('H:i') : null;
    $endTimeInitial = $schoolClass->end_time ? \Illuminate\Support\Carbon::parse($schoolClass->end_time)->format('H:i') : null;
    $isRecurringInitial = $recurring !== null;
    $schoolClassHoursInitialSummary = __('Not set');
    if ($startTimeInitial !== null && $endTimeInitial !== null) {
        $schoolClassHoursInitialSummary = \Illuminate\Support\Carbon::parse($startTimeInitial)->translatedFormat('g:i A')
            .' – '.\Illuminate\Support\Carbon::parse($endTimeInitial)->translatedFormat('g:i A');
    }
    $recurrenceInitial = [
        'enabled' => false,
        'type' => 'weekly',
        'interval' => 1,
        'daysOfWeek' => [],
    ];
    if ($recurring !== null) {
        $daysOfWeek = $recurring->days_of_week ? (json_decode($recurring->days_of_week, true) ?? []) : [];
        $recurrenceInitial = [
            'enabled' => true,
            'type' => $recurring->recurrence_type?->value ?? 'weekly',
            'interval' => $recurring->interval ?? 1,
            'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
        ];
    }
@endphp

<div
    {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }}
    wire:ignore
    data-test="workspace-school-class-item"
    x-data="{
        itemId: @js($schoolClass->id),
        updatePropertyMethod: @js($updatePropertyMethod),
        teachers: @js($teachers),
        formData: {
            schoolClass: {
                teacherId: @js($teacherId),
                teacherName: @js($teacherName),
                startDatetime: @js($startDatetimeInitial),
                endDatetime: @js($endDatetimeInitial),
                startTime: @js($startTimeInitial),
                endTime: @js($endTimeInitial),
                scheduleMode: @js($recurring ? 'recurring' : 'one_off'),
                recurrence: @js($recurrenceInitial),
            },
        },
        editErrorToast: @js(__('Something went wrong. Please try again.')),
        newTeacherName: '',
        creatingTeacher: false,
        deletingTeacherIds: new Set(),
        teacherPopoverOpen: false,
        teacherDeleteMode: false,
        teacherPopoverPlacementVertical: 'bottom',
        teacherPopoverPlacementHorizontal: 'end',
        teacherPopoverPanelHeightEst: 240,
        teacherPopoverPanelWidthEst: 260,
        classHoursPopoverOpen: false,
        classHoursPopoverPlacementVertical: 'bottom',
        classHoursPopoverPlacementHorizontal: 'end',
        classHoursPopoverPanelHeightEst: 200,
        classHoursPopoverPanelWidthEst: 288,
        timeUpdateDebounceTimer: null,
        isSubmitting: false,
        schoolClassTimeStart: { hour: '', minute: '', ampm: 'AM', disabled: false, updateTime() {} },
        schoolClassTimeEnd: { hour: '', minute: '', ampm: 'AM', disabled: false, updateTime() {} },
        init() {
            this.schoolClassTimeStart = this.createTimeModel('startTime');
            this.schoolClassTimeEnd = this.createTimeModel('endTime');
        },
        createTimeModel(path) {
            const parseTime = (value) => {
                const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(String(value || ''));
                if (!match) {
                    return { hour: '', minute: '', ampm: 'AM' };
                }
                let hour24 = Number(match[1]);
                const minute = String(match[2]);
                const ampm = hour24 >= 12 ? 'PM' : 'AM';
                if (hour24 === 0) {
                    hour24 = 12;
                } else if (hour24 > 12) {
                    hour24 -= 12;
                }
                return { hour: String(hour24), minute, ampm };
            };
            const value = this.formData?.schoolClass?.[path] ?? null;
            const parsed = parseTime(value);
            return {
                hour: parsed.hour,
                minute: parsed.minute,
                ampm: parsed.ampm,
                disabled: false,
                updateTime: () => {
                    const model = path === 'startTime' ? this.schoolClassTimeStart : this.schoolClassTimeEnd;
                    const hourNum = Number(model.hour);
                    const minuteNum = Number(model.minute);
                    const ampm = model.ampm;
                    if (!Number.isFinite(hourNum) || hourNum < 1 || hourNum > 12 || !Number.isFinite(minuteNum) || minuteNum < 0 || minuteNum > 59) {
                        return;
                    }
                    let hour24 = hourNum % 12;
                    if (ampm === 'PM') {
                        hour24 += 12;
                    }
                    const hh = String(hour24).padStart(2, '0');
                    const mm = String(minuteNum).padStart(2, '0');
                    this.formData.schoolClass[path] = `${hh}:${mm}`;
                    this.persistTimeFieldsDebounced();
                },
            };
        },
        schoolClassHoursTriggerSummary() {
            const to12h = (hm) => {
                const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(String(hm || ''));
                if (!match) {
                    return '';
                }
                let hour = Number(match[1]);
                const minute = match[2];
                const isPm = hour >= 12;
                if (hour > 12) {
                    hour -= 12;
                }
                if (hour === 0) {
                    hour = 12;
                }
                return `${hour}:${minute} ${isPm ? 'PM' : 'AM'}`;
            };
            const start = to12h(this.formData.schoolClass.startTime);
            const end = to12h(this.formData.schoolClass.endTime);
            return start && end ? `${start} – ${end}` : '';
        },
        schoolClassTeacherTriggerLabel() {
            return String(this.formData?.schoolClass?.teacherName || '').trim();
        },
        mergedSchoolClassTeachers() {
            const available = Array.isArray(this.teachers) ? [...this.teachers] : [];
            const selectedId = this.formData?.schoolClass?.teacherId;
            const selectedName = String(this.formData?.schoolClass?.teacherName || '').trim();
            if (selectedId != null && selectedName !== '' && !available.some((t) => String(t.id) === String(selectedId))) {
                available.push({ id: selectedId, name: selectedName });
            }
            return available
                .filter((teacher) => teacher && teacher.id != null && String(teacher.name || '').trim() !== '')
                .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
        },
        isSchoolClassTeacherSelected(teacherId) {
            return String(this.formData?.schoolClass?.teacherId ?? '') === String(teacherId ?? '');
        },
        selectSchoolClassTeacher(teacher) {
            if (!teacher || teacher.id == null) {
                return;
            }
            this.formData.schoolClass.teacherId = teacher.id;
            this.formData.schoolClass.teacherName = String(teacher.name || '').trim();
            this.closeTeacherPopover(this.$refs.teacherSelectionTrigger);
            this.updateProperty('teacherName', this.formData.schoolClass.teacherName);
        },
        async createTeacherOptimistic(teacherNameFromEvent) {
            const teacherName = String(teacherNameFromEvent || this.newTeacherName || '').trim();
            if (!teacherName || this.creatingTeacher) {
                return;
            }
            this.creatingTeacher = true;
            try {
                await $wire.$parent.$call('createTeacher', teacherName, true);
                this.newTeacherName = '';
            } catch (_) {
                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
            } finally {
                this.creatingTeacher = false;
            }
        },
        async deleteTeacherOptimistic(teacher) {
            if (!teacher || teacher.id == null || this.deletingTeacherIds.has(teacher.id)) {
                return;
            }
            this.deletingTeacherIds.add(teacher.id);
            try {
                await $wire.$parent.$call('deleteTeacher', teacher.id, true);
            } catch (_) {
                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
            } finally {
                this.deletingTeacherIds.delete(teacher.id);
            }
        },
        onTeacherCreated(event) {
            const teacher = event.detail || {};
            if (!teacher.id || !teacher.name) {
                return;
            }
            if (!this.teachers.some((entry) => String(entry.id) === String(teacher.id))) {
                this.teachers.push({ id: teacher.id, name: teacher.name });
            }
        },
        onTeacherDeleted(event) {
            const teacherId = event.detail?.id;
            if (teacherId == null) {
                return;
            }
            this.teachers = this.teachers.filter((teacher) => String(teacher.id) !== String(teacherId));
            if (String(this.formData.schoolClass.teacherId ?? '') === String(teacherId)) {
                this.formData.schoolClass.teacherId = null;
                this.formData.schoolClass.teacherName = '';
            }
        },
        toggleTeacherDeleteMode() {
            this.teacherDeleteMode = !this.teacherDeleteMode;
        },
        toggleTeacherPopover() {
            if (this.teacherPopoverOpen) {
                this.closeTeacherPopover(this.$refs.teacherSelectionTrigger);
                return;
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
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter?.focus();
        },
        teacherPopoverPanelClasses() {
            return 'top-full right-0 mt-1';
        },
        toggleClassHoursPopover() {
            if (this.classHoursPopoverOpen) {
                this.closeClassHoursPopover(this.$refs.classHoursTrigger);
                return;
            }
            this.classHoursPopoverOpen = true;
            this.$dispatch('dropdown-opened');
        },
        closeClassHoursPopover(focusAfter) {
            if (!this.classHoursPopoverOpen) {
                return;
            }
            this.classHoursPopoverOpen = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter?.focus();
        },
        classHoursPopoverPanelClasses() {
            return 'top-full right-0 mt-1';
        },
        persistTimeFieldsDebounced() {
            if (this.timeUpdateDebounceTimer) {
                clearTimeout(this.timeUpdateDebounceTimer);
            }
            this.timeUpdateDebounceTimer = setTimeout(async () => {
                const startOk = await this.updateProperty('startTime', this.formData.schoolClass.startTime);
                if (!startOk) {
                    return;
                }
                await this.updateProperty('endTime', this.formData.schoolClass.endTime);
            }, 300);
        },
        clearSchoolClassMeetingDateForRecurringChoice() {
            this.formData.schoolClass.scheduleMode = 'recurring';
        },
        async updateProperty(property, value) {
            const snapshot = JSON.parse(JSON.stringify(this.formData.schoolClass));
            try {
                const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, false);
                if (!ok) {
                    this.formData.schoolClass = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                    return false;
                }
                window.dispatchEvent(new CustomEvent('workspace-item-property-updated', {
                    detail: { kind: 'schoolclass', itemId: this.itemId, property, value },
                    bubbles: true,
                }));
                if (property === 'teacherName') {
                    window.dispatchEvent(new CustomEvent('workspace-school-class-meta-updated', {
                        detail: { schoolClassId: this.itemId, teacherName: value ?? '' },
                        bubbles: true,
                    }));
                }
                return true;
            } catch (_) {
                this.formData.schoolClass = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                return false;
            }
        },
        async handleDatePickerUpdated(event) {
            event.stopPropagation();
            const path = event.detail?.path;
            const value = event.detail?.value;
            if (path === 'formData.schoolClass.startDatetime') {
                await this.updateProperty('startDatetime', value);
                return;
            }
            if (path === 'formData.schoolClass.endDatetime') {
                await this.updateProperty('endDatetime', value);
            }
        },
        async handleRecurringSelectionUpdated(event) {
            event.stopPropagation();
            const path = event.detail?.path;
            const value = event.detail?.value;
            if (path !== 'formData.schoolClass.recurrence') {
                return;
            }
            const ok = await this.updateProperty('recurrence', value);
            if (!ok) {
                event.target.dispatchEvent(new CustomEvent('recurring-revert', {
                    detail: { path: 'formData.schoolClass.recurrence', value: this.formData.schoolClass.recurrence, itemId: this.itemId },
                    bubbles: true,
                }));
            } else {
                this.formData.schoolClass.recurrence = value;
                this.formData.schoolClass.scheduleMode = value?.enabled ? 'recurring' : 'one_off';
            }
        },
    }"
    @date-picker-updated="handleDatePickerUpdated($event)"
    @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
    @teacher-create-request="createTeacherOptimistic($event.detail.teacherName)"
    @teacher-delete-request="deleteTeacherOptimistic($event.detail.teacher)"
    @teacher-created.window="onTeacherCreated($event)"
    @teacher-deleted.window="onTeacherDeleted($event)"
>
    <div class="flex flex-wrap items-center gap-2 text-muted-foreground">
        <x-workspace.teacher-selection
            position="top"
            align="end"
            :initial-label="$teacherName !== '' ? $teacherName : __('Add teacher')"
            :initial-selected="$teacherName !== ''"
        />
        <x-recurring-selection
            model="formData.schoolClass.recurrence"
            kind="schoolClass"
            position="top"
            align="end"
            :initial-value="$recurrenceInitial"
            :school-class-creation="true"
        />
    </div>

    <div class="flex flex-wrap items-center gap-2 text-muted-foreground">
        <div
            class="contents"
            x-show="formData.schoolClass.recurrence?.enabled"
            @if (! $isRecurringInitial) style="display: none;" @endif
        >
            <flux:tooltip :content="__('Set the first day in range.')">
                <span class="inline-flex">
                    <x-date-picker
                        :label="__('First day in range')"
                        :trigger-label="__('Class starts')"
                        model="formData.schoolClass.startDatetime"
                        type="date"
                        position="top"
                        align="end"
                        :initial-value="$startDatetimeInitial"
                    />
                </span>
            </flux:tooltip>
            <flux:tooltip :content="__('Set the last day this class meets.')">
                <span class="inline-flex">
                    <x-date-picker
                        :label="__('Last day in range')"
                        :trigger-label="__('Class ends')"
                        model="formData.schoolClass.endDatetime"
                        type="date"
                        position="top"
                        align="end"
                        :initial-value="$endDatetimeInitial"
                    />
                </span>
            </flux:tooltip>
        </div>

        <div
            x-show="!formData.schoolClass.recurrence?.enabled"
            @if ($isRecurringInitial) style="display: none;" @endif
        >
            <x-date-picker
                :label="__('Meeting date')"
                :trigger-label="__('Meeting date')"
                model="formData.schoolClass.startDatetime"
                type="date"
                position="top"
                align="end"
                :initial-value="$startDatetimeInitial"
                :school-class-meeting-day="true"
            />
        </div>

        <x-workspace.school-class-hours-selection
            :initial-summary="$schoolClassHoursInitialSummary"
            :initial-has-value="$startTimeInitial !== null && $endTimeInitial !== null"
            start-model="$data.schoolClassTimeStart"
            end-model="$data.schoolClassTimeEnd"
        />
    </div>
</div>
