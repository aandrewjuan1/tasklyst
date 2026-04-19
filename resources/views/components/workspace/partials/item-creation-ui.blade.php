@php
    $isKanbanMode = ($mode ?? 'list') === 'kanban';
    $isListMode = ($mode ?? 'list') === 'list';
    $hasActiveSearch = (bool) ($hasActiveSearch ?? false);
    $hasActiveFilters = (bool) ($hasActiveFilters ?? false);
    $searchQueryDisplay = $searchQueryDisplay ?? null;
    $emptyDateLabel = $emptyDateLabel ?? '';
    $boardIsEmpty = (bool) ($boardIsEmpty ?? false);
    $visibleItemsInitial = (int) ($visibleItemsInitial ?? 0);
    $entryCardClass =
        'list-item-card relative flex w-full flex-col gap-2 overflow-hidden rounded-xl px-3 py-2 lic-surface-zinc';
    $entryButtonClass =
        'flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors duration-100 ease-out hover:bg-black/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 dark:hover:bg-white/[0.06]';
    $typePickerBtnClass =
        'flex min-h-[4.5rem] flex-col items-center justify-center gap-1.5 rounded-lg border border-border/60 bg-background/40 px-1.5 py-2 shadow-sm transition hover:bg-background/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 dark:border-border/50 dark:bg-background/20 dark:hover:bg-background/40';
@endphp

<div
    class="relative w-full"
    x-bind:class="showItemCreation ? 'z-[60]' : 'z-10'"
