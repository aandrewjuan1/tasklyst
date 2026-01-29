<div
    class="space-y-4"
    x-data="{
        showTaskCreation: false,
        isSubmitting: false,
        messages: {
            taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
            taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
        },
        errors: {
            taskDateRange: null,
        },
        formData: {
            task: {
                title: '',
                status: 'to_do',
                priority: 'medium',
                complexity: 'moderate',
                duration: '60',
                startDatetime: null,
                endDatetime: null,
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
        validateTaskDateRange() {
            this.errors.taskDateRange = null;

            const start = this.formData.task.startDatetime;
            const end = this.formData.task.endDatetime;
            const durationMinutes = parseInt(this.formData.task.duration ?? '0', 10);

            if (!start || !end) {
                return true;
            }

            const startDate = new Date(start);
            const endDate = new Date(end);

            if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
                return true;
            }

            if (endDate.getTime() < startDate.getTime()) {
                this.errors.taskDateRange = this.messages.taskEndBeforeStart;
                return false;
            }

            const isSameDay = startDate.toDateString() === endDate.toDateString();
            if (isSameDay && Number.isFinite(durationMinutes) && durationMinutes > 0) {
                const minimumEnd = new Date(startDate.getTime() + (durationMinutes * 60 * 1000));

                if (endDate.getTime() < minimumEnd.getTime()) {
                    this.errors.taskDateRange = this.messages.taskEndTooSoon.replace(':minutes', String(durationMinutes));
                    return false;
                }
            }

            return true;
        },
        resetForm() {
            this.formData.task.title = '';
            this.formData.task.status = 'to_do';
            this.formData.task.priority = 'medium';
            this.formData.task.complexity = 'moderate';
            this.formData.task.duration = '60';
            this.formData.task.startDatetime = null;
            this.formData.task.endDatetime = null;
            this.errors.taskDateRange = null;
        },
        submitTask() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.task.title || !this.formData.task.title.trim()) {
                return;
            }

            if (!this.validateTaskDateRange()) {
                return;
            }

            this.isSubmitting = true;
            this.formData.task.title = this.formData.task.title.trim();

            const payload = JSON.parse(JSON.stringify(this.formData.task));

            $wire.$parent.$call('createTask', payload)
                .then(() => {
                    this.showTaskCreation = false;
                })
                .finally(() => {
                    this.isSubmitting = false;
                });
        },
        handleGlobalClick(event) {
            if (! this.showTaskCreation) {
                return;
            }

            const card = this.$refs.taskCreationCard;

            if (! card) {
                return;
            }

            const rect = card.getBoundingClientRect();
            const clickX = event.clientX;
            const clickY = event.clientY;

            const isWithinCardBounds =
                clickX >= rect.left
                && clickX <= rect.right
                && clickY >= rect.top
                && clickY <= rect.bottom;

            const isSafe = event.target.closest('[data-task-creation-safe]');

            if (! isWithinCardBounds && ! isSafe) {
                window.dispatchEvent(new CustomEvent('task-creation-outside-clicked'));
            }
        },
        formatDatetime(datetimeString) {
            if (!datetimeString) {
                return 'Not set';
            }

            try {
                const date = new Date(datetimeString);
                if (isNaN(date.getTime())) {
                    return 'Not set';
                }

                const dateStr = date.toLocaleDateString(undefined, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                });

                const timeStr = date.toLocaleTimeString(undefined, {
                    hour: 'numeric',
                    minute: '2-digit',
                });

                return dateStr + ' ' + timeStr;
            } catch (e) {
                return 'Not set';
            }
        },
    }"
    x-init="
        window.addEventListener('task-created', () => {
            resetForm();
        });

        window.addEventListener('click', (event) => {
            handleGlobalClick(event);
        });

        // Listen for date picker updates
        window.addEventListener('date-picker-updated', (event) => {
            const { path, value } = event.detail;
            const pathParts = path.split('.');
            let target = this;
            for (let i = 0; i < pathParts.length - 1; i++) {
                if (!target[pathParts[i]]) {
                    target[pathParts[i]] = {};
                }
                target = target[pathParts[i]];
            }
            target[pathParts[pathParts.length - 1]] = value;

            validateTaskDateRange();
        });
    "
    x-effect="
        formData.task.startDatetime;
        formData.task.endDatetime;
        formData.task.duration;
        validateTaskDateRange();
    "
