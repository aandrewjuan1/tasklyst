<div class="relative z-10">
    @if (($mode ?? 'list') === 'kanban')
        <flux:button
            icon:trailing="plus-circle"
            data-item-creation-safe
            x-bind:disabled="showItemLoading"
            @click="
                if (showItemCreation && creationKind === 'task') {
                    showItemCreation = false;
                } else {
                    creationKind = 'task';
                    formData.item.status = 'to_do';
                    formData.item.priority = 'medium';
                    formData.item.complexity = 'moderate';
                    formData.item.duration = null;
                    formData.item.allDay = false;
                    formData.item.projectId = null;
                    showItemCreation = true;
                    $nextTick(() => $refs.taskTitle?.focus());
                }
            "
        >
            {{ __('Add') }}
        </flux:button>
    @else
        <flux:dropdown position="right" align="start">
            <flux:button icon:trailing="plus-circle" data-item-creation-safe x-bind:disabled="showItemLoading">
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
                            formData.item.duration = null;
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
                <flux:menu.item
                    icon="clipboard-document-list"
                    @click="
                        if (showItemCreation && creationKind === 'project') {
                            showItemCreation = false;
                        } else {
                            creationKind = 'project';
                            formData.project.name = '';
                            formData.project.description = null;
                            formData.project.startDatetime = null;
                            formData.project.endDatetime = null;
                            showItemCreation = true;
                            $nextTick(() => $refs.projectName?.focus());
                        }
                    "
                >
                    {{ __('Project') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    @endif

    <template x-if="showItemCreation">
    <div
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-ref="taskCreationCard"
        @click.outside="
            const target = $event.target;
            const isSafe = target.closest('[data-item-creation-safe]');
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
                @submit.prevent="creationKind === 'project' ? submitProject() : (creationKind === 'task' ? submitTask() : submitEvent())"
            >
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <div x-show="creationKind !== 'project'" x-cloak>
                            <x-recurring-selection
                                compactWhenDisabled
                                position="top"
                                align="end"
                            />
                        </div>
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
                        <span
                            class="inline-flex w-fit items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                            x-show="creationKind === 'project'"
                            x-cloak
                        >
                            {{ __('Project') }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                            <flux:input
                                x-show="creationKind !== 'project'"
                                x-cloak
                                x-model="formData.item.title"
                                x-ref="taskTitle"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Enter title...') }}"
                                class="w-full text-sm font-medium"
                                @keydown.enter.prevent="if (!isSubmitting && formData.item.title && formData.item.title.trim()) { creationKind === 'task' ? submitTask() : submitEvent(); }"
                            />
                            <flux:input
                                x-show="creationKind === 'project'"
                                x-cloak
                                x-model="formData.project.name"
                                x-ref="projectName"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Enter project name...') }}"
                                class="w-full text-sm font-medium"
                                @keydown.enter.prevent="if (!isSubmitting && formData.project.name && formData.project.name.trim()) { submitProject(); }"
                            />
                        </div>

                        <flux:button
                            type="button"
                            variant="primary"
                            icon="paper-airplane"
                            class="shrink-0 rounded-full"
                            x-bind:disabled="isSubmitting || (creationKind === 'project' ? (!formData.project.name || !formData.project.name.trim()) : (!formData.item.title || !formData.item.title.trim()))"
                            @click="creationKind === 'project' ? submitProject() : (creationKind === 'task' ? submitTask() : submitEvent())"
                        />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <template x-if="creationKind === 'task'">
                            <div class="contents">
                                <x-workspace.creation-task-fields />
                            </div>
                        </template>
                        <template x-if="creationKind === 'event'">
                            <div class="contents">
                                <x-workspace.creation-event-fields />
                            </div>
                        </template>
                        <template x-if="creationKind === 'project'">
                            <div class="contents">
                                <x-workspace.creation-project-fields />
                            </div>
                        </template>

                        <div x-show="creationKind !== 'project'" x-cloak class="contents">
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
                        </div>

                        <div class="flex w-full items-center gap-1.5" x-show="errors.dateRange" x-cloak>
                            <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600" />
                            <p class="text-xs font-medium text-red-600" x-text="errors.dateRange"></p>
                        </div>
                    </div>

                    <div class="w-full flex flex-wrap items-center gap-2 pt-1.5 mt-1 border-t border-border/50 text-[10px]" x-show="creationKind !== 'project'" x-cloak>
                        <x-workspace.tag-selection position="bottom" align="end" />
                    </div>
                </div>
            </form>
        </div>
    </div>
    </template>

<template x-if="showItemLoading">
<div
    x-cloak
    x-transition:enter="transition ease-out duration-100"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    data-test="task-loading-card"
    class="mt-4 flex flex-col overflow-hidden rounded-xl border border-border/60 bg-background/60 shadow-sm backdrop-blur opacity-60"
>
    <div
        class="relative h-px w-full overflow-hidden bg-zinc-300"
        aria-hidden="true"
    >
        <div class="loading-bar-track absolute left-0 top-0 h-full w-1/3 max-w-[120px] rounded-full bg-zinc-500"></div>
    </div>
    <div class="flex flex-col gap-2 px-3 pt-3 pb-2">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <p class="truncate text-lg font-semibold leading-tight" x-text="creationKind === 'project' ? formData.project.name : formData.item.title"></p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    x-show="creationKind !== 'project'"
                    x-cloak
                    class="cursor-default inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-medium transition-[box-shadow,transform] duration-150 ease-out"
                    :class="(formData.item.recurrence?.enabled && formData.item.recurrence?.type) ? 'border-indigo-500/25 bg-indigo-500/10 text-indigo-700 shadow-sm' : 'border-border/60 bg-muted text-muted-foreground'"
                >
                    <flux:icon name="arrow-path" class="size-3" />
                    <span x-show="formData.item.recurrence?.enabled && formData.item.recurrence?.type" class="inline-flex items-baseline gap-1" x-cloak>
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Repeats') }}:</span>
                        <span class="text-xs" x-text="recurrenceLabel(formData.item.recurrence)"></span>
                    </span>
                    <flux:icon name="chevron-down" class="size-3" x-show="formData.item.recurrence?.enabled && formData.item.recurrence?.type" x-cloak></flux:icon>
                </span>
                <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    <span x-text="creationKind === 'task' ? '{{ __('Task') }}' : (creationKind === 'event' ? '{{ __('Event') }}' : '{{ __('Project') }}')"></span>
                </span>
                <flux:button size="xs" icon="ellipsis-horizontal" disabled class="pointer-events-none opacity-70" />
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
            <template x-if="creationKind === 'task'">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold"
                        :class="getStatusBadgeClass(formData.item.status)"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Status') }}:</span>
                            <span class="uppercase" x-text="statusLabel(formData.item.status)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold"
                        :class="getPriorityBadgeClass(formData.item.priority)"
                    >
                        <flux:icon name="bolt" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Priority') }}:</span>
                            <span class="uppercase" x-text="priorityLabel(formData.item.priority)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold"
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
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold"
                        :class="getEventStatusBadgeClass(formData.item.status)"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Status') }}:</span>
                            <span class="uppercase" x-text="eventStatusLabel(formData.item.status)"></span>
                        </span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium transition-[box-shadow,transform] duration-150 ease-out"
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
            <template x-if="creationKind === 'project'">
                <div class="flex flex-wrap items-center gap-2"></div>
            </template>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Start') }}:</span>
                    <span class="text-xs uppercase" x-text="(creationKind === 'project' ? formData.project.startDatetime : formData.item.startDatetime) ? formatDatetime(creationKind === 'project' ? formData.project.startDatetime : formData.item.startDatetime) : '{{ __('Not set') }}'"></span>
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70" x-text="creationKind === 'task' ? '{{ __('Due') }}:' : '{{ __('End') }}:'"></span>
                    <span class="text-xs uppercase" x-text="(creationKind === 'project' ? formData.project.endDatetime : formData.item.endDatetime) ? formatDatetime(creationKind === 'project' ? formData.project.endDatetime : formData.item.endDatetime) : '{{ __('Not set') }}'"></span>
                </span>
            </span>
            <span
                x-show="creationKind !== 'project' && formData.item.projectId && projectNames[formData.item.projectId]"
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90"
            >
                <flux:icon name="folder" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Project') }}:</span>
                    <span class="truncate max-w-[120px] uppercase" x-text="projectNames[formData.item.projectId] || ''"></span>
                </span>
            </span>
        </div>
        <div class="flex w-full shrink-0 flex-wrap items-center gap-2 border-t border-border/50 pt-1.5 mt-1 text-[10px]" x-show="creationKind !== 'project'" x-cloak>
            <template x-for="tag in getSelectedTags()" :key="tag.id">
                <span class="inline-flex items-center rounded-sm border border-black/10 bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground" x-text="tag.name"></span>
            </template>
            <span
                x-show="!(formData.item.tagIds && formData.item.tagIds.length > 0)"
                class="inline-flex items-center rounded-sm border border-border/60 bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
            >{{ __('None') }}</span>
        </div>
    </div>
</div>
</template>
</div>
