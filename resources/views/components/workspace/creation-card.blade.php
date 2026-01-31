@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
@endphp
<div class="relative z-10">
    <x-dropdown position="right" align="start">
        <x-slot:trigger>
            <flux:button icon:trailing="plus-circle" data-task-creation-safe>
                {{ __('Add') }}
            </flux:button>
        </x-slot:trigger>

        <div class="flex flex-col py-1" data-task-creation-safe>
            @foreach ([
                ['icon' => 'rectangle-stack', 'label' => __('Task'), 'variant' => 'default', 'taskClick' => true],
                ['icon' => 'calendar-days', 'label' => __('Event'), 'variant' => 'default', 'taskClick' => false],
                ['icon' => 'clipboard-document-list', 'label' => __('Project'), 'variant' => 'destructive', 'taskClick' => false],
            ] as $addItem)
                <button
                    type="button"
                    @if ($addItem['taskClick'])
                        @click="
                            showTaskCreation = !showTaskCreation;
                            if (showTaskCreation) {
                                $nextTick(() => $refs.taskTitle?.focus());
                            }
                        "
                    @endif
                    class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-left {{ $addItem['variant'] === 'destructive' ? 'hover:bg-destructive/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive' : 'hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring' }}"
                >
                    <flux:icon name="{{ $addItem['icon'] }}" class="size-4 {{ $addItem['variant'] === 'destructive' ? 'text-destructive' : 'text-muted-foreground' }}" />
                    <span>{{ $addItem['label'] }}</span>
                </button>
            @endforeach
        </div>
    </x-dropdown>

    <div
    x-show="showTaskCreation"
    x-transition
    x-ref="taskCreationCard"
    @click.outside="
        const target = $event.target;
        const isSafe = target.closest('[data-task-creation-safe]');
        // Also check if clicking on a dropdown panel (which might be positioned outside the card)
        const isDropdownPanel = target.closest('.absolute.z-50');
        if (!isSafe && !isDropdownPanel) {
            showTaskCreation = false;
        }
    "
    class="mt-4 flex flex-col gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3 shadow-md backdrop-blur ring-1 ring-border/20"
    x-cloak
