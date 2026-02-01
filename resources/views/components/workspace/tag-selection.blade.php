@props([
    'position' => 'top',
    'align' => 'end',
])

@php
    $panelHeightEst = 220;
    $panelWidthEst = 192;
@endphp

<div
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: {{ $panelHeightEst }},
        panelWidthEst: {{ $panelWidthEst }},
        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            this.$refs.button.focus();

            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;

            if (rect.bottom + this.panelHeightEst > vh && rect.top > this.panelHeightEst) {
                this.placementVertical = 'top';
            } else {
                this.placementVertical = 'bottom';
            }
            const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + this.panelWidthEst <= vw;
            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            this.open = true;
        },
        close(focusAfter) {
            if (!this.open) return;

            this.open = false;

            focusAfter && focusAfter.focus();
        },
        get panelPlacementClasses() {
            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') return 'bottom-full right-0 mb-1';
            if (v === 'top' && h === 'start') return 'bottom-full left-0 mb-1';
            if (v === 'bottom' && h === 'end') return 'top-full right-0 mt-1';
            if (v === 'bottom' && h === 'start') return 'top-full left-0 mt-1';
            return 'bottom-full right-0 mb-1';
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['tag-selection-dropdown']"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <button
        x-ref="button"
        type="button"
        @click="toggle()"
        aria-haspopup="true"
        :aria-expanded="open"
        :aria-controls="$id('tag-selection-dropdown')"
        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
        :class="{ 'shadow-md scale-[1.02]': open }"
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
        x-ref="panel"
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.outside="close($refs.button)"
        :id="$id('tag-selection-dropdown')"
        :class="panelPlacementClasses"
        x-cloak
        class="absolute z-50 flex min-w-48 flex-col gap-2 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
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
                    @keydown.enter.prevent="$dispatch('tag-create-request', { tagName: newTagName })"
                />
                <button
                    type="button"
                    @click="$dispatch('tag-create-request', { tagName: newTagName })"
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
                        @click="$dispatch('tag-toggled', { tagId: tag.id }); $event.preventDefault()"
                    >
                        <flux:checkbox
                            x-bind:checked="isTagSelected(tag.id)"
                        />
                        <span x-text="tag.name" class="flex-1"></span>
                        <flux:tooltip :content="__('Delete tag')" position="right">
                            <button
                                type="button"
                                @click.stop="$dispatch('tag-delete-request', { tag: tag })"
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
