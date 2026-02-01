@props([
    'kind',
    'item',
])

@php
    $kind = strtolower((string) $kind);

    $title = match ($kind) {
        'project' => $item->name,
        'event' => $item->title,
        'task' => $item->title,
        default => '',
    };

    $description = match ($kind) {
        'project' => $item->description,
        'event' => $item->description,
        'task' => $item->description,
        default => null,
    };

    $type = match ($kind) {
        'project' => __('Project'),
        'event' => __('Event'),
        'task' => __('Task'),
        default => null,
    };

    $deleteMethod = match ($kind) {
        'project' => 'deleteProject',
        'event' => 'deleteEvent',
        'task' => 'deleteTask',
        default => null,
    };

    $updatePropertyMethod = match ($kind) {
        'task' => 'updateTaskProperty',
        default => null,
    };

    if ($kind === 'task') {
        $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
        $statusOptions = [
            ['value' => 'to_do', 'label' => __('To Do'), 'color' => \App\Enums\TaskStatus::ToDo->color()],
            ['value' => 'doing', 'label' => __('Doing'), 'color' => \App\Enums\TaskStatus::Doing->color()],
            ['value' => 'done', 'label' => __('Done'), 'color' => \App\Enums\TaskStatus::Done->color()],
        ];
        $priorityOptions = [
            ['value' => 'low', 'label' => __('Low'), 'color' => \App\Enums\TaskPriority::Low->color()],
            ['value' => 'medium', 'label' => __('Medium'), 'color' => \App\Enums\TaskPriority::Medium->color()],
            ['value' => 'high', 'label' => __('High'), 'color' => \App\Enums\TaskPriority::High->color()],
            ['value' => 'urgent', 'label' => __('Urgent'), 'color' => \App\Enums\TaskPriority::Urgent->color()],
        ];
        $complexityOptions = [
            ['value' => 'simple', 'label' => __('Simple'), 'color' => \App\Enums\TaskComplexity::Simple->color()],
            ['value' => 'moderate', 'label' => __('Moderate'), 'color' => \App\Enums\TaskComplexity::Moderate->color()],
            ['value' => 'complex', 'label' => __('Complex'), 'color' => \App\Enums\TaskComplexity::Complex->color()],
        ];
        $durationOptions = [
            ['value' => 15, 'label' => '15 min'],
            ['value' => 30, 'label' => '30 min'],
            ['value' => 60, 'label' => '1 hour'],
            ['value' => 120, 'label' => '2 hours'],
            ['value' => 240, 'label' => '4 hours'],
            ['value' => 480, 'label' => '8+ hours'],
        ];

        $statusInitialOption = collect($statusOptions)->firstWhere('value', $item->status?->value);
        $priorityInitialOption = collect($priorityOptions)->firstWhere('value', $item->priority?->value);
        $complexityInitialOption = collect($complexityOptions)->firstWhere('value', $item->complexity?->value);

        $statusInitialClass = $statusInitialOption
            ? 'bg-' . $statusInitialOption['color'] . '/10 text-' . $statusInitialOption['color']
            : 'bg-muted text-muted-foreground';
        $priorityInitialClass = $priorityInitialOption
            ? 'bg-' . $priorityInitialOption['color'] . '/10 text-' . $priorityInitialOption['color']
            : 'bg-muted text-muted-foreground';
        $complexityInitialClass = $complexityInitialOption
            ? 'bg-' . $complexityInitialOption['color'] . '/10 text-' . $complexityInitialOption['color']
            : 'bg-muted text-muted-foreground';

        $durationInitialLabel = '';
        if ($item->duration !== null) {
            $m = (int) $item->duration;
            if ($m < 60) {
                $durationInitialLabel = $m . ' ' . __('min');
            } else {
                $hours = (int) ceil($m / 60);
                $remainder = $m % 60;
                $hourWord = $hours === 1 ? __('hour') : \Illuminate\Support\Str::plural(__('hour'), 2);
                $durationInitialLabel = $hours . ' ' . $hourWord;
                if ($remainder) {
                    $durationInitialLabel .= ' ' . $remainder . ' ' . __('min');
                }
            }
        }

        $startDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
        $endDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');
    }
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
    x-data="{
        deletingInProgress: false,
        hideCard: false,
        dropdownOpenCount: 0,
        deleteMethod: @js($deleteMethod),
        itemId: @js($item->id),
        deleteErrorToast: @js(__('Something went wrong. Please try again.')),
        async deleteItem() {
            if (this.deletingInProgress || this.hideCard || !this.deleteMethod || this.itemId == null) return;
            this.deletingInProgress = true;
            try {
                const ok = await $wire.$parent.$call(this.deleteMethod, this.itemId);
                if (ok) {
                    this.hideCard = true;
                } else {
                    this.deletingInProgress = false;
                    $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                this.deletingInProgress = false;
                $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
            }
        }
    }"
    x-show="!hideCard"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @dropdown-opened="dropdownOpenCount++"
    @dropdown-closed="dropdownOpenCount--"
    :class="{ 'relative z-50': dropdownOpenCount > 0, 'pointer-events-none opacity-60': deletingInProgress }"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-base font-semibold leading-tight">
                {{ $title }}
            </p>

            @if($description)
                <p class="mt-0.5 line-clamp-2 text-xs text-foreground/70">
                    {{ $description }}
                </p>
            @endif
        </div>

        @if($type)
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ $type }}
                </span>

                @if($deleteMethod)
                    <flux:dropdown>
                        <flux:button size="xs" icon="ellipsis-horizontal" />

                        <flux:menu>
                            <flux:menu.separator />

                            <flux:menu.item
                                variant="danger"
                                icon="trash"
                                @click.throttle.250ms="deleteItem()"
                            >
                                Delete
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
    @if($kind === 'project')
        @if($item->user)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10">
                <flux:icon name="user" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Owner') }}:
                    </span>
                    <span class="truncate max-w-[140px] uppercase">
                        {{ $item->user->name }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->start_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Start') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->start_datetime->toDateString() }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->end_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Due') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->end_datetime->toDateString() }}
                    </span>
                </span>
            </span>
        @endif

        <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-amber-500/10 px-2.5 py-0.5 font-medium text-amber-500 dark:border-white/10">
            <flux:icon name="list-bullet" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Tasks') }}:
                </span>
                <span>
                    {{ $item->tasks->count() }}
                </span>
            </span>
        </span>

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />

    @elseif($kind === 'event')
        @if($item->timezone)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="globe-alt" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Time zone') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">
                        {{ $item->timezone }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->status)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->status->color() }}/10 px-2.5 py-0.5 font-semibold text-{{ $item->status->color() }} dark:border-white/10"
            >
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Status') }}:
                    </span>
                    <span class="uppercase">{{ $item->status->value }}</span>
                </span>
            </span>
        @endif

        @if($item->all_day)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-500 dark:border-white/10">
                <flux:icon name="sun" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Time') }}:
                    </span>
                    <span class="uppercase">
                        {{ __('All day') }}
                    </span>
                </span>
            </span>
        @elseif($item->start_datetime || $item->end_datetime)
            @if($item->start_datetime)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                    <flux:icon name="clock" class="size-3" />
                    <span class="inline-flex items-baseline gap-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                            {{ __('Start') }}:
                        </span>
                        <span class="uppercase">
                            {{ $item->start_datetime->translatedFormat('M j, Y · g:i A') }}
                        </span>
                    </span>
                </span>
            @endif

            @if($item->end_datetime)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                    <flux:icon name="clock" class="size-3" />
                    <span class="inline-flex items-baseline gap-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                            {{ __('End') }}:
                        </span>
                        <span class="uppercase">
                            {{ $item->end_datetime->translatedFormat('M j, Y · g:i A') }}
                        </span>
                    </span>
                </span>
            @endif
        @endif

        @if($item->location)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-sky-500/10 px-2.5 py-0.5 font-medium text-sky-500 dark:border-white/10">
                <flux:icon name="map-pin" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Location') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->location }}</span>
                </span>
            </span>
        @endif

        @if($item->color)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium dark:border-white/10" style="color: {{ $item->color }};">
                <flux:icon name="paint-brush" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Color') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">
                        {{ $item->color }}
                    </span>
                </span>
            </span>
        @endif

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />
    @elseif($kind === 'task')
        <div
            wire:ignore
            x-data="{
                itemId: @js($item->id),
                updatePropertyMethod: @js($updatePropertyMethod),
                status: @js($item->status?->value),
                priority: @js($item->priority?->value),
                complexity: @js($item->complexity?->value),
                duration: @js($item->duration),
                startDatetime: @js($startDatetimeInitial),
                endDatetime: @js($endDatetimeInitial),
                statusOptions: @js($statusOptions),
                priorityOptions: @js($priorityOptions),
                complexityOptions: @js($complexityOptions),
                durationOptions: @js($durationOptions),
                editErrorToast: @js(__('Something went wrong. Please try again.')),
                editDateRangeError: null,
                datePickerOriginals: {},
                dateRangeMessages: {
                    taskEndBeforeStart: @js(__('End date must be the same as or after the start date.')),
                    taskEndTooSoon: @js(__('End time must be at least :minutes minutes after the start time.', ['minutes' => ':minutes'])),
                },
                validateEditDateRange(startVal, endVal, durationMinutes) {
                    this.editDateRangeError = null;
                    if (!startVal || !endVal) return true;
                    const startDate = new Date(startVal);
                    const endDate = new Date(endVal);
                    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return true;
                    if (endDate.getTime() < startDate.getTime()) {
                        this.editDateRangeError = this.dateRangeMessages.taskEndBeforeStart;
                        return false;
                    }
                    const isSameDay = startDate.toDateString() === endDate.toDateString();
                    if (isSameDay && Number.isFinite(durationMinutes) && durationMinutes > 0) {
                        const minimumEnd = new Date(startDate.getTime() + (durationMinutes * 60 * 1000));
                        if (endDate.getTime() < minimumEnd.getTime()) {
                            this.editDateRangeError = this.dateRangeMessages.taskEndTooSoon.replace(':minutes', String(durationMinutes));
                            return false;
                        }
                    }
                    return true;
                },
                getOption(options, value) {
                    return options.find(o => o.value === value);
                },
                durationLabels: { min: @js(__('min')), hour: @js(__('hour')), hours: @js(\Illuminate\Support\Str::plural(__('hour'), 2)) },
                formatDurationLabel(minutes) {
                    if (minutes == null) return '';
                    const m = Number(minutes);
                    if (m < 59) return m + ' ' + this.durationLabels.min;
                    const hours = Math.ceil(m / 60);
                    const remainder = m % 60;
                    const hourWord = hours === 1 ? this.durationLabels.hour : this.durationLabels.hours;
                    let s = hours + ' ' + hourWord;
                    if (remainder) s += ' ' + remainder + ' ' + this.durationLabels.min;
                    return s;
                },
                async updateProperty(property, value) {
                    const snapshot = {
                        status: this.status,
                        priority: this.priority,
                        complexity: this.complexity,
                        duration: this.duration,
                        startDatetime: this.startDatetime,
                        endDatetime: this.endDatetime,
                    };
                    try {
                        if (property === 'status') this.status = value;
                        else if (property === 'priority') this.priority = value;
                        else if (property === 'complexity') this.complexity = value;
                        else if (property === 'duration') this.duration = value;
                        else if (property === 'startDatetime') this.startDatetime = value;
                        else if (property === 'endDatetime') this.endDatetime = value;
                        const promise = $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value);
                        const ok = await promise;
                        if (!ok) {
                            this.status = snapshot.status;
                            this.priority = snapshot.priority;
                            this.complexity = snapshot.complexity;
                            this.duration = snapshot.duration;
                            this.startDatetime = snapshot.startDatetime;
                            this.endDatetime = snapshot.endDatetime;
                            $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                            return false;
                        }
                        return true;
                    } catch (err) {
                        this.status = snapshot.status;
                        this.priority = snapshot.priority;
                        this.complexity = snapshot.complexity;
                        this.duration = snapshot.duration;
                        this.startDatetime = snapshot.startDatetime;
                        this.endDatetime = snapshot.endDatetime;
                        $wire.$dispatch('toast', { type: 'error', message: err.message || this.editErrorToast });
                        return false;
                    }
                },
                handleDatePickerRequestValue(e) {
                    e.stopPropagation();
                    const path = e.detail.path;
                    const value = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                    e.target.dispatchEvent(new CustomEvent('date-picker-value', { detail: { path, value: value ?? null } }));
                },
                handleDatePickerOpened(e) {
                    e.stopPropagation();
                    const path = e.detail.path;
                    this.datePickerOriginals[path] = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                },
                handleDatePickerValueChanged(e) {
                    e.stopPropagation();
                    const path = e.detail.path;
                    const value = e.detail.value;
                    const startVal = path === 'startDatetime' ? value : this.startDatetime;
                    const endVal = path === 'endDatetime' ? value : this.endDatetime;
                    const durationMinutes = parseInt(this.duration ?? '0', 10);
                    this.validateEditDateRange(startVal, endVal, durationMinutes);
                },
                getDatePickerOriginalValue(path) {
                    if (path in this.datePickerOriginals) {
                        return this.datePickerOriginals[path];
                    }
                    return path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                },
                dispatchDatePickerRevert(target, path, value) {
                    const valueToRevert = value ?? this.getDatePickerOriginalValue(path);
                    target.dispatchEvent(new CustomEvent('date-picker-revert', {
                        detail: { path, value: valueToRevert ?? null },
                        bubbles: true,
                    }));
                },
                async handleDatePickerUpdated(e) {
                    e.stopPropagation();
                    const path = e.detail.path;
                    const value = e.detail.value;
                    const startVal = path === 'startDatetime' ? value : this.startDatetime;
                    const endVal = path === 'endDatetime' ? value : this.endDatetime;
                    const durationMinutes = parseInt(this.duration ?? '0', 10);
                    const isValid = this.validateEditDateRange(startVal, endVal, durationMinutes);
                    if (!isValid) {
                        this.dispatchDatePickerRevert(e.target, path);
                        this.editDateRangeError = null;
                        return;
                    }
                    this.editDateRangeError = null;
                    const ok = await this.updateProperty(path, value);
                    if (!ok) {
                        const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                        this.dispatchDatePickerRevert(e.target, path, realValue);
                    }
                },
            }"
            class="contents"
            @date-picker-request-value="handleDatePickerRequestValue($event)"
            @date-picker-opened="handleDatePickerOpened($event)"
            @date-picker-value-changed="handleDatePickerValueChanged($event)"
            @date-picker-updated="handleDatePickerUpdated($event)"
        >
        @if($item->status)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $statusInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(statusOptions, status) ? 'bg-' + getOption(statusOptions, status).color + '/10 text-' + getOption(statusOptions, status).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="check-circle" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Status') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(statusOptions, status) ? getOption(statusOptions, status).label : (status || '')">{{ $statusInitialOption ? $statusInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($statusOptions as $opt)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': status === '{{ $opt['value'] }}' }"
                            @click="updateProperty('status', '{{ $opt['value'] }}')"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @endif

        @if($item->priority)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $priorityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(priorityOptions, priority) ? 'bg-' + getOption(priorityOptions, priority).color + '/10 text-' + getOption(priorityOptions, priority).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="bolt" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Priority') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(priorityOptions, priority) ? getOption(priorityOptions, priority).label : (priority || '')">{{ $priorityInitialOption ? $priorityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($priorityOptions as $opt)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': priority === '{{ $opt['value'] }}' }"
                            @click="updateProperty('priority', '{{ $opt['value'] }}')"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @endif

        @if($item->complexity)
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 {{ $complexityInitialClass }}"
                        x-effect="$el.className = 'inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 ' + (getOption(complexityOptions, complexity) ? 'bg-' + getOption(complexityOptions, complexity).color + '/10 text-' + getOption(complexityOptions, complexity).color : 'bg-muted text-muted-foreground') + (open ? ' shadow-md scale-[1.02]' : '')"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="squares-2x2" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Complexity') }}:
                            </span>
                            <span class="uppercase" x-text="getOption(complexityOptions, complexity) ? getOption(complexityOptions, complexity).label : (complexity || '')">{{ $complexityInitialOption ? $complexityInitialOption['label'] : '' }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($complexityOptions as $opt)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': complexity === '{{ $opt['value'] }}' }"
                            @click="updateProperty('complexity', '{{ $opt['value'] }}')"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @endif

        @if(! is_null($item->duration))
            <x-simple-select-dropdown position="top" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
                        :class="{ 'shadow-md scale-[1.02]': open }"
                        aria-haspopup="menu"
                    >
                        <flux:icon name="clock" class="size-3" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Duration') }}:
                            </span>
                            <span class="uppercase" x-text="formatDurationLabel(duration)">{{ $durationInitialLabel }}</span>
                        </span>
                        <flux:icon name="chevron-down" class="size-3" />
                    </button>
                </x-slot:trigger>

                <div class="flex flex-col py-1">
                    @foreach ($durationOptions as $dur)
                        <button
                            type="button"
                            class="{{ $dropdownItemClass }}"
                            :class="{ 'font-semibold text-foreground': duration == {{ $dur['value'] }} }"
                            @click="updateProperty('duration', {{ $dur['value'] }})"
                        >
                            {{ $dur['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-simple-select-dropdown>
        @endif

        <x-date-picker
            model="startDatetime"
            type="datetime-local"
            :triggerLabel="__('Start')"
            :label="__('Start Date')"
            position="top"
            align="end"
            :initial-value="$startDatetimeInitial"
            data-task-creation-safe
        />

        <x-date-picker
            model="endDatetime"
            type="datetime-local"
            :triggerLabel="__('Due')"
            :label="__('End Date')"
            position="top"
            align="end"
            :initial-value="$endDatetimeInitial"
            data-task-creation-safe
        />

        <div class="flex w-full items-center gap-1.5" x-show="editDateRangeError" x-cloak>
            <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
            <p class="text-xs font-medium text-red-600 dark:text-red-400" x-text="editDateRangeError"></p>
        </div>

        </div>

        @if($item->tags->isNotEmpty())
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-sky-500/10 px-2.5 py-0.5 font-medium text-sky-500 dark:border-white/10">
                <flux:icon name="tag" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Tags') }}:
                    </span>
                    <span class="truncate max-w-[140px] uppercase">
                        {{ $item->tags->sortBy('name')->pluck('name')->join(', ') }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->project)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10">
                <flux:icon name="folder" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Project') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->project->name }}</span>
                </span>
            </span>
        @endif

        @if($item->event)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-purple-500/10 px-2.5 py-0.5 font-medium text-purple-500 dark:border-white/10">
                <flux:icon name="calendar" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Event') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->event->title }}</span>
                </span>
            </span>
        @endif

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />

        @if($item->completed_at)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-700 dark:border-white/10">
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Completed') }}:
                    </span>
                    <span class="opacity-80">
                        {{ $item->completed_at->format('Y-m-d') }}
                    </span>
                </span>
            </span>
        @endif

    </div>
    @endif
</div>
