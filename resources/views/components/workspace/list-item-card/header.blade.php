{{-- Header: title, description, type badge, recurring (task/event), collaborators, activity logs, overflow dropdown. Uses parent scope. --}}
<div :class="{ 'pointer-events-none': isFocused }">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p
                x-show="!isEditingTitle"
                @click="canEdit && startEditingTitle()"
                class="truncate text-lg font-semibold leading-tight transition-opacity"
                :class="canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default'"
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
                class="w-full min-w-0 text-lg font-semibold leading-tight rounded-md bg-muted/20 px-1 py-0.5 -mx-1 -my-0.5 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10"
                type="text"
            />

            <div class="mt-0.5" x-effect="isEditingDescription && $nextTick(() => requestAnimationFrame(() => { const el = $refs.descriptionInput; if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }))">
                {{-- Server-rendered first paint --}}
                <div x-show="!alpineReady">
                    @if(trim((string) ($description ?? '')) !== '')
                        <p
                            class="line-clamp-2 text-xs text-foreground/70 {{ $canEdit ? 'cursor-text hover:opacity-80' : 'cursor-default' }} transition-opacity"
                        >{{ $description ?? '' }}</p>
                    @elseif($canEdit)
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
                    <button
                        x-show="canEdit && !editedDescription"
                        type="button"
                        @click="startEditingDescription()"
                        class="text-xs text-muted-foreground hover:text-foreground/70 transition-colors inline-flex items-center gap-1 cursor-pointer"
                    >
                        <flux:icon name="plus" class="size-3" />
                        <span x-text="addDescriptionLabel"></span>
                    </button>
                </div>

                <textarea
                    x-show="isEditingDescription"
                    x-cloak
                    x-ref="descriptionInput"
                    x-model="editedDescription"
                    x-on:keydown="handleDescriptionKeydown($event)"
                    x-on:blur="handleDescriptionBlur()"
                    wire:ignore
                    rows="2"
                    class="w-full min-w-0 text-xs rounded-md bg-muted/20 px-2 py-1 -mx-1 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10 resize-none"
                    placeholder="{{ __('Add a description...') }}"
                ></textarea>
            </div>
        </div>

        @if($type || ($currentUserIsOwner && $deleteMethod))
            <div class="ml-2 flex items-center gap-1.5 shrink-0">
                @if($type)
                    <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ $type }}
                    </span>
                @endif

                @if(in_array($kind, ['task', 'event'], true))
                    <div class="hidden md:block">
                        <x-recurring-selection
                            model="recurrence"
                            :initial-value="$headerRecurrenceInitial"
                            :kind="$kind"
                            :readonly="!$canEditRecurrence"
                            compactWhenDisabled
                            hideWhenDisabled
                            position="top"
                            align="end"
                        />
                    </div>
                @endif

                <div class="hidden md:block">
                    <x-workspace.collaborators-popover
                        :item="$item"
                        :kind="$kind"
                        position="top"
                        align="end"
                    />
                </div>

                <div class="hidden md:block relative">
                    <x-workspace.activity-logs-popover
                        :item="$item"
                        :kind="$kind"
                        position="top"
                        align="end"
                    />
                </div>

                @if($currentUserIsOwner && $deleteMethod)
                    <flux:dropdown>
                        <flux:button size="xs" icon="ellipsis-horizontal" />

                        <flux:menu>
                            @if($kind === 'task' && $canEdit)
                                <flux:tooltip :content="__('Start focus mode')">
                                    <flux:menu.item
                                        icon="bolt"
                                        class="cursor-pointer"
                                        x-show="!isFocused"
                                        @click.stop="setTimeout(() => startFocusMode(), 120)"
                                    >
                                        <span class="block">{{ __('Focus mode') }}</span>
                                    </flux:menu.item>
                                </flux:tooltip>
                            @endif
                            <flux:tooltip :content="__('Activity Logs')">
                                <flux:menu.item
                                    icon="clock"
                                    class="cursor-pointer"
                                    @click.stop.prevent="$dispatch('workspace-open-activity-logs', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                >
                                    {{ __('Activity Logs') }}
                                </flux:menu.item>
                            </flux:tooltip>

                            <flux:separator />

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
    </div>

    @if($type)
        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
            @if(in_array($kind, ['task', 'event'], true))
                <div class="md:hidden">
                    <x-recurring-selection
                        model="recurrence"
                        :initial-value="$headerRecurrenceInitial"
                        :kind="$kind"
                        :readonly="!$canEditRecurrence"
                        compactWhenDisabled
                        hideWhenDisabled
                        position="top"
                        align="end"
                    />
                </div>
            @endif

            <div class="md:hidden">
                <x-workspace.collaborators-popover
                    :item="$item"
                    :kind="$kind"
                    position="top"
                    align="end"
                />
            </div>

            @if($showOwnerBadge)
                <flux:tooltip content="{{ __('Owner') }}: {{ $owner->name }}">
                    <span
                        class="inline-flex items-center gap-1 rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
                    >
                        <flux:icon name="user" class="size-3 shrink-0" />
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                {{ __('Owner') }}:
                            </span>
                            <span class="truncate max-w-24">{{ $owner->name }}</span>
                        </span>
                    </span>
                </flux:tooltip>
            @endif

            @if(! $currentUserIsOwner)
                @if($canEdit)
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-600 dark:border-emerald-400/50 dark:text-emerald-400"
                    >
                        <flux:icon name="pencil-square" class="size-3 shrink-0" />
                        <span>{{ __('Can edit') }}</span>
                    </span>
                @else
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                    >
                        <flux:icon name="eye" class="size-3 shrink-0" />
                        <span>{{ __('View only') }}</span>
                    </span>
                @endif
            @endif

            @if(in_array($kind, ['task', 'event'], true))
                <span
                    x-show="(isOverdue || clientOverdue) && !clientNotOverdue"
                    x-cloak
                    @if(!$isOverdue) style="display: none" @endif
                    class="inline-flex items-center gap-1 rounded-full border border-red-500/40 bg-red-500/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-red-700 dark:border-red-400/40 dark:bg-red-500/10 dark:text-red-400"
                >
                    <flux:icon name="exclamation-triangle" class="size-3 shrink-0" />
                    {{ __('Overdue') }}
                </span>
            @endif
        </div>
    @endif
</div>
