<div
    class="space-y-4"
    x-data="{
        showTaskCreation: false,
        isSubmitting: false,
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
        resetForm() {
            this.formData.task.title = '';
            this.formData.task.status = 'to_do';
            this.formData.task.priority = 'medium';
            this.formData.task.complexity = 'moderate';
            this.formData.task.duration = '60';
        },
        submitTask() {
            if (this.isSubmitting) {
                return;
            }

            if (!this.formData.task.title || !this.formData.task.title.trim()) {
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
    }"
    x-init="
        window.addEventListener('task-created', () => {
            resetForm();
        });
    "
>
    <flux:dropdown position="right" align="start">
        <flux:button icon:trailing="plus-circle">Add</flux:button>
        <flux:navmenu>
            <flux:navmenu.item
                icon="rectangle-stack"
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
        @click.outside="showTaskCreation = false"
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
                                <span x-text="formData.task.status === 'to_do' ? '{{ __('To Do') }}' : formData.task.status === 'doing' ? '{{ __('Doing') }}' : '{{ __('Done') }}'"></span>
                            </flux:button>
                            <flux:menu>
                                <flux:menu.radio.group x-model="formData.task.status">
                                    <flux:menu.radio value="to_do">{{ __('To Do') }}</flux:menu.radio>
                                    <flux:menu.radio value="doing">{{ __('Doing') }}</flux:menu.radio>
                                    <flux:menu.radio value="done">{{ __('Done') }}</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
                                <span x-text="formData.task.priority === 'low' ? '{{ __('Low') }}' : formData.task.priority === 'medium' ? '{{ __('Medium') }}' : formData.task.priority === 'high' ? '{{ __('High') }}' : '{{ __('Urgent') }}'"></span>
                            </flux:button>
                            <flux:menu>
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
                                <span x-text="formData.task.complexity === 'simple' ? '{{ __('Simple') }}' : formData.task.complexity === 'moderate' ? '{{ __('Moderate') }}' : '{{ __('Complex') }}'"></span>
                            </flux:button>
                            <flux:menu>
                                <flux:menu.radio.group x-model="formData.task.complexity">
                                    <flux:menu.radio value="simple">{{ __('Simple') }}</flux:menu.radio>
                                    <flux:menu.radio value="moderate">{{ __('Moderate') }}</flux:menu.radio>
                                    <flux:menu.radio value="complex">{{ __('Complex') }}</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down" size="sm">
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
                                                        : '4 hours'"></span>
                            </flux:button>
                            <flux:menu>
                                <flux:menu.radio.group x-model="formData.task.duration">
                                    <flux:menu.radio value="15">15 min</flux:menu.radio>
                                    <flux:menu.radio value="30">30 min</flux:menu.radio>
                                    <flux:menu.radio value="60">1 hour</flux:menu.radio>
                                    <flux:menu.radio value="90">1.5 hours</flux:menu.radio>
                                    <flux:menu.radio value="120">2 hours</flux:menu.radio>
                                    <flux:menu.radio value="180">3 hours</flux:menu.radio>
                                    <flux:menu.radio value="240">4 hours</flux:menu.radio>
                                </flux:menu.radio.group>
                            </flux:menu>
                        </flux:dropdown>
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
