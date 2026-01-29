<flux:dropdown position="right" align="start">
    <flux:button icon:trailing="plus-circle" data-task-creation-safe>
        {{ __('Add') }}
    </flux:button>

    <flux:menu data-task-creation-safe>
        <div class="flex flex-col py-1">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="
                    showTaskCreation = !showTaskCreation;
                    if (showTaskCreation) {
                        $nextTick(() => $refs.taskTitle?.focus());
                    }
                "
            >
                <flux:icon name="rectangle-stack" class="size-4 text-muted-foreground" />
                <span>{{ __('Task') }}</span>
            </button>

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <flux:icon name="calendar-days" class="size-4 text-muted-foreground" />
                <span>{{ __('Event') }}</span>
            </button>

            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-left hover:bg-destructive/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive"
            >
                <flux:icon name="clipboard-document-list" class="size-4 text-destructive" />
                <span>{{ __('Project') }}</span>
            </button>
        </div>
    </flux:menu>
</flux:dropdown>

<div
    x-show="showTaskCreation"
    x-transition
    x-ref="taskCreationCard"
    @task-creation-outside-clicked.window="showTaskCreation = false"
    class="mt-4 flex flex-col gap-3 rounded-xl border border-border/60 bg-background/60 px-4 py-3 shadow-sm backdrop-blur"
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
                    <flux:dropdown position="top" align="end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                            x-bind:class="formData.task.status === 'to_do' ? 'bg-gray-800/10 text-gray-800' : formData.task.status === 'doing' ? 'bg-blue-800/10 text-blue-800' : 'bg-green-800/10 text-green-800'"
                            data-task-creation-safe
                        >
                            <flux:icon name="check-circle" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Status') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formData.task.status === 'to_do' ? '{{ __('To Do') }}' : formData.task.status === 'doing' ? '{{ __('Doing') }}' : '{{ __('Done') }}'"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <flux:menu.radio.group>
                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.status === 'to_do' }"
                                    @click="formData.task.status = 'to_do'"
                                >
                                    {{ __('To Do') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.status === 'doing' }"
                                    @click="formData.task.status = 'doing'"
                                >
                                    {{ __('Doing') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.status === 'done' }"
                                    @click="formData.task.status = 'done'"
                                >
                                    {{ __('Done') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown position="top" align="end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                            x-bind:class="formData.task.priority === 'low' ? 'bg-gray-800/10 text-gray-800' : formData.task.priority === 'medium' ? 'bg-yellow-800/10 text-yellow-800' : formData.task.priority === 'high' ? 'bg-orange-800/10 text-orange-800' : 'bg-red-800/10 text-red-800'"
                            data-task-creation-safe
                        >
                            <flux:icon name="bolt" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Priority') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formData.task.priority === 'low' ? '{{ __('Low') }}' : formData.task.priority === 'medium' ? '{{ __('Medium') }}' : formData.task.priority === 'high' ? '{{ __('High') }}' : '{{ __('Urgent') }}'"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <flux:menu.radio.group>
                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.priority === 'low' }"
                                    @click="formData.task.priority = 'low'"
                                >
                                    {{ __('Low') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.priority === 'medium' }"
                                    @click="formData.task.priority = 'medium'"
                                >
                                    {{ __('Medium') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.priority === 'high' }"
                                    @click="formData.task.priority = 'high'"
                                >
                                    {{ __('High') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.priority === 'urgent' }"
                                    @click="formData.task.priority = 'urgent'"
                                >
                                    {{ __('Urgent') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown position="top" align="end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                            x-bind:class="formData.task.complexity === 'simple' ? 'bg-green-800/10 text-green-800' : formData.task.complexity === 'moderate' ? 'bg-yellow-800/10 text-yellow-800' : 'bg-red-800/10 text-red-800'"
                            data-task-creation-safe
                        >
                            <flux:icon name="squares-2x2" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Complexity') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formData.task.complexity === 'simple' ? '{{ __('Simple') }}' : formData.task.complexity === 'moderate' ? '{{ __('Moderate') }}' : '{{ __('Complex') }}'"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <flux:menu.radio.group>
                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.complexity === 'simple' }"
                                    @click="formData.task.complexity = 'simple'"
                                >
                                    {{ __('Simple') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.complexity === 'moderate' }"
                                    @click="formData.task.complexity = 'moderate'"
                                >
                                    {{ __('Moderate') }}
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.complexity === 'complex' }"
                                    @click="formData.task.complexity = 'complex'"
                                >
                                    {{ __('Complex') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown position="top" align="end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                            data-task-creation-safe
                        >
                            <flux:icon name="clock" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Duration') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formData.task.duration == '15'
                                    ? '15 min'
                                    : formData.task.duration == '30'
                                        ? '30 min'
                                        : formData.task.duration == '60'
                                            ? '1 hour'
                                            : formData.task.duration == '90'
                                                ? '1.5 hours'
                                                : formData.task.duration == '120'
                                                    ? '2 hours'
                                                    : formData.task.duration == '180'
                                                        ? '3 hours'
                                                        : formData.task.duration == '240'
                                                            ? '4 hours'
                                                            : '8+ hours'"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <flux:menu.radio.group>
                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '15' }"
                                    @click="formData.task.duration = '15'"
                                >
                                    15 min
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '30' }"
                                    @click="formData.task.duration = '30'"
                                >
                                    30 min
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '60' }"
                                    @click="formData.task.duration = '60'"
                                >
                                    1 hour
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '90' }"
                                    @click="formData.task.duration = '90'"
                                >
                                    1.5 hours
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '120' }"
                                    @click="formData.task.duration = '120'"
                                >
                                    2 hours
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '180' }"
                                    @click="formData.task.duration = '180'"
                                >
                                    3 hours
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '240' }"
                                    @click="formData.task.duration = '240'"
                                >
                                    4 hours
                                </flux:menu.item>

                                <flux:menu.item
                                    as="button"
                                    type="button"
                                    x-bind:class="{ 'font-semibold text-foreground': formData.task.duration == '480' }"
                                    @click="formData.task.duration = '480'"
                                >
                                    8+ hours
                                </flux:menu.item>
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown position="top" align="end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                            data-task-creation-safe
                        >
                            <flux:icon name="clock" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Start') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formatDatetime(formData.task.startDatetime)"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <div class="p-3">
                                <x-date-picker
                                    label="{{ __('Start Date') }}"
                                    model="formData.task.startDatetime"
                                    type="datetime-local"
                                />
                            </div>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
                            data-task-creation-safe
                        >
                            <flux:icon name="clock" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('End') }}:
                                </span>
                                <span class="text-xs uppercase" x-text="formatDatetime(formData.task.endDatetime)"></span>
                            </span>
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>

                        <flux:menu data-task-creation-safe>
                            <div class="p-3">
                                <x-date-picker
                                    label="{{ __('End Date') }}"
                                    model="formData.task.endDatetime"
                                    type="datetime-local"
                                />
                            </div>
                        </flux:menu>
                    </flux:dropdown>

                    <div class="w-full" x-show="errors.taskDateRange" x-cloak>
                        <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="errors.taskDateRange"></p>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
