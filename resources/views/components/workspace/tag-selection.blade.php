@props([
    'position' => 'top',
    'align' => 'end',
])

@php
    $panelPositionClasses = match (true) {
        $position === 'top' && $align === 'end' => 'bottom-full right-0 mb-1',
        $position === 'top' && $align === 'start' => 'bottom-full left-0 mb-1',
        $position === 'bottom' && $align === 'end' => 'top-full right-0 mt-1',
        $position === 'bottom' && $align === 'start' => 'top-full left-0 mt-1',
        default => 'bottom-full right-0 mb-1',
    };
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <button
        type="button"
        @click="open = !open"
        aria-haspopup="true"
        :aria-expanded="open"
        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
        data-task-creation-safe
    >
        <flux:icon name="tag" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Tags') }}:
            </span>
            <span class="text-xs uppercase" x-text="formData.task.tagIds && formData.task.tagIds.length > 0 ? formData.task.tagIds.length : '{{ __('None') }}'"></span>
        </span>
        <flux:icon name="chevron-down" class="size-3" />
    </button>

    <div
        x-show="open"
        x-transition
        x-cloak
        class="absolute z-50 flex min-w-48 flex-col gap-2 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 {{ $panelPositionClasses }}"
        data-task-creation-safe
        role="menu"
    >
        <div wire:ignore class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5 border-b border-border/60 px-3 py-1.5">
                <flux:input
                    x-model="newTagName"
                    x-ref="newTagInput"
                    placeholder="{{ __('Create tag...') }}"
                    size="sm"
                    class="flex-1"
                    @keydown.enter.prevent="createTagOptimistic()"
                />
                <button
                    type="button"
                    @click="createTagOptimistic()"
                    x-bind:disabled="!newTagName || !newTagName.trim() || creatingTag"
                    class="shrink-0 rounded-md p-1 hover:bg-muted/80 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <flux:icon name="paper-airplane" class="size-3.5" />
                </button>
            </div>

            <div class="max-h-40 overflow-y-auto">
                <template x-for="tag in tags || []" :key="tag.id">
                    <label
                        class="group flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="toggleTag(tag.id); $event.preventDefault()"
                    >
                        <flux:checkbox
                            x-bind:checked="isTagSelected(tag.id)"
                        />
                        <span x-text="tag.name" class="flex-1"></span>
                        <flux:tooltip :content="__('Delete tag')" position="right">
                            <button
                                type="button"
                                @click.stop="deleteTagOptimistic(tag)"
                                x-bind:disabled="deletingTagIds?.has(tag.id)"
                                class="shrink-0 rounded p-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                aria-label="{{ __('Delete tag') }}"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </flux:tooltip>
                    </label>
                </template>
                <div x-show="!tags || tags.length === 0" class="px-3 py-2 text-sm text-muted-foreground">
                    {{ __('No tags available') }}
                </div>
            </div>
        </div>
    </div>
</div>
