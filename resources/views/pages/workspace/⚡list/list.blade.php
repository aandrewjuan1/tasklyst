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
            this.formData.task.tagIds = [];
            this.errors.taskDateRange = null;
        },
        toggleTag(tagId) {
            const index = this.formData.task.tagIds.indexOf(tagId);
            if (index === -1) {
                this.formData.task.tagIds.push(tagId);
            } else {
                this.formData.task.tagIds.splice(index, 1);
            }
        },
        isTagSelected(tagId) {
            return this.formData.task.tagIds.includes(tagId);
        },
        getSelectedTagNames() {
            if (!window.tags || !this.formData.task.tagIds || this.formData.task.tagIds.length === 0) {
                return '';
            }
            const selectedIds = this.formData.task.tagIds;
            const selectedTags = window.tags.filter(tag => selectedIds.includes(tag.id));
            return selectedTags.map(tag => tag.name).join(', ');
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
                    return '8+ {{ \Illuminate\Support\Str::plural(__('hour'), 8) }}';
                default:
                    return '{{ __('Not set') }}';
            }
        },
    }"
    x-init="
        window.tags = @js($tags);

        window.addEventListener('task-created', () => {
            resetForm();
        });

        window.addEventListener('tag-created', (event) => {
            const { id, name } = event.detail;
            
            // Add new tag to local tags array immediately
            if (window.tags && !window.tags.find(tag => tag.id === id)) {
                window.tags.push({ id, name });
                window.tags.sort((a, b) => a.name.localeCompare(b.name));
            }
            
            // Automatically select the newly created tag
            if (!this.formData.task.tagIds.includes(id)) {
                this.formData.task.tagIds.push(id);
            }
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
    <x-workspace.creation-card :tags="$tags" />

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
                    <x-workspace.list-item-card
                        kind="project"
                        :item="$project"
                        wire:key="project-{{ $project->id }}"
                    />
                @endforeach
            </div>

            <div class="space-y-3">
                @foreach ($events as $event)
                    <x-workspace.list-item-card
                        kind="event"
                        :item="$event"
                        wire:key="event-{{ $event->id }}"
                    />
                @endforeach
            </div>

            <div class="space-y-3">
                @foreach ($tasks as $task)
                    <x-workspace.list-item-card
                        kind="task"
                        :item="$task"
                        wire:key="task-{{ $task->id }}"
                    />
                @endforeach
            </div>
        </div>
    @endif
</div>