>
    <div
        class="{{ $entryCardClass }}"
        x-bind:class="showItemCreation || showItemLoading ? 'z-0' : 'z-10'"
        data-item-creation-safe
        @click.outside="itemTypePickerOpen = false"
    >
        <div class="min-w-0">
            @if ($isKanbanMode)
                <button
                    type="button"
                    data-item-creation-safe
                    class="{{ $entryButtonClass }}"
                    x-bind:disabled="showItemLoading"
                    aria-label="{{ __('Create task') }}"
                    @click="beginItemCreation('task')"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background/60 shadow-sm ring-1 ring-border/50 dark:bg-zinc-800/80 dark:ring-border/40"
                        aria-hidden="true"
                    >
                        <flux:icon
                            name="plus-circle"
                            class="size-5 text-foreground/80"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        @if ($boardIsEmpty)
                            <span
                                class="contents"
                                role="status"
                                aria-live="polite"
                                data-test="workspace-item-creation-empty"
                            >
                                @if ($hasActiveSearch && $searchQueryDisplay)
                                    <span class="block text-sm font-semibold leading-tight text-foreground">
                                        {{ __('No results for ":query"', ['query' => $searchQueryDisplay]) }}
                                    </span>
                                    <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                        {{ __('Try another search or add a task.') }}
                                    </span>
                                @elseif ($hasActiveFilters)
                                    <span class="block text-sm font-semibold leading-tight text-foreground">
                                        {{ __('Nothing matches your filters') }}
                                    </span>
                                    <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                        {{ __('Clear or adjust filters, or add a task.') }}
                                    </span>
                                @else
                                    <span class="block text-sm font-semibold leading-tight text-foreground">{{ __('New task') }}</span>
                                    <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">{{ __('Tap to open the quick-add form') }}</span>
                                @endif
                            </span>
                        @else
                            <span class="block text-sm font-semibold leading-tight text-foreground">{{ __('New task') }}</span>
                            <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">{{ __('Tap to open the quick-add form') }}</span>
                        @endif
                    </span>
                </button>
            @else
                <button
                    type="button"
                    data-item-creation-safe
                    class="{{ $entryButtonClass }}"
                    x-bind:class="(showItemCreation || showItemLoading) ? 'cursor-not-allowed opacity-40 transition-opacity duration-150 ease-out' : 'transition-opacity duration-150 ease-out'"
                    x-bind:disabled="showItemLoading || showItemCreation"
                    aria-label="{{ __('Create item') }}"
                    x-bind:aria-expanded="itemTypePickerOpen"
                    @click="onPlusToolbarClick()"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background/60 shadow-sm ring-1 ring-border/50 dark:bg-zinc-800/80 dark:ring-border/40"
                        aria-hidden="true"
                    >
                        <flux:icon
                            name="plus-circle"
                            class="size-5 text-foreground/80"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            x-show="mode === 'list' && visibleItemCount <= 0"
                            class="block"
                            role="status"
                            aria-live="polite"
                            data-test="workspace-item-creation-empty"
                            style="{{ $isListMode && $visibleItemsInitial > 0 ? 'display: none' : '' }}"
                        >
                            @if ($hasActiveSearch && $searchQueryDisplay)
                                <span class="block text-sm font-semibold leading-tight text-foreground">
                                    {{ __('No results for ":query"', ['query' => $searchQueryDisplay]) }}
                                </span>
                                <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                    {{ __('Try another search or add a task, event, project, or class.') }}
                                </span>
                            @elseif ($hasActiveFilters)
                                <span class="block text-sm font-semibold leading-tight text-foreground">
                                    {{ __('Nothing matches your filters') }}
                                </span>
                                <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                    {{ __('Clear or adjust filters, or add something new.') }}
                                </span>
                            @else
                                <span class="block text-sm font-semibold leading-tight text-foreground">
                                    {{ __('No tasks, projects, events, or classes for :date', ['date' => $emptyDateLabel]) }}
                                </span>
                                <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                    {{ __('Add a task, project, event, or class for this day to get started') }}
                                </span>
                            @endif
                        </span>
                        <span
                            x-show="mode !== 'list' || visibleItemCount > 0"
                            class="block"
                            style="{{ $isListMode && $visibleItemsInitial <= 0 ? 'display: none' : '' }}"
                        >
                            <span class="block text-sm font-semibold leading-tight text-foreground">{{ __('Create something new') }}</span>
                            <span class="mt-0.5 block text-xs leading-snug text-muted-foreground">{{ __('Tap, then pick task, event, project, or class') }}</span>
                        </span>
                    </span>
                </button>

                <div
                    x-show="itemTypePickerOpen"
                    x-cloak
                    x-transition.opacity.duration.100ms
                    class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-2.5"
                    role="group"
                    aria-label="{{ __('Item type') }}"
                >
                    <button
                        type="button"
                        data-item-creation-safe
                        class="{{ $typePickerBtnClass }} border-l-[3px] border-l-brand-navy-blue/50"
                        @mousedown.prevent
                        @click="beginItemCreation('task')"
                    >
                        <flux:icon
                            name="rectangle-stack"
                            class="size-7 text-brand-navy-blue dark:text-brand-light-blue"
                            aria-hidden="true"
                        />
                        <span class="text-center text-[11px] font-semibold leading-tight text-foreground sm:text-xs">{{ __('Task') }}</span>
                    </button>
                    <button
                        type="button"
                        data-item-creation-safe
                        class="{{ $typePickerBtnClass }} border-l-[3px] border-l-indigo-500/60"
                        @mousedown.prevent
                        @click="beginItemCreation('event')"
                    >
                        <flux:icon
                            name="calendar-days"
                            class="size-7 text-indigo-600 dark:text-indigo-300"
                            aria-hidden="true"
                        />
                        <span class="text-center text-[11px] font-semibold leading-tight text-foreground sm:text-xs">{{ __('Event') }}</span>
                    </button>
                    <button
                        type="button"
                        data-item-creation-safe
                        class="{{ $typePickerBtnClass }} border-l-[3px] border-l-emerald-500/60"
                        @mousedown.prevent
                        @click="beginItemCreation('project')"
                    >
                        <flux:icon
                            name="clipboard-document-list"
                            class="size-7 text-emerald-700 dark:text-emerald-300"
                            aria-hidden="true"
                        />
                        <span class="text-center text-[11px] font-semibold leading-tight text-foreground sm:text-xs">{{ __('Project') }}</span>
                    </button>
                    <button
                        type="button"
                        data-item-creation-safe
                        class="{{ $typePickerBtnClass }} border-l-[3px] border-l-amber-500/60"
                        @mousedown.prevent
                        @click="beginItemCreation('schoolClass')"
                    >
                        <flux:icon
                            name="book-open"
                            class="size-7 text-amber-800 dark:text-amber-200"
                            aria-hidden="true"
                        />
                        <span class="text-center text-[11px] font-semibold leading-tight text-foreground sm:text-xs">{{ __('Class') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

    <template x-if="showItemCreation">
        <div
            x-transition.opacity.duration.200ms
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
            class="list-item-card relative z-20 mt-4 flex flex-col gap-2 rounded-xl px-3 py-2"
            x-bind:class="creationCardSurfaceClass()"
            x-cloak
        >
            <form
                class="min-w-0"
                @submit.prevent="creationKind === 'project' ? submitProject() : (creationKind === 'schoolClass' ? submitSchoolClass() : (creationKind === 'task' ? submitTask() : submitEvent()))"
            >
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                        <div class="min-w-0 flex-1">
                            <input
                                type="text"
                                x-show="creationKind === 'task' || creationKind === 'event'"
                                x-cloak
                                x-model="formData.item.title"
                                x-ref="taskTitle"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Enter title...') }}"
                                autocomplete="off"
                                aria-label="{{ __('Task or event title') }}"
                                class="w-full min-w-0 border-0 bg-transparent px-0 py-0.5 text-xl font-bold leading-tight text-foreground shadow-none ring-0 placeholder:text-sm placeholder:font-normal placeholder:text-muted-foreground/80 focus:border-0 focus:outline-none focus:ring-0 md:text-2xl md:placeholder:text-base"
                                @keydown.enter.prevent="if (!isSubmitting && formData.item.title && formData.item.title.trim()) { creationKind === 'task' ? submitTask() : submitEvent(); }"
                            />
                            <input
                                type="text"
                                x-show="creationKind === 'project'"
                                x-cloak
                                x-model="formData.project.name"
                                x-ref="projectName"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Enter project name...') }}"
                                autocomplete="off"
                                aria-label="{{ __('Project name') }}"
                                class="w-full min-w-0 border-0 bg-transparent px-0 py-0.5 text-xl font-bold leading-tight text-foreground shadow-none ring-0 placeholder:text-sm placeholder:font-normal placeholder:text-muted-foreground/80 focus:border-0 focus:outline-none focus:ring-0 md:text-2xl md:placeholder:text-base"
                                @keydown.enter.prevent="if (!isSubmitting && formData.project.name && formData.project.name.trim()) { submitProject(); }"
                            />
                            <input
                                type="text"
                                x-show="creationKind === 'schoolClass'"
                                x-cloak
                                x-model="formData.schoolClass.subjectName"
                                x-ref="schoolClassSubject"
                                x-bind:disabled="isSubmitting"
                                placeholder="{{ __('Subject…') }}"
                                autocomplete="off"
                                aria-label="{{ __('Class subject') }}"
                                class="w-full min-w-0 border-0 bg-transparent px-0 py-0.5 text-xl font-bold leading-tight text-foreground shadow-none ring-0 placeholder:text-sm placeholder:font-normal placeholder:text-muted-foreground/80 focus:border-0 focus:outline-none focus:ring-0 md:text-2xl md:placeholder:text-base"
                                @keydown.enter.prevent="if (!isSubmitting && formData.schoolClass.subjectName && formData.schoolClass.subjectName.trim()) { submitSchoolClass(); }"
                            />
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5 sm:justify-end">
                            <flux:button
                                type="button"
                                variant="primary"
                                icon="paper-airplane"
                                class="shrink-0 rounded-full"
                                x-bind:disabled="isSubmitting || (creationKind === 'project' ? (!formData.project.name || !formData.project.name.trim()) : creationKind === 'schoolClass' ? !schoolClassCanSubmit() : (!formData.item.title || !formData.item.title.trim()))"
                                @click="creationKind === 'project' ? submitProject() : (creationKind === 'schoolClass' ? submitSchoolClass() : (creationKind === 'task' ? submitTask() : submitEvent()))"
                            />
                        </div>
                    </div>

                    <div class="relative z-[2] min-w-0 border-t border-border/40 pt-2">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
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
                            <template x-if="creationKind === 'schoolClass'">
                                <div class="contents">
                                    <x-workspace.creation-school-class-fields />
                                </div>
                            </template>

                            <div x-show="creationKind !== 'project' && creationKind !== 'schoolClass'" x-cloak class="contents">
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
                    </div>

                    <div
                        class="relative z-0 flex w-full flex-wrap items-center gap-2 border-t border-border/50 pt-2 text-[10px]"
                        x-show="creationKind !== 'project' && creationKind !== 'schoolClass'"
                        x-cloak
                    >
                        <x-workspace.tag-selection position="bottom" align="end" />
                    </div>
                </div>
            </form>
        </div>
    </template>

    <template x-if="showItemLoading">
        <div
            x-cloak
            x-transition.opacity.duration.200ms
            data-test="task-loading-card"
            aria-busy="true"
            class="list-item-card relative z-20 mt-4 flex flex-col overflow-hidden rounded-xl px-3 py-2 shadow-sm ring-1 ring-border/25 opacity-90 dark:ring-border/35"
            x-bind:class="creationCardSurfaceClass()"
        >
            <div
                class="relative h-px w-full shrink-0 overflow-hidden bg-border/60 dark:bg-border/40"
                aria-hidden="true"
            >
                <div class="loading-bar-track absolute left-0 top-0 h-full w-1/3 max-w-[120px] rounded-full bg-zinc-500 dark:bg-zinc-400"></div>
            </div>
            <div class="relative flex flex-col gap-2 pt-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate text-xl font-bold leading-tight md:text-2xl"
                            x-text="creationKind === 'project' ? formData.project.name : creationKind === 'schoolClass' ? formData.schoolClass.subjectName : formData.item.title"
                        ></p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
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
                    <template x-if="creationKind === 'project'">
                        <div class="flex flex-wrap items-center gap-2"></div>
                    </template>
                    <template x-if="creationKind === 'schoolClass'">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-medium dark:border-white/10"
                            >
                                <flux:icon name="user" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Teacher') }}:</span>
                                    <span class="max-w-[140px] truncate uppercase" x-text="formData.schoolClass.teacherName"></span>
                                </span>
                            </span>
                            <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                                <flux:icon name="clock" class="size-3 shrink-0" />
                                <span class="text-xs leading-tight" x-text="schoolClassLoadingScheduleLabel()"></span>
                            </span>
                        </div>
                    </template>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground" x-show="creationKind !== 'schoolClass'">
                        <flux:icon name="clock" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Start') }}:</span>
                            <span class="text-xs uppercase" x-text="(creationKind === 'project' ? formData.project.startDatetime : formData.item.startDatetime) ? formatDatetime(creationKind === 'project' ? formData.project.startDatetime : formData.item.startDatetime) : '{{ __('Not set') }}'"></span>
                        </span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground" x-show="creationKind !== 'schoolClass'">
                        <flux:icon name="clock" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70" x-text="creationKind === 'task' ? '{{ __('Due') }}:' : '{{ __('End') }}:'"></span>
                            <span class="text-xs uppercase" x-text="(creationKind === 'project' ? formData.project.endDatetime : formData.item.endDatetime) ? formatDatetime(creationKind === 'project' ? formData.project.endDatetime : formData.item.endDatetime) : '{{ __('Not set') }}'"></span>
                        </span>
                    </span>
                    <span
                        x-show="creationKind !== 'project' && creationKind !== 'schoolClass' && formData.item.projectId && projectNames[formData.item.projectId]"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10"
                    >
                        <flux:icon name="folder" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Project') }}:</span>
                            <span class="max-w-[120px] truncate uppercase" x-text="projectNames[formData.item.projectId] || ''"></span>
                        </span>
                    </span>
                </div>
                <div class="flex w-full shrink-0 flex-wrap items-center gap-2 border-t border-border/50 pt-2 text-[10px]" x-show="creationKind !== 'project' && creationKind !== 'schoolClass'" x-cloak>
                    <template x-for="tag in getSelectedTags()" :key="tag.id">
                        <span class="inline-flex items-center rounded-sm border border-black/10 bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground dark:border-white/10" x-text="tag.name"></span>
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