>
    <flux:dropdown position="right" align="start">
        <flux:button icon:trailing="plus-circle">Add</flux:button>
        <flux:navmenu>
            <flux:navmenu.item
                icon="rectangle-stack"
                data-task-creation-safe
                @click="
                    showTaskCreation = !showTaskCreation;
                    if (showTaskCreation) {
                        $nextTick(() => $refs.taskTitle?.focus());
                    }
                "
            >
                Task
            </flux:navmenu.item>
            <flux:navmenu.item icon="calendar-days">Event</flux:navmenu.item>
            <flux:navmenu.item icon="clipboard-document-list" variant="danger">Project</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <!-- Inline task creation card -->
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
                    <span class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
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
                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Status:') }}
                                </span>
                                <span x-text="formData.task.status === 'to_do' ? '{{ __('To Do') }}' : formData.task.status === 'doing' ? '{{ __('Doing') }}' : '{{ __('Done') }}'"></span>
                            </flux:button>
                            <flux:menu data-task-creation-safe>
                                <flux:menu.radio.group x-model="formData.task.status">
                                    <flux:menu.radio value="to_do">{{ __('To Do') }}</flux:menu.radio>
                                    <flux:menu.radio value="doing">{{ __('Doing') }}</flux:menu.radio>
                                    <flux:menu.radio value="done">{{ __('Done') }}</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Priority:') }}
                                </span>
                                <span x-text="formData.task.priority === 'low' ? '{{ __('Low') }}' : formData.task.priority === 'medium' ? '{{ __('Medium') }}' : formData.task.priority === 'high' ? '{{ __('High') }}' : '{{ __('Urgent') }}'"></span>
                            </flux:button>
                            <flux:menu data-task-creation-safe>
                                <flux:menu.radio.group x-model="formData.task.priority">
                                    <flux:menu.radio value="low">{{ __('Low') }}</flux:menu.radio>
                                    <flux:menu.radio value="medium">{{ __('Medium') }}</flux:menu.radio>
                                    <flux:menu.radio value="high">{{ __('High') }}</flux:menu.radio>
                                    <flux:menu.radio value="urgent">{{ __('Urgent') }}</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Complexity:') }}
                                </span>
                                <span x-text="formData.task.complexity === 'simple' ? '{{ __('Simple') }}' : formData.task.complexity === 'moderate' ? '{{ __('Moderate') }}' : '{{ __('Complex') }}'"></span>
                            </flux:button>
                            <flux:menu data-task-creation-safe>
                                <flux:menu.radio.group x-model="formData.task.complexity">
                                    <flux:menu.radio value="simple">{{ __('Simple') }}</flux:menu.radio>
                                    <flux:menu.radio value="moderate">{{ __('Moderate') }}</flux:menu.radio>
                                    <flux:menu.radio value="complex">{{ __('Complex') }}</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Duration:') }}
                                </span>
                                <span x-text="formData.task.duration == '15'
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
                            </flux:button>
                            <flux:menu data-task-creation-safe>
                                <flux:menu.radio.group x-model="formData.task.duration">
                                    <flux:menu.radio value="15">15 min</flux:menu.radio>
                                    <flux:menu.radio value="30">30 min</flux:menu.radio>
                                    <flux:menu.radio value="60">1 hour</flux:menu.radio>
                                    <flux:menu.radio value="90">1.5 hours</flux:menu.radio>
                                    <flux:menu.radio value="120">2 hours</flux:menu.radio>
                                    <flux:menu.radio value="180">3 hours</flux:menu.radio>
                                    <flux:menu.radio value="240">4 hours</flux:menu.radio>
                                    <flux:menu.radio value="480">8+ hours</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm" data-task-creation-safe>
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Start:') }}
                                </span>
                                <span x-text="formatDatetime(formData.task.startDatetime)"></span>
                            </flux:button>
                            <flux:menu keep-open data-task-creation-safe>
                                <x-date-picker
                                    label="{{ __('Start Date') }}"
                                    model="formData.task.startDatetime"
                                    type="datetime-local"
                                />
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm" data-task-creation-safe>
                                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('End:') }}
                                </span>
                                <span x-text="formatDatetime(formData.task.endDatetime)"></span>
                            </flux:button>
                            <flux:menu keep-open data-task-creation-safe>
                                <x-date-picker
                                    label="{{ __('End Date') }}"
                                    model="formData.task.endDatetime"
                                    type="datetime-local"
                                />
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

    @if($projects->isEmpty() && $events->isEmpty() && $tasks->isEmpty())
        <div class="mt-6 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur">
            <div class="flex items-center gap-2">
                <flux:icon name="inbox" class="size-5 text-muted-foreground/50" />
                <flux:text class="text-sm font-medium text-muted-foreground">
                    {{ __('No items yet') }}
                </flux:text>
            </div>
            <flux:text class="text-xs text-muted-foreground/70">
                {{ __('Create your first task, project, or event to get started') }}
            </flux:text>
        </div>
    @else
        <div class="space-y-4">
            <div class="space-y-3">
                @foreach ($projects as $project)
                    <x-workspace.project-list-card
                        :$project
                        wire:key="project-{{ $project->id }}"
                    />
                @endforeach
            </div>

            <div class="space-y-3">
                @foreach ($events as $event)
                    <x-workspace.event-list-card
                        :$event
                        wire:key="event-{{ $event->id }}"
                    />
                @endforeach
            </div>

            <div class="space-y-3">
                @foreach ($tasks as $task)
                    <x-workspace.task-list-card
                        :$task
                        wire:key="task-{{ $task->id }}"
                    />
                @endforeach
            </div>
        </div>
    @endif
</div>
