{{-- Header: title, description, type badge, recurring (task/event), collaborators, activity logs, overflow dropdown. Uses parent scope. --}}
<div>
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
            <div
                x-show="isEditingTitle"
                x-cloak
                class="relative inline-block min-w-full"
            >
                <span
                    class="invisible inline-block whitespace-pre px-1 py-0.5 text-lg font-semibold leading-tight"
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
                    class="absolute inset-0 w-full min-w-0 text-lg font-semibold leading-tight rounded-md bg-muted/20 px-1 py-0.5 -mx-1 -my-0.5 transition focus:bg-background/70 focus:outline-none dark:bg-muted/10"
                    type="text"
                />
            </div>

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

                <div
                    x-show="isEditingDescription"
                    x-cloak
                    class="relative inline-block min-h-[3.5rem] min-w-full"
                >
                    <span
                        class="invisible inline-block whitespace-pre-wrap break-words px-2 py-1 text-xs leading-relaxed"
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
                            :recurring-event-id="$recurringEventIdForSelection ?? null"
                            :recurring-task-id="$recurringTaskIdForSelection ?? null"
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

                <div class="relative">
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
                            <flux:tooltip :content="__('Activity Logs')">
                                <flux:menu.item
                                    icon="clock"
                                    class="cursor-pointer"
                                    @click.stop.prevent="$dispatch('workspace-open-activity-logs', { id: {{ $item->id }}, kind: '{{ $kind }}' })"
                                >
                                    {{ __('Activity Logs') }}
                                </flux:menu.item>
                            </flux:tooltip>

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
                        :recurring-event-id="$recurringEventIdForSelection ?? null"
                        :recurring-task-id="$recurringTaskIdForSelection ?? null"
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

            @if(in_array($kind, ['task', 'event'], true))
                <span
                    x-show="(isOverdue || clientOverdue) && !clientNotOverdue"
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
