{{-- Header: title, description, type badge, recurring (task/event), collaborators, activity logs, overflow dropdown. Uses parent scope. --}}
@php
    $layout = $layout ?? 'list';
    $isKanbanLayout = $layout === 'kanban';
    $embedInFocusModal = (bool) ($embedInFocusModal ?? false);
    $descriptionText = trim((string) ($description ?? ''));
    $hideEmptyDescriptionInFocusModal = $embedInFocusModal && $descriptionText === '';
    $cardTitleViewClass = $isKanbanLayout
        ? 'text-lg leading-snug md:text-xl'
        : 'text-xl leading-tight md:text-2xl';
    $cardTitleEditClass = $isKanbanLayout
        ? 'text-lg font-bold leading-snug md:text-xl'
        : 'text-xl font-bold leading-tight md:text-2xl';
    $itemTypePillKindClass = match ($kind ?? '') {
        'event' => 'lic-item-type-pill--event',
        'project' => 'lic-item-type-pill--project',
        'schoolclass' => 'lic-item-type-pill--school-class',
        default => 'lic-item-type-pill--task',
    };
    $supportsSharedAuxActions = in_array($kind, ['task', 'event', 'project'], true);
    $showRecurringInFocusModal = $kind === 'task';
    $showRecurringSelection = in_array($kind, ['task', 'event'], true)
        && (! $embedInFocusModal || $showRecurringInFocusModal);
    $headerSourceUrl = is_string($item->source_url ?? null) ? trim($item->source_url) : null;
    $showBrightspaceBadgeLink = $kind === 'task'
        && $headerSourceUrl !== null
        && $headerSourceUrl !== ''
        && ($item->source_type === \App\Enums\TaskSourceType::Brightspace);
    $currentHeaderUserId = auth()->id();
    $headerCurrentUserIsOwner = $currentHeaderUserId && (int) $item->user_id === (int) $currentHeaderUserId;
    $headerCanEditTags = $headerCurrentUserIsOwner && ($canEdit ?? false);
    $headerHasCollaborators = ($item->collaborators ?? collect())->count() > 0;
    $headerIsCollaboratedView = $headerHasCollaborators && ! $headerCurrentUserIsOwner;
    $showHeaderTags = ($kind ?? '') === 'task'
        && ! (($headerIsCollaboratedView && $item->tags->isEmpty()) || $embedInFocusModal);
