@props([
    'kind',
    'item',
    'listFilterDate' => null,
    'availableTags' => [],
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
        'project' => 'updateProjectProperty',
        'event' => 'updateEventProperty',
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

        $recurrenceInitial = [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ];
        if ($item->recurringTask) {
            $rt = $item->recurringTask;
            $daysOfWeek = $rt->days_of_week ? (json_decode($rt->days_of_week, true) ?? []) : [];
            $recurrenceInitial = [
                'enabled' => true,
                'type' => $rt->recurrence_type?->value,
                'interval' => $rt->interval ?? 1,
                'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
            ];
        }
    }
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
    wire:ignore
    x-data="{
        deletingInProgress: false,
        hideCard: false,
        dropdownOpenCount: 0,
        kind: @js($kind),
        listFilterDate: @js($listFilterDate),
        deleteMethod: @js($deleteMethod),
        itemId: @js($item->id),
        isRecurringTask: @js($kind === 'task' && (bool) $item->recurringTask),
        deleteErrorToast: @js(__('Something went wrong. Please try again.')),
        isEditingTitle: false,
        editedTitle: @js($title),
        titleSnapshot: null,
        savingTitle: false,
        justCanceledTitle: false,
        savedViaEnter: false,
        updateTitleMethod: @js($updatePropertyMethod),
        titleProperty: @js(match($kind) { 'project' => 'name', default => 'title' }),
        titleErrorToast: @js(__('Title cannot be empty.')),
        titleUpdateErrorToast: @js(__('Something went wrong updating the title.')),
        isTaskStillRelevantForList(startDatetime) {
            if (this.isRecurringTask) return true;
            if (!this.listFilterDate || this.kind !== 'task') return true;
            if (startDatetime == null || startDatetime === '') return true;
            try {
                const d = new Date(startDatetime);
                if (Number.isNaN(d.getTime())) return true;
                const taskDate = d.toISOString().slice(0, 10);
                const filterDate = String(this.listFilterDate).slice(0, 10);
                return taskDate === filterDate;
            } catch (_) {
                return true;
            }
        },
        async deleteItem() {
            if (this.deletingInProgress || this.hideCard || !this.deleteMethod || this.itemId == null) return;
            this.deletingInProgress = true;
            try {
                const ok = await $wire.$parent.$call(this.deleteMethod, this.itemId);
                if (ok) {
                    this.hideCard = true;
                    $dispatch('list-item-hidden');
                } else {
                    this.deletingInProgress = false;
                    $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                this.deletingInProgress = false;
                $wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
            }
        },
        startEditingTitle() {
            if (this.deletingInProgress || !this.updateTitleMethod) return;
            this.titleSnapshot = this.editedTitle;
            this.isEditingTitle = true;
            this.$nextTick(() => {
                const input = this.$refs.titleInput;
                if (input) {
                    input.focus();
                    // Position cursor at the end instead of selecting all
                    const length = input.value.length;
                    input.setSelectionRange(length, length);
                }
            });
        },
        cancelEditingTitle() {
            this.justCanceledTitle = true;
            this.savedViaEnter = false;
            this.editedTitle = this.titleSnapshot;
            this.isEditingTitle = false;
            this.titleSnapshot = null;
            // Reset flag after a short delay to allow blur events to settle
            setTimeout(() => { this.justCanceledTitle = false; }, 100);
        },
        async saveTitle() {
            if (this.deletingInProgress || !this.updateTitleMethod || !this.itemId || this.savingTitle || this.justCanceledTitle) return;
            
            const trimmedTitle = (this.editedTitle || '').trim();
            // 1) Empty titles are forbidden – show error and revert without calling backend
            if (!trimmedTitle) {
                $wire.$dispatch('toast', { type: 'error', message: this.titleErrorToast });
                this.cancelEditingTitle();
                return;
            }

            // Snapshot for rollback (use original value from when editing started)
            const snapshot = this.titleSnapshot;
            const originalTrimmed = (snapshot ?? '').toString().trim();

            // 2) Do not submit if nothing actually changed (no backend call, simply exit edit mode)
            if (trimmedTitle === originalTrimmed) {
                this.editedTitle = snapshot;
                this.isEditingTitle = false;
                this.titleSnapshot = null;
                return;
            }

            // Prevent concurrent saves
            this.savingTitle = true;
            
            try {
                // Optimistic update - update immediately (x-model already updated it, but ensure trimmed)
                this.editedTitle = trimmedTitle;
                
                // Call server
                const ok = await $wire.$parent.$call(this.updateTitleMethod, this.itemId, this.titleProperty, trimmedTitle);
                
                if (!ok) {
                    // Rollback on failure
                    this.editedTitle = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.titleUpdateErrorToast });
                } else {
                    // Success - exit edit mode
                    this.isEditingTitle = false;
                    this.titleSnapshot = null;
                }
            } catch (error) {
                // Rollback on error
                this.editedTitle = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.titleUpdateErrorToast });
            } finally {
                this.savingTitle = false;
                // Reset savedViaEnter flag after a short delay to allow blur events to settle
                if (this.savedViaEnter) {
                    setTimeout(() => { this.savedViaEnter = false; }, 100);
                }
            }
        },
        handleEnterKey() {
            this.savedViaEnter = true;
            this.saveTitle();
        },
        handleBlur() {
            if (!this.savedViaEnter && !this.justCanceledTitle) {
                this.saveTitle();
            }
        }
    }"
    x-show="!hideCard"
    x-transition:leave="transition ease-out duration-300"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-2"
    @dropdown-opened="dropdownOpenCount++"
    @dropdown-closed="dropdownOpenCount--"
    @task-date-updated="if (kind === 'task' && !isTaskStillRelevantForList($event.detail.startDatetime)) { hideCard = true; $dispatch('list-item-hidden') }"
    @task-date-update-failed.window="if ($event.detail && $event.detail.taskId === itemId) { hideCard = false; $dispatch('list-item-shown') }"
    :class="{ 'relative z-50': dropdownOpenCount > 0, 'pointer-events-none opacity-60': deletingInProgress }"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p 
                x-show="!isEditingTitle"
                @click="startEditingTitle()"
                class="truncate text-base font-semibold leading-tight cursor-pointer hover:opacity-80 transition-opacity"
                x-text="editedTitle"
            >
                {{ $title }}
            </p>
            <input
                x-show="isEditingTitle"
                x-cloak
                x-ref="titleInput"
                x-model="editedTitle"
                @keydown.enter.prevent="handleEnterKey()"
                @keydown.escape="cancelEditingTitle()"
                @blur="handleBlur()"
                wire:ignore
                class="w-full min-w-0 text-base font-semibold leading-tight rounded-md bg-muted/20 px-1 py-0.5 -mx-1 -my-0.5 ring-1 ring-border/40 shadow-sm transition focus:bg-background/70 focus:ring-2 focus:ring-ring/30 dark:bg-muted/10"
                type="text"
            />

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
    </div>
    @elseif($kind === 'event')
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

        @if($item->recurringEvent)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->recurringEvent->recurrence_type->color() }}/10 px-2.5 py-0.5 font-medium text-{{ $item->recurringEvent->recurrence_type->color() }} dark:border-white/10">
                <flux:icon name="calendar-days" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Recurring') }}:
                    </span>
                    <span class="uppercase">{{ $item->recurringEvent->recurrence_type->name }}</span>
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

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />
    </div>
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
                recurrence: @js($recurrenceInitial),
                statusOptions: @js($statusOptions),
                priorityOptions: @js($priorityOptions),
                complexityOptions: @js($complexityOptions),
                durationOptions: @js($durationOptions),
                formData: { task: { tagIds: @js($item->tags->pluck('id')->values()->all()) } },
                tags: @js($availableTags),
                newTagName: '',
                creatingTag: false,
                deletingTagIds: new Set(),
                isTagSelected(tagId) {
                    if (!this.formData?.task?.tagIds || !Array.isArray(this.formData.task.tagIds)) return false;
                    const tagIdStr = String(tagId);
                    return this.formData.task.tagIds.some(id => String(id) === tagIdStr);
                },
                async toggleTag(tagId) {
                    if (!this.formData.task.tagIds) this.formData.task.tagIds = [];
                    const tagIdsBackup = [...this.formData.task.tagIds];
                    const tagIdStr = String(tagId);
                    const index = this.formData.task.tagIds.findIndex(id => String(id) === tagIdStr);
                    if (index === -1) {
                        this.formData.task.tagIds.push(tagId);
                    } else {
                        this.formData.task.tagIds.splice(index, 1);
                    }
                    const realTagIds = this.formData.task.tagIds.filter(id => !String(id).startsWith('temp-'));
                    const ok = await this.updateProperty('tagIds', realTagIds);
                    if (!ok) {
                        this.formData.task.tagIds = tagIdsBackup;
                    }
                },
                async createTagOptimistic(tagNameFromEvent) {
                    const tagName = (tagNameFromEvent != null && tagNameFromEvent !== '' ? String(tagNameFromEvent).trim() : (this.newTagName || '').trim());
                    if (!tagName || this.creatingTag) return;
                    this.newTagName = '';
                    const tagNameLower = tagName.toLowerCase();
                    const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
                    if (existingTag) {
                        if (!this.formData.task.tagIds) this.formData.task.tagIds = [];
                        const alreadySelected = this.formData.task.tagIds.some(id => String(id) === String(existingTag.id));
                        if (!alreadySelected) {
                            this.formData.task.tagIds.push(existingTag.id);
                            const realTagIds = this.formData.task.tagIds.filter(id => !String(id).startsWith('temp-'));
                            await this.updateProperty('tagIds', realTagIds);
                        }
                        $wire.$dispatch('toast', { type: 'info', message: this.tagMessages.tagAlreadyExists });
                        return;
                    }
                    const tempId = 'temp-' + Date.now();
                    const tagsBackup = this.tags ? [...this.tags] : [];
                    const tagIdsBackup = [...this.formData.task.tagIds];
                    const newTagNameBackup = tagName;
                    try {
                        if (!this.tags) this.tags = [];
                        this.tags.push({ id: tempId, name: tagName });
                        this.tags.sort((a, b) => a.name.localeCompare(b.name));
                        if (!this.formData.task.tagIds.includes(tempId)) this.formData.task.tagIds.push(tempId);
                        this.creatingTag = true;
                        await $wire.$parent.$call('createTag', tagName, true);
                    } catch (err) {
                        this.tags = tagsBackup;
                        this.formData.task.tagIds = tagIdsBackup;
                        this.newTagName = newTagNameBackup;
                        $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
                    } finally {
                        this.creatingTag = false;
                    }
                },
                async deleteTagOptimistic(tag) {
                    if (this.deletingTagIds?.has(tag.id)) return;
                    const isTempTag = String(tag.id).startsWith('temp-');
                    const snapshot = { ...tag };
                    const tagsBackup = this.tags ? [...this.tags] : [];
                    const tagIdsBackup = [...this.formData.task.tagIds];
                    const tagIndex = this.tags?.findIndex(t => t.id === tag.id) ?? -1;
                    try {
                        this.deletingTagIds = this.deletingTagIds || new Set();
                        this.deletingTagIds.add(tag.id);
                        if (this.tags && tagIndex !== -1) this.tags = this.tags.filter(t => t.id !== tag.id);
                        const selectedIndex = this.formData.task.tagIds?.indexOf(tag.id);
                        if (selectedIndex !== undefined && selectedIndex !== -1) this.formData.task.tagIds.splice(selectedIndex, 1);
                        if (!isTempTag) {
                            await $wire.$parent.$call('deleteTag', tag.id);
                        }
                        const realTagIds = this.formData.task.tagIds.filter(id => !String(id).startsWith('temp-'));
                        await this.updateProperty('tagIds', realTagIds, true);
                    } catch (err) {
                        if (tagIndex !== -1 && this.tags) {
                            this.tags.splice(tagIndex, 0, snapshot);
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
                        }
                        if (tagIdsBackup.includes(tag.id) && !this.formData.task.tagIds.includes(tag.id)) {
                            this.formData.task.tagIds.push(tag.id);
                        }
                        $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
                    } finally {
                        this.deletingTagIds?.delete(tag.id);
                    }
                },
                onTagCreated(event) {
                    const { id, name } = event.detail || {};
                    const nameLower = (name || '').toLowerCase();
                    const tempTag = this.tags?.find(tag => (tag.name || '').toLowerCase() === nameLower && String(tag.id).startsWith('temp-'));
                    if (tempTag) {
                        const tempId = tempTag.id;
                        const tempTagIndex = this.tags.findIndex(tag => tag.id === tempId);
                        if (tempTagIndex !== -1) this.tags[tempTagIndex] = { id, name };
                        if (this.formData?.task?.tagIds) {
                            const tempIdIndex = this.formData.task.tagIds.indexOf(tempId);
                            if (tempIdIndex !== -1) this.formData.task.tagIds[tempIdIndex] = id;
                        }
                        this.tags = this.tags.filter((tag, idx, arr) => arr.findIndex(t => String(t.id) === String(tag.id)) === idx);
                        this.tags.sort((a, b) => a.name.localeCompare(b.name));
                        const realTagIds = this.formData.task.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                        this.updateProperty('tagIds', realTagIds);
                    } else {
                        // Tag was created elsewhere (e.g. creation form); only keep our tags list in sync for the dropdown, do not persist this task's tagIds
                        if (this.tags && !this.tags.find(tag => tag.id === id)) {
                            this.tags.push({ id, name });
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
                        }
                    }
                },
                onTagDeleted(event) {
                    const { id } = event.detail || {};
                    if (this.tags) {
                        const tagIndex = this.tags.findIndex(tag => tag.id === id);
                        if (tagIndex !== -1) {
                            this.tags.splice(tagIndex, 1);
                        }
                    }
                    if (this.formData?.task?.tagIds) {
                        const selectedIndex = this.formData.task.tagIds.indexOf(id);
                        if (selectedIndex !== -1) {
                            this.formData.task.tagIds.splice(selectedIndex, 1);
                            const realTagIds = this.formData.task.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                            this.updateProperty('tagIds', realTagIds);
                        }
                    }
                },
                editErrorToast: @js(__('Something went wrong. Please try again.')),
                tagMessages: {
                    tagAlreadyExists: @js(__('Tag already exists.')),
                    tagError: @js(__('Something went wrong. Please try again.')),
                },
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
                async updateProperty(property, value, silentSuccessToast = false) {
                    if (property === 'tagIds') {
                        try {
                            const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value, silentSuccessToast);
                            if (!ok) {
                                $wire.$dispatch('toast', { type: 'error', message: this.editErrorToast });
                                return false;
                            }
                            return true;
                        } catch (err) {
                            $wire.$dispatch('toast', { type: 'error', message: err.message || this.editErrorToast });
                            return false;
                        }
                    }
                    const snapshot = {
                        status: this.status,
                        priority: this.priority,
                        complexity: this.complexity,
                        duration: this.duration,
                        startDatetime: this.startDatetime,
                        endDatetime: this.endDatetime,
                        recurrence: JSON.parse(JSON.stringify(this.recurrence)),
                    };
                    try {
                        if (property === 'status') this.status = value;
                        else if (property === 'priority') this.priority = value;
                        else if (property === 'complexity') this.complexity = value;
                        else if (property === 'duration') this.duration = value;
                        else if (property === 'startDatetime') this.startDatetime = value;
                        else if (property === 'endDatetime') this.endDatetime = value;
                        else if (property === 'recurrence') this.recurrence = value;
                        const promise = $wire.$parent.$call(this.updatePropertyMethod, this.itemId, property, value);
                        const ok = await promise;
                        if (!ok) {
                            this.status = snapshot.status;
                            this.priority = snapshot.priority;
                            this.complexity = snapshot.complexity;
                            this.duration = snapshot.duration;
                            this.startDatetime = snapshot.startDatetime;
                            this.endDatetime = snapshot.endDatetime;
                            this.recurrence = snapshot.recurrence;
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
                        this.recurrence = snapshot.recurrence;
                        $wire.$dispatch('toast', { type: 'error', message: err.message || this.editErrorToast });
                        return false;
                    }
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
                    if (path === 'startDatetime') {
                        $dispatch('task-date-updated', { startDatetime: value });
                    }
                    const ok = await this.updateProperty(path, value);
                    if (!ok) {
                        const realValue = path === 'startDatetime' ? this.startDatetime : this.endDatetime;
                        this.dispatchDatePickerRevert(e.target, path, realValue);
                        if (path === 'startDatetime') {
                            window.dispatchEvent(new CustomEvent('task-date-update-failed', { detail: { taskId: this.itemId }, bubbles: true }));
                        }
                    }
                },
                async handleRecurringSelectionUpdated(e) {
                    e.stopPropagation();
                    const value = e.detail.value;
                    const ok = await this.updateProperty('recurrence', value);
                    if (!ok) {
                        const realValue = this.recurrence;
                        e.target.dispatchEvent(new CustomEvent('recurring-revert', {
                            detail: { path: 'recurrence', value: realValue ?? null },
                            bubbles: true,
                        }));
                    }
                },
            }"
            class="contents"
            @date-picker-opened="handleDatePickerOpened($event)"
            @date-picker-value-changed="handleDatePickerValueChanged($event)"
            @date-picker-updated="handleDatePickerUpdated($event)"
            @recurring-selection-updated="handleRecurringSelectionUpdated($event)"
            @tag-created.window="onTagCreated($event)"
            @tag-deleted.window="onTagDeleted($event)"
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

        <x-recurring-selection
            model="recurrence"
            :initial-value="$recurrenceInitial"
            triggerLabel="{{ __('Recurring') }}"
            position="top"
            align="end"
        />

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

        <div
            @tag-toggled="toggleTag($event.detail.tagId)"
            @tag-create-request="createTagOptimistic($event.detail.tagName)"
            @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
        >
            <x-workspace.tag-selection
                position="top"
                align="end"
                :initial-tag-count-label="$item->tags->count() > 0 ? (string) $item->tags->count() : __('None')"
            />
        </div>

        </div>

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
