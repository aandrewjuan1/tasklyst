@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
@endphp
<div class="relative z-10">
    <flux:dropdown position="right" align="start">
        <flux:button icon:trailing="plus-circle" data-task-creation-safe>
            {{ __('Add') }}
        </flux:button>

        <flux:menu>
            <flux:menu.item
                icon="rectangle-stack"
                @click="
                    showTaskCreation = !showTaskCreation;
                    if (showTaskCreation) {
                        $nextTick(() => $refs.taskTitle?.focus());
                    }
                "
            >
                {{ __('Task') }}
            </flux:menu.item>
            <flux:menu.item icon="calendar-days">
                {{ __('Event') }}
            </flux:menu.item>
            <flux:menu.item variant="danger" icon="clipboard-document-list">
                {{ __('Project') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>

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
    class="relative mt-4 flex flex-col gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3 shadow-md ring-1 ring-border/20"
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
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                                x-bind:class="[getStatusBadgeClass(formData.task.status), open && 'shadow-md scale-[1.02]']"
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
                                    @click="$dispatch('task-form-updated', { path: 'formData.task.status', value: '{{ $opt['value'] }}' })"
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
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                                x-bind:class="[getPriorityBadgeClass(formData.task.priority), open && 'shadow-md scale-[1.02]']"
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
                                    @click="$dispatch('task-form-updated', { path: 'formData.task.priority', value: '{{ $opt['value'] }}' })"
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
                                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                                x-bind:class="[getComplexityBadgeClass(formData.task.complexity), open && 'shadow-md scale-[1.02]']"
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
                                    @click="$dispatch('task-form-updated', { path: 'formData.task.complexity', value: '{{ $opt['value'] }}' })"
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
                                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
                                :class="{ 'shadow-md scale-[1.02]': open }"
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
                                    @click="$dispatch('task-form-updated', { path: 'formData.task.duration', value: '{{ $dur['value'] }}' })"
                                >
                                    {{ $dur['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </x-simple-select-dropdown>

                    <x-workspace.tag-selection position="bottom" align="end" />

                    @foreach ([['label' => __('Start'), 'model' => 'formData.task.startDatetime', 'datePickerLabel' => __('Start Date')], ['label' => __('End'), 'model' => 'formData.task.endDatetime', 'datePickerLabel' => __('End Date')]] as $dateField)
                        <x-date-picker
                            :triggerLabel="$dateField['label']"
                            :label="$dateField['datePickerLabel']"
                            :model="$dateField['model']"
                            type="datetime-local"
                            position="bottom"
                            align="end"
                        />
                    @endforeach

                    <x-recurring-selection
                        triggerLabel="{{ __('Recurring') }}"
                        position="bottom"
                        align="end"
                    />

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
