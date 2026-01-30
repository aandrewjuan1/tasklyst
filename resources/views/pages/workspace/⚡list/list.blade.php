<div
    class="space-y-4"
    x-data="{
        showTaskCreation: false,
        showTaskLoading: false,
        loadingStartedAt: null,
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

            this.showTaskCreation = false;
            this.showTaskLoading = true;
            this.loadingStartedAt = Date.now();

            const payload = JSON.parse(JSON.stringify(this.formData.task));
            const minLoadingMs = 500;

            $wire.$parent.$call('createTask', payload)
                .finally(() => {
                    const elapsed = Date.now() - this.loadingStartedAt;
                    const remaining = Math.max(0, minLoadingMs - elapsed);
                    setTimeout(() => {
                        this.showTaskLoading = false;
                        this.isSubmitting = false;
                    }, remaining);
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
            const notSet = 'Not set';
            if (!datetimeString) return notSet;
            try {
                const date = new Date(datetimeString);
                if (isNaN(date.getTime())) return notSet;
                const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                return dateStr + ' ' + timeStr;
            } catch (e) {
                return notSet;
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
        getStatusBadgeClass(status) {
            const map = { to_do: 'bg-gray-800/10 text-gray-800', doing: 'bg-blue-800/10 text-blue-800', done: 'bg-green-800/10 text-green-800' };
            return map[status] || map.to_do;
        },
        getPriorityBadgeClass(priority) {
            const map = { low: 'bg-gray-800/10 text-gray-800', medium: 'bg-yellow-800/10 text-yellow-800', high: 'bg-orange-800/10 text-orange-800', urgent: 'bg-red-800/10 text-red-800' };
            return map[priority] || map.medium;
        },
        getComplexityBadgeClass(complexity) {
            const map = { simple: 'bg-green-800/10 text-green-800', moderate: 'bg-yellow-800/10 text-yellow-800', complex: 'bg-red-800/10 text-red-800' };
            return map[complexity] || map.moderate;
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

    <div
        x-show="showTaskLoading"
        x-cloak
        data-test="task-loading-card"
        class="mt-4 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm opacity-95 blur-[1px]"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <flux:icon name="loading" class="size-5 shrink-0 animate-spin text-muted-foreground" />
                <p class="truncate text-base font-semibold leading-tight" x-text="formData.task.title"></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex w-fit items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Task') }}
                </span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                :class="getStatusBadgeClass(formData.task.status)"
            >
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Status') }}:</span>
                    <span class="uppercase" x-text="statusLabel(formData.task.status)"></span>
                </span>
            </span>
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                :class="getPriorityBadgeClass(formData.task.priority)"
            >
                <flux:icon name="bolt" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Priority') }}:</span>
                    <span class="uppercase" x-text="priorityLabel(formData.task.priority)"></span>
                </span>
            </span>
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold dark:border-white/10"
                :class="getComplexityBadgeClass(formData.task.complexity)"
            >
                <flux:icon name="squares-2x2" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Complexity') }}:</span>
                    <span class="uppercase" x-text="complexityLabel(formData.task.complexity)"></span>
                </span>
            </span>
            <span
                x-show="formData.task.tagIds && formData.task.tagIds.length > 0"
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-sky-500/10 px-2.5 py-0.5 font-medium text-sky-500 dark:border-white/10"
            >
                <flux:icon name="tag" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Tags') }}:</span>
                    <span class="truncate max-w-[140px] uppercase" x-text="getSelectedTagNames()"></span>
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Duration') }}:</span>
                    <span class="uppercase" x-text="formatDurationLabel(formData.task.duration)"></span>
                </span>
            </span>
            <span
                x-show="formData.task.startDatetime"
                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
            >
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Start') }}:</span>
                    <span class="uppercase" x-text="formatDatetime(formData.task.startDatetime)"></span>
                </span>
            </span>
            <span
                x-show="formData.task.endDatetime"
                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
            >
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ __('Due') }}:</span>
                    <span class="uppercase" x-text="formatDatetime(formData.task.endDatetime)"></span>
                </span>
            </span>
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
            @foreach ([['kind' => 'project', 'items' => $projects], ['kind' => 'event', 'items' => $events], ['kind' => 'task', 'items' => $tasks]] as $group)
                <div class="space-y-3">
                    @foreach ($group['items'] as $item)
                        <x-workspace.list-item-card
                            :kind="$group['kind']"
                            :item="$item"
                            wire:key="{{ $group['kind'] }}-{{ $item->id }}"
                        />
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</div>