@endphp
<div>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p
                x-show="!isEditingTitle"
                @click="canEdit && startEditingTitle()"
                class="truncate font-bold transition-opacity {{ $cardTitleViewClass }}"
                :class="canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default'"
                x-text="editedTitle"
            >
                {{ $title }}
            </p>
            <div
                x-show="isEditingTitle"
                x-cloak
                class="relative block min-w-0 w-full max-w-full overflow-hidden"
            >
                <span
                    class="invisible block w-full max-w-full truncate px-1 py-0.5 {{ $cardTitleEditClass }}"
                    aria-hidden="true"
                    x-text="editedTitle || '\u00A0'"
                ></span>
                <input
                    x-ref="titleInput"
                    x-model="editedTitle"
                    @keydown.enter.prevent="handleEnterKey()"
                    @keydown.escape="cancelEditingTitle()"
                    @blur="handleBlur()"
                    wire:ignore
                    class="absolute inset-0 w-full max-w-full min-w-0 {{ $cardTitleEditClass }} rounded-md bg-muted/20 px-1 py-0.5 -mx-1 -my-0.5 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10"
                    type="text"
                    :maxlength="titleMaxLength"
                />
            </div>

            @if($showHeaderTags)
            <div
                class="mt-0.5 w-full"
                x-data="{
                    itemId: @js($item->id),
                    updatePropertyMethod: @js($updatePropertyMethod),
                    tagMessages: {
                        tagAlreadyExists: @js(__('Tag already exists.')),
                        tagError: @js(__('Something went wrong. Please try again.')),
                        tagRemovedFromItem: @js(__('Tag ":tag" removed from :type ":item".')),
                        tagDeleted: @js(__('Tag ":tag" deleted.')),
                    },
                    itemTitle: @js($item->title ?? ''),
                    itemTypeLabel: @js(__('Task')),
                    tags: @js($availableTags ?? []),
                    formData: { item: { tagIds: @js($item->tags->pluck('id')->values()->all()) } },
                    newTagName: '',
                    creatingTag: false,
                    deletingTagIds: new Set(),
                    async updateTagIds(realTagIds, silentSuccessToast = false) {
                        try {
                            const ok = await $wire.$parent.$call(this.updatePropertyMethod, this.itemId, 'tagIds', realTagIds, silentSuccessToast);
                            if (!ok) {
                                $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
                                return false;
                            }
                            return true;
                        } catch (err) {
                            $wire.$dispatch('toast', { type: 'error', message: err.message || this.tagMessages.tagError });
                            return false;
                        }
                    },
                    async toggleTag(tagId) {
                        if (!this.formData.item.tagIds) this.formData.item.tagIds = [];
                        const backup = [...this.formData.item.tagIds];
                        const tagIdStr = String(tagId);
                        const index = this.formData.item.tagIds.findIndex(id => String(id) === tagIdStr);
                        if (index === -1) {
                            this.formData.item.tagIds.push(tagId);
                        } else {
                            this.formData.item.tagIds.splice(index, 1);
                        }
                        const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
                        const ok = await this.updateTagIds(realTagIds);
                        if (!ok) {
                            this.formData.item.tagIds = backup;
                        }
                    },
                    async createTagOptimistic(tagNameFromEvent) {
                        const tagName = (tagNameFromEvent != null && tagNameFromEvent !== '' ? String(tagNameFromEvent).trim() : (this.newTagName || '').trim());
                        if (!tagName || this.creatingTag) return;
                        this.newTagName = '';
                        const tagNameLower = tagName.toLowerCase();
                        const existingTag = this.tags?.find(t => (t.name || '').trim().toLowerCase() === tagNameLower);
                        if (existingTag && !String(existingTag.id).startsWith('temp-')) {
                            if (!this.formData.item.tagIds) this.formData.item.tagIds = [];
                            const alreadySelected = this.formData.item.tagIds.some(id => String(id) === String(existingTag.id));
                            if (!alreadySelected) {
                                this.formData.item.tagIds.push(existingTag.id);
                                const realTagIds = this.formData.item.tagIds.filter(id => !String(id).startsWith('temp-'));
                                await this.updateTagIds(realTagIds);
                            }
                            $wire.$dispatch('toast', { type: 'info', message: this.tagMessages.tagAlreadyExists });
                            return;
                        }
                        const tempId = 'temp-' + Date.now();
                        const tagsBackup = this.tags ? [...this.tags] : [];
                        const tagIdsBackup = [...this.formData.item.tagIds];
                        const newTagNameBackup = tagName;
                        try {
                            if (!this.tags) this.tags = [];
                            this.tags.push({ id: tempId, name: tagName });
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
                            if (!this.formData.item.tagIds.includes(tempId)) this.formData.item.tagIds.push(tempId);
                            this.creatingTag = true;
                            await $wire.$parent.$call('createTag', tagName, true);
                        } catch (err) {
                            this.tags = tagsBackup;
                            this.formData.item.tagIds = tagIdsBackup;
                            this.newTagName = newTagNameBackup;
                            $wire.$dispatch('toast', { type: 'error', message: this.tagMessages.tagError });
                        } finally {
                            this.creatingTag = false;
                        }
                    },
                    async deleteTagOptimistic(tag) {
                        if (this.deletingTagIds?.has(tag.id)) return;
                        const isTempTag = String(tag.id).startsWith('temp-');
                        const tagsBackup = this.tags ? [...this.tags] : [];
                        const tagIdsBackup = [...this.formData.item.tagIds];
                        const tagIndex = this.tags?.findIndex(t => String(t.id) === String(tag.id)) ?? -1;
                        try {
                            this.deletingTagIds = this.deletingTagIds || new Set();
                            this.deletingTagIds.add(tag.id);
                            if (this.tags && tagIndex !== -1) {
                                this.tags = this.tags.filter(t => String(t.id) !== String(tag.id));
                            }
                            const selectedIndex = Array.isArray(this.formData.item.tagIds)
                                ? this.formData.item.tagIds.findIndex(id => String(id) === String(tag.id))
                                : -1;
                            if (selectedIndex !== -1) {
                                this.formData.item.tagIds.splice(selectedIndex, 1);
                            }
                            if (!isTempTag) {
                                await $wire.$parent.$call('deleteTag', tag.id, true);
                            }
                            if (!isTempTag && tag.name && this.itemTitle) {
                                const msg = this.tagMessages.tagRemovedFromItem
                                    .replace(':tag', tag.name)
                                    .replace(':type', this.itemTypeLabel)
                                    .replace(':item', this.itemTitle);
                                $wire.$dispatch('toast', { type: 'success', message: msg });
                            } else if (!isTempTag && tag.name) {
                                const msg = this.tagMessages.tagDeleted.replace(':tag', tag.name);
                                $wire.$dispatch('toast', { type: 'success', message: msg });
                            }
                        } catch (err) {
                            this.tags = tagsBackup;
                            this.formData.item.tagIds = tagIdsBackup;
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
                            if (this.formData?.item?.tagIds) {
                                const tempIdIndex = this.formData.item.tagIds.indexOf(tempId);
                                if (tempIdIndex !== -1) this.formData.item.tagIds[tempIdIndex] = id;
                            }
                            this.tags = this.tags.filter((tag, idx, arr) => arr.findIndex(t => String(t.id) === String(tag.id)) === idx);
                            this.tags.sort((a, b) => a.name.localeCompare(b.name));
                            const realTagIds = this.formData.item.tagIds.filter(tid => !String(tid).startsWith('temp-'));
                            this.updateTagIds(realTagIds);
                        } else {
                            if (this.tags && !this.tags.find(tag => tag.id === id)) {
                                this.tags.push({ id, name });
                                this.tags.sort((a, b) => a.name.localeCompare(b.name));
                            }
                        }
                    },
                    onTagDeleted(event) {
                        const { id } = event.detail || {};
                        if (this.tags) {
                            const tagIndex = this.tags.findIndex(tag => String(tag.id) === String(id));
                            if (tagIndex !== -1) {
                                this.tags.splice(tagIndex, 1);
                            }
                        }
                        if (this.formData?.item?.tagIds) {
                            const selectedIndex = this.formData.item.tagIds.findIndex(tagId => String(tagId) === String(id));
                            if (selectedIndex !== -1) {
                                this.formData.item.tagIds.splice(selectedIndex, 1);
                            }
                        }
                    },
                }"
                @tag-created.window="onTagCreated($event)"
                @tag-deleted.window="onTagDeleted($event)"
                @tag-toggled="toggleTag($event.detail.tagId)"
                @tag-create-request="createTagOptimistic($event.detail.tagName)"
                @tag-delete-request="deleteTagOptimistic($event.detail.tag)"
            >
                <x-workspace.tag-selection
                    position="top"
                    :align="$isKanbanLayout ? 'start' : 'end'"
                    :selected-tags="$item->tags"
                    :readonly="!$headerCanEditTags"
                    :compact="$isKanbanLayout"
                />
            </div>
            @endif

            @if (! $isKanbanLayout && $kind !== 'schoolclass')
            <div class="mt-0.5" x-effect="isEditingDescription && $nextTick(() => requestAnimationFrame(() => { const el = $refs.descriptionInput; if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }))">
                {{-- Server-rendered first paint --}}
                <div x-show="!alpineReady">
                    @if($descriptionText !== '')
                        <p
                            class="line-clamp-2 text-xs text-foreground/70 {{ $canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default' }} transition-opacity"
                        >{{ $description ?? '' }}</p>
                    @elseif($canEdit && ! $hideEmptyDescriptionInFocusModal)
                        <button
                            type="button"
                            class="text-xs text-muted-foreground hover:text-foreground/70 transition-colors inline-flex items-center gap-1 cursor-pointer"
                        >
                            <flux:icon name="plus" class="size-3" />
                            <span>{{ __('Add description') }}</span>
                        </button>
                    @endif
                </div>

                {{-- Alpine reactive (replaces server content when hydrated) --}}
                <div x-show="alpineReady && !isEditingDescription" x-cloak>
                    <p
                        x-show="editedDescription"
                        @click="canEdit && startEditingDescription()"
                        class="line-clamp-2 text-xs text-foreground/70 transition-opacity"
                        :class="canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default'"
                        x-text="editedDescription"
                    ></p>
                    @if(! $hideEmptyDescriptionInFocusModal)
                    <button
                        x-show="canEdit && !editedDescription"
                        type="button"
                        @click="startEditingDescription()"
                        class="text-xs text-muted-foreground hover:text-foreground/70 transition-colors inline-flex items-center gap-1 cursor-pointer"
                    >
                        <flux:icon name="plus" class="size-3" />
                        <span x-text="addDescriptionLabel"></span>
                    </button>
                    @endif
                </div>

                <div
                    x-show="isEditingDescription"
                    x-cloak
                    class="relative inline-block min-h-14 min-w-full"
                >
                    <span
                        class="invisible inline-block whitespace-pre-wrap wrap-break-word px-2 py-1 text-xs leading-relaxed"
                        aria-hidden="true"
                        x-text="editedDescription || '\u00A0'"
                    ></span>
                    <textarea
                        x-ref="descriptionInput"
                        x-model="editedDescription"
                        x-on:keydown="handleDescriptionKeydown($event)"
                        x-on:blur="handleDescriptionBlur()"
                        wire:ignore
                        rows="2"
                        class="absolute inset-0 w-full min-w-0 resize-none rounded-md bg-muted/20 px-2 py-1 text-xs leading-relaxed -mx-1 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10"
                        placeholder="{{ __('Add a description...') }}"
                    ></textarea>
                </div>
            </div>
            @endif
        </div>

        {{-- Right-side actions: inline with title in list layout; ellipsis only in kanban layout --}}
        @if(! $isKanbanLayout && ($type || ($currentUserIsOwner && $deleteMethod)))
            <div class="ml-2 flex flex-wrap items-center justify-end gap-1.5 shrink-0">
                @if($showBrightspaceBadgeLink)
                    <flux:tooltip :content="__('Open in Brightspace')">
                        <a
                            href="{{ $headerSourceUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-6 items-center justify-center rounded-full border border-border/60 bg-white shadow-sm transition-colors hover:bg-zinc-50 dark:border-border dark:bg-white dark:hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="{{ __('Open in Brightspace') }}"
                        >
                            <img src="{{ asset('images/brightspace-icon.png') }}" alt="" class="size-3.5 object-contain" />
                        </a>
                    </flux:tooltip>
                @endif
                @include('components.workspace.list-item-card._item-type-pill')

                @if($showRecurringSelection)
                    <div class="hidden md:block">
                        <x-recurring-selection
                            model="recurrence"
                            :initial-value="$headerRecurrenceInitial"
                            :kind="$kind"
                            :sync-item-id="$item->id"
                            :readonly="$embedInFocusModal ? true : ! $canEditRecurrence"
                            :recurring-event-id="$recurringEventIdForSelection ?? null"
                            :recurring-task-id="$recurringTaskIdForSelection ?? null"
                            compactWhenDisabled
                            :hide-when-disabled="false"
                            position="top"
                            align="end"
                        />
                    </div>
                @endif

                @if($supportsSharedAuxActions)
                    <div class="hidden md:block">
                        <x-workspace.collaborators-popover
                            :item="$item"
                            :kind="$kind"
                            position="top"
                            align="end"
                        />
                    </div>

                    <div class="relative">
                        <x-workspace.activity-logs-popover
                            :item="$item"
                            :kind="$kind"
                            position="top"
                            align="end"
                        />
                    </div>
                @endif

                @if($currentUserIsOwner && $deleteMethod && ! $embedInFocusModal)
                    <flux:dropdown>
                        <flux:button size="xs" icon="ellipsis-horizontal" />

                        <flux:menu>
                            @if($supportsSharedAuxActions)
                                <flux:tooltip :content="__('Activity Logs')">
                                    <flux:menu.item
                                        icon="clock"
                                        class="cursor-pointer"
                                        @click.stop.prevent="$dispatch('workspace-open-activity-logs', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                    >
                                        {{ __('Activity Logs') }}
                                    </flux:menu.item>
                                </flux:tooltip>

                                <flux:tooltip :content="__('Collaborators')">
                                    <flux:menu.item
                                        icon="share"
                                        class="cursor-pointer"
                                        @click.stop.prevent="$dispatch('workspace-open-collaborators', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                    >
                                        {{ __('Collaborators') }}
                                    </flux:menu.item>
                                </flux:tooltip>
                            @endif

                            @if($supportsSharedAuxActions)
                                <flux:tooltip
                                    x-show="showSkipOccurrence"
                                    x-cloak
                                    style="display: none;"
                                    :content="__('Don\'t show this occurrence on this date')"
                                >
                                    <flux:menu.item
                                        icon="calendar-days"
                                        class="cursor-pointer"
                                        ::aria-label="skipInProgress ? skipOccurrenceSkippingLabel : skipOccurrenceLabel"
                                        ::aria-busy="skipInProgress"
                                        @click.throttle.250ms="skipThisOccurrence()"
                                    >
                                        <span x-show="!skipInProgress" x-cloak>{{ __('Skip this occurrence') }}</span>
                                        <span x-show="skipInProgress" x-cloak class="inline-flex items-center gap-1.5">
                                            <flux:icon name="arrow-path" class="size-3.5 animate-spin" />
                                            <span x-text="skipOccurrenceSkippingLabel"></span>
                                        </span>
                                    </flux:menu.item>
                                </flux:tooltip>
                                <flux:separator x-show="showSkipOccurrence" x-cloak style="display: none;" />
                            @endif

                            <flux:tooltip :content="__('Move to trash')">
                                <flux:menu.item
                                    variant="danger"
                                    icon="trash"
                                    class="cursor-pointer"
                                    @click.throttle.250ms="deleteItem()"
                                >
                                    {{ __('Move to trash') }}
                                </flux:menu.item>
                            </flux:tooltip>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        @endif

        @if($isKanbanLayout && $currentUserIsOwner && $deleteMethod)
            <div class="ml-2 flex items-center gap-1.5 shrink-0">
                {{-- Invisible popover anchors beside ellipsis so menu-opened panels position like list view (not the row below). --}}
                @if($supportsSharedAuxActions)
                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                        <x-workspace.collaborators-popover
                            :item="$item"
                            :kind="$kind"
                            position="top"
                            align="end"
                        />
                        <div class="relative">
                            <x-workspace.activity-logs-popover
                                :item="$item"
                                :kind="$kind"
                                position="top"
                                align="end"
                            />
                        </div>
                    </div>
                @endif
                <flux:dropdown>
                    <flux:button size="xs" icon="ellipsis-horizontal" />

                    <flux:menu>
                        @if($supportsSharedAuxActions)
                            <flux:tooltip :content="__('Activity Logs')">
                                <flux:menu.item
                                    icon="clock"
                                    class="cursor-pointer"
                                    @click.stop.prevent="$dispatch('workspace-open-activity-logs', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                >
                                    {{ __('Activity Logs') }}
                                </flux:menu.item>
                            </flux:tooltip>

                            <flux:tooltip :content="__('Collaborators')">
                                <flux:menu.item
                                    icon="share"
                                    class="cursor-pointer"
                                    @click.stop.prevent="$dispatch('workspace-open-collaborators', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                >
                                    {{ __('Collaborators') }}
                                </flux:menu.item>
                            </flux:tooltip>
                        @endif

                        @if($supportsSharedAuxActions)
                            <flux:tooltip
                                x-show="showSkipOccurrence"
                                x-cloak
                                style="display: none;"
                                :content="__('Don\'t show this occurrence on this date')"
                            >
                                <flux:menu.item
                                    icon="calendar-days"
                                    class="cursor-pointer"
                                    ::aria-label="skipInProgress ? skipOccurrenceSkippingLabel : skipOccurrenceLabel"
                                    ::aria-busy="skipInProgress"
                                    @click.throttle.250ms="skipThisOccurrence()"
                                >
                                    <span x-show="!skipInProgress" x-cloak>{{ __('Skip this occurrence') }}</span>
                                    <span x-show="skipInProgress" x-cloak class="inline-flex items-center gap-1.5">
                                        <flux:icon name="arrow-path" class="size-3.5 animate-spin" />
                                        <span x-text="skipOccurrenceSkippingLabel"></span>
                                    </span>
                                </flux:menu.item>
                            </flux:tooltip>
                            <flux:separator x-show="showSkipOccurrence" x-cloak style="display: none;" />
                        @endif

                        <flux:tooltip :content="__('Move to trash')">
                            <flux:menu.item
                                variant="danger"
                                icon="trash"
                                class="cursor-pointer"
                                @click.throttle.250ms="deleteItem()"
                            >
                                {{ __('Move to trash') }}
                            </flux:menu.item>
                        </flux:tooltip>
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
    </div>

    {{-- Kanban layout: place pills and ellipsis below the title to avoid overlap and overflow --}}
    @if($isKanbanLayout && ($type || ($currentUserIsOwner && $deleteMethod)))
        <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
            <div class="flex flex-wrap items-center gap-2">
                @if($showBrightspaceBadgeLink)
                    <flux:tooltip :content="__('Open in Brightspace')">
                        <a
                            href="{{ $headerSourceUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-6 items-center justify-center rounded-full border border-border/60 bg-white shadow-sm transition-colors hover:bg-zinc-50 dark:border-border dark:bg-white dark:hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="{{ __('Open in Brightspace') }}"
                        >
                            <img src="{{ asset('images/brightspace-icon.png') }}" alt="" class="size-3.5 object-contain" />
                        </a>
                    </flux:tooltip>
                @endif
                @include('components.workspace.list-item-card._item-type-pill')

                @if($showRecurringSelection)
                    <div>
                        <x-recurring-selection
                            model="recurrence"
                            :initial-value="$headerRecurrenceInitial"
                            :kind="$kind"
                            :sync-item-id="$item->id"
                            :readonly="$embedInFocusModal ? true : ! $canEditRecurrence"
                            :recurring-event-id="$recurringEventIdForSelection ?? null"
                            :recurring-task-id="$recurringTaskIdForSelection ?? null"
                            compactWhenDisabled
                            :hide-when-disabled="false"
                            position="top"
                            align="start"
                        />
                    </div>
                @endif

                @unless($currentUserIsOwner && $deleteMethod)
                    @if($supportsSharedAuxActions)
                        <div class="hidden sm:block">
                            <x-workspace.collaborators-popover
                                :item="$item"
                                :kind="$kind"
                                position="top"
                                align="start"
                            />
                        </div>

                        <div class="relative">
                            <x-workspace.activity-logs-popover
                                :item="$item"
                                :kind="$kind"
                                position="top"
                                align="start"
                            />
                        </div>
                    @endif
                @endunless
            </div>

            {{-- Ellipsis is rendered in the title row for kanban layout --}}
        </div>
    @endif

    @if($type && ! $isKanbanLayout)
        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
            @if($showRecurringSelection)
                <div class="md:hidden">
                    <x-recurring-selection
                        model="recurrence"
                        :initial-value="$headerRecurrenceInitial"
                        :kind="$kind"
                        :sync-item-id="$item->id"
                        :readonly="$embedInFocusModal ? true : ! $canEditRecurrence"
                        :recurring-event-id="$recurringEventIdForSelection ?? null"
                        :recurring-task-id="$recurringTaskIdForSelection ?? null"
                        compactWhenDisabled
                        :hide-when-disabled="false"
                        position="top"
                        align="end"
                    />
                </div>
            @endif

            @if($supportsSharedAuxActions)
                <div class="md:hidden">
                    <x-workspace.collaborators-popover
                        :item="$item"
                        :kind="$kind"
                        position="top"
                        align="end"
                    />
                </div>
            @endif

        </div>
    @endif
</div>