>
    <div class="flex items-start justify-between gap-3">
        <form class="min-w-0 flex-1" @submit.prevent="submitTask()">
            <div class="flex flex-col gap-2">
                <span class="inline-flex w-fit items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Task') }}
                </span>

                <div class="flex items-center gap-2">
                    <flux:input
                        x-model="formData.task.title"
                        x-ref="taskTitle"
                        x-bind:disabled="isSubmitting"
                        placeholder="{{ __('Enter task title...') }}"
                        class="flex-1 text-sm font-medium"
                        @keydown.enter.prevent="if (!isSubmitting && formData.task.title && formData.task.title.trim()) submitTask()"
                    />

                    <flux:button
                        type="button"
                        variant="primary"
                        icon="paper-airplane"
                        class="shrink-0 rounded-full"
                        x-bind:disabled="isSubmitting || !formData.task.title || !formData.task.title.trim()"
                        @click="submitTask()"
                    />
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-simple-select-dropdown position="top" align="end">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                                x-bind:class="getStatusBadgeClass(formData.task.status)"
                                data-task-creation-safe
                                aria-haspopup="menu"
                            >
                                <flux:icon name="check-circle" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        {{ __('Status') }}:
                                    </span>
                                    <span class="text-xs uppercase" x-text="statusLabel(formData.task.status)"></span>
                                </span>
                                <flux:icon name="chevron-down" class="size-3" />
                            </button>
                        </x-slot:trigger>

                        <div class="flex flex-col py-1" data-task-creation-safe>
                            @foreach ([['value' => 'to_do', 'label' => __('To Do')], ['value' => 'doing', 'label' => __('Doing')], ['value' => 'done', 'label' => __('Done')]] as $opt)
                                <button
                                    type="button"
                                    class="{{ $dropdownItemClass }}"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.status === '{{ $opt['value'] }}' }"
                                    @click="formData.task.status = '{{ $opt['value'] }}'"
                                >
                                    {{ $opt['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </x-simple-select-dropdown>

                    <x-simple-select-dropdown position="top" align="end">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                                x-bind:class="getPriorityBadgeClass(formData.task.priority)"
                                data-task-creation-safe
                                aria-haspopup="menu"
                            >
                                <flux:icon name="bolt" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        {{ __('Priority') }}:
                                    </span>
                                    <span class="text-xs uppercase" x-text="priorityLabel(formData.task.priority)"></span>
                                </span>
                                <flux:icon name="chevron-down" class="size-3" />
                            </button>
                        </x-slot:trigger>

                        <div class="flex flex-col py-1" data-task-creation-safe>
                            @foreach ([['value' => 'low', 'label' => __('Low')], ['value' => 'medium', 'label' => __('Medium')], ['value' => 'high', 'label' => __('High')], ['value' => 'urgent', 'label' => __('Urgent')]] as $opt)
                                <button
                                    type="button"
                                    class="{{ $dropdownItemClass }}"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.priority === '{{ $opt['value'] }}' }"
                                    @click="formData.task.priority = '{{ $opt['value'] }}'"
                                >
                                    {{ $opt['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </x-simple-select-dropdown>

                    <x-simple-select-dropdown position="top" align="end">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                                x-bind:class="getComplexityBadgeClass(formData.task.complexity)"
                                data-task-creation-safe
                                aria-haspopup="menu"
                            >
                                <flux:icon name="squares-2x2" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        {{ __('Complexity') }}:
                                    </span>
                                    <span class="text-xs uppercase" x-text="complexityLabel(formData.task.complexity)"></span>
                                </span>
                                <flux:icon name="chevron-down" class="size-3" />
                            </button>
                        </x-slot:trigger>

                        <div class="flex flex-col py-1" data-task-creation-safe>
                            @foreach ([['value' => 'simple', 'label' => __('Simple')], ['value' => 'moderate', 'label' => __('Moderate')], ['value' => 'complex', 'label' => __('Complex')]] as $opt)
                                <button
                                    type="button"
                                    class="{{ $dropdownItemClass }}"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.complexity === '{{ $opt['value'] }}' }"
                                    @click="formData.task.complexity = '{{ $opt['value'] }}'"
                                >
                                    {{ $opt['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </x-simple-select-dropdown>

                    <x-simple-select-dropdown position="top" align="end">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                                data-task-creation-safe
                                aria-haspopup="menu"
                            >
                                <flux:icon name="clock" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        {{ __('Duration') }}:
                                    </span>
                                    <span class="text-xs uppercase" x-text="formatDurationLabel(formData.task.duration)"></span>
                                </span>
                                <flux:icon name="chevron-down" class="size-3" />
                            </button>
                        </x-slot:trigger>

                        <div class="flex flex-col py-1" data-task-creation-safe>
                            @foreach ([['value' => '15', 'label' => '15 min'], ['value' => '30', 'label' => '30 min'], ['value' => '60', 'label' => '1 hour'], ['value' => '120', 'label' => '2 hours'], ['value' => '240', 'label' => '4 hours'], ['value' => '480', 'label' => '8+ hours']] as $dur)
                                <button
                                    type="button"
                                    class="{{ $dropdownItemClass }}"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '{{ $dur['value'] }}' }"
                                    @click="formData.task.duration = '{{ $dur['value'] }}'"
                                >
                                    {{ $dur['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </x-simple-select-dropdown>

                    <x-dropdown position="top" align="end" :keep-open="true" x-ref="tagsDropdown">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                                data-task-creation-safe
                                aria-haspopup="menu"
                            >
                                <flux:icon name="tag" class="size-3" />
                                <span class="inline-flex items-baseline gap-1">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        {{ __('Tags') }}:
                                    </span>
                                    <span class="text-xs uppercase" x-text="formData.task.tagIds && formData.task.tagIds.length > 0 ? formData.task.tagIds.length : '{{ __('None') }}'"></span>
                                </span>
                                <flux:icon name="chevron-down" class="size-3" />
                            </button>
                        </x-slot:trigger>

                        <div wire:ignore class="flex flex-col gap-2 py-1" data-task-creation-safe>
                            <!-- Inline tag creation -->
                            <div class="flex items-center gap-1.5 px-3 py-1.5 border-b border-border/60">
                                <flux:input
                                    x-model="newTagName"
                                    x-ref="newTagInput"
                                    placeholder="{{ __('Create tag...') }}"
                                    size="sm"
                                    class="flex-1"
                                    @keydown.enter.prevent="createTagOptimistic()"
                                />
                                <button
                                    type="button"
                                    @click="createTagOptimistic()"
                                    x-bind:disabled="!newTagName || !newTagName.trim() || creatingTag"
                                    class="shrink-0 rounded-md p-1 hover:bg-muted/80 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <flux:icon name="paper-airplane" class="size-3.5" />
                                </button>
                            </div>

                            <!-- Tag list with checkboxes -->
                            <div class="max-h-40 overflow-y-auto">
                                <template x-for="tag in tags || []" :key="tag.id">
                                    <label
                                        class="group flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        @click="toggleTag(tag.id); $event.preventDefault()"
                                    >
                                        <flux:checkbox
                                            x-bind:checked="isTagSelected(tag.id)"
                                        />
                                        <span x-text="tag.name" class="flex-1"></span>
                                        <flux:tooltip :content="__('Delete tag')" position="right">
                                            <button
                                                type="button"
                                                @click.stop="deleteTagOptimistic(tag)"
                                                x-bind:disabled="deletingTagIds?.has(tag.id)"
                                                class="shrink-0 rounded p-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                                aria-label="{{ __('Delete tag') }}"
                                            >
                                                <flux:icon name="x-mark" class="size-3.5" />
                                            </button>
                                        </flux:tooltip>
                                    </label>
                                </template>
                                <div x-show="!tags || tags.length === 0" class="px-3 py-2 text-sm text-muted-foreground">
                                    {{ __('No tags available') }}
                                </div>
                            </div>
                        </div>
                    </x-dropdown>

                    @foreach ([['label' => __('Start'), 'model' => 'formData.task.startDatetime', 'ref' => 'startDateDropdown', 'datePickerLabel' => __('Start Date')], ['label' => __('End'), 'model' => 'formData.task.endDatetime', 'ref' => 'endDateDropdown', 'datePickerLabel' => __('End Date')]] as $dateField)
                        <x-dropdown position="top" align="end" :keep-open="true" x-ref="{{ $dateField['ref'] }}">
                            <x-slot:trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                                    data-task-creation-safe
                                    aria-haspopup="menu"
                                >
                                    <flux:icon name="clock" class="size-3" />
                                    <span class="inline-flex items-baseline gap-1">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                            {{ $dateField['label'] }}:
                                        </span>
                                        <span class="text-xs uppercase" x-text="formatDatetime({{ $dateField['model'] }})"></span>
                                    </span>
                                    <flux:icon name="chevron-down" class="size-3" />
                                </button>
                            </x-slot:trigger>

                            <div class="p-3" data-task-creation-safe>
                                <x-date-picker
                                    label="{{ $dateField['datePickerLabel'] }}"
                                    :model="$dateField['model']"
                                    type="datetime-local"
                                />
                            </div>
                        </x-dropdown>
                    @endforeach

                    <div class="flex w-full items-center gap-1.5" x-show="errors.taskDateRange" x-cloak>
                        <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
                        <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="errors.taskDateRange"></p>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
