@props([
    'tags',
    'teachers' => [],
    'projects',
    'activeFocusSession',
    'mode' => 'list',
    'emptyDateLabel' => null,
    'hasActiveSearch' => false,
    'hasActiveFilters' => false,
    'searchQueryDisplay' => null,
    'visibleItemsInitial' => 0,
    'boardIsEmpty' => false,
])

<div
    class="space-y-4"
    data-workspace-creation-form
    data-test="workspace-item-creation"
    x-data="{ @include('components.workspace.partials.item-creation-xdata') }"
    @workspace-list-visible-count.window="mode === 'list' && $event.detail?.count != null && (visibleItemCount = parseInt($event.detail.count, 10))"
    @task-created="resetForm()"
    @event-created="resetForm()"
    @project-created="resetForm()"
    @school-class-created="resetForm()"
    @tag-created.window="onTagCreated($event)"
    @tag-deleted.window="onTagDeleted($event)"
    @teacher-created.window="onTeacherCreated($event)"
    @teacher-deleted.window="onTeacherDeleted($event)"
    @date-picker-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @recurring-selection-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @item-form-updated="setFormDataByPath($event.detail.path, $event.detail.value)"
    @tag-toggled="toggleTag($event.detail.tagId)"
    @tag-create-request="createTagOptimistic($event.detail.tagName)"
    @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
    @teacher-create-request="createTeacherOptimistic($event.detail.teacherName)"
    @teacher-delete-request="deleteTeacherOptimistic($event.detail.teacher)"
    x-effect="
        formData.item.startDatetime;
        formData.item.endDatetime;
        formData.project.startDatetime;
        formData.project.endDatetime;
        formData.schoolClass.scheduleMode;
        formData.schoolClass.scheduleStartDate;
        formData.schoolClass.scheduleEndDate;
        formData.schoolClass.meetingDate;
        formData.schoolClass.startTime;
        formData.schoolClass.endTime;
        formData.schoolClass.recurrence;
        creationKind === 'task' ? formData.item.duration : null;
        validateDateRange();
    "
    x-init="Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: @js($activeFocusSession ?? null), focusReady: false })"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    @include('components.workspace.partials.item-creation-ui')
</div>
