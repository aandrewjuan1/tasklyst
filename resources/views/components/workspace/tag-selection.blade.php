@props([
    'position' => 'top',
    'align' => 'end',
    'selectedTags' => [],
    'readonly' => false,
])

@php
    $panelHeightEst = 220;
    $panelWidthEst = 192;

    $selectedTagsSorted = collect($selectedTags)
        ->sortBy(function (mixed $tag): string {
            if (is_array($tag)) {
                return strtolower((string) ($tag['name'] ?? ''));
            }

            return strtolower((string) ($tag->name ?? ''));
        })
        ->values();

    $initialSelectedTags = $selectedTagsSorted
        ->map(function (mixed $tag): array {
            $id = is_array($tag) ? ($tag['id'] ?? null) : ($tag->id ?? null);
            $name = is_array($tag) ? ($tag['name'] ?? '') : ($tag->name ?? '');

            return [
                'id' => $id,
                'name' => (string) $name,
            ];
        })
        ->filter(fn (array $tag): bool => ! is_null($tag['id']) && trim($tag['name']) !== '')
        ->values();
@endphp

<div
    x-data="{
        alpineReady: false,
        open: false,
        readonly: @js($readonly),
        initialSelectedTags: @js($initialSelectedTags),
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: {{ $panelHeightEst }},
        panelWidthEst: {{ $panelWidthEst }},
        get mergedTags() {
            const available = Array.isArray(this.tags) ? [...this.tags] : [];
            const selectedFallback = Array.isArray(this.initialSelectedTags) ? [...this.initialSelectedTags] : [];

            const merged = [...available, ...selectedFallback]
                .filter(t => t && t.id != null && String(t.name || '').trim() !== '');

            const byId = new Map();
            for (const tag of merged) {
                const key = String(tag.id);
                if (!byId.has(key)) {
                    byId.set(key, { id: tag.id, name: String(tag.name || '').trim() });
                }
            }

            return Array.from(byId.values())
                .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
        },
        isTagSelected(tagId) {
            const tagIds = this.formData?.item?.tagIds;
            if (Array.isArray(tagIds)) {
                const tagIdStr = String(tagId);
                return tagIds.some(id => String(id) === tagIdStr);
            }

            const fallback = Array.isArray(this.initialSelectedTags) ? this.initialSelectedTags : [];
            const tagIdStr = String(tagId);
            return fallback.some(t => String(t?.id) === tagIdStr);
        },
        get selectedTagPills() {
            const fallback = Array.isArray(this.initialSelectedTags) ? [...this.initialSelectedTags] : [];

            const tagIds = this.formData?.item?.tagIds;
            const tags = Array.isArray(this.tags) ? this.tags : null;

            if (!Array.isArray(tagIds) || !tags) {
                return fallback
                    .filter(t => t && t.id != null && String(t.name || '').trim() !== '')
                    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
            }

            const tagMap = new Map(tags.map(t => [String(t?.id), t]));
            const fallbackMap = new Map(fallback.map(t => [String(t?.id), t]));

            const results = [];
            const seen = new Set();

            for (const id of tagIds) {
                const key = String(id);
                if (seen.has(key)) continue;
                seen.add(key);

                const tag = tagMap.get(key) || fallbackMap.get(key);
                if (!tag) continue;

                const name = String(tag?.name || '').trim();
                if (!name) continue;

                results.push({ id: tag.id, name });
            }

            results.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

            return results;
        },
        toggle() {
            if (this.readonly) return;
            if (this.open) {
                return this.close(this.$refs.trigger);
            }

            this.$refs.trigger?.focus();

            const rect = this.$refs.trigger?.getBoundingClientRect() ?? { bottom: 0, top: 0, left: 0, right: 0 };
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
            this.$dispatch('dropdown-opened');
        },
        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

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
    x-init="alpineReady = true"
    @keydown.escape.prevent.stop="close($refs.trigger)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.trigger)"
    x-id="['tag-selection-dropdown']"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <button
        x-ref="trigger"
        type="button"
        @click="toggle()"
        aria-haspopup="true"
        :aria-expanded="open"
        :aria-controls="$id('tag-selection-dropdown')"
        :aria-readonly="readonly"
        class="inline-flex flex-wrap items-center gap-1.5 transition-[box-shadow,transform] duration-150 ease-out"
        :class="[{ 'shadow-md scale-[1.02]': open }, readonly ? 'cursor-default pointer-events-none opacity-90' : 'cursor-pointer']"
        data-task-creation-safe
    >
        <span class="inline-flex flex-wrap items-center gap-1.5">
            {{-- Server-rendered tags for first paint --}}
            @if($selectedTagsSorted->isNotEmpty())
                @foreach($selectedTagsSorted as $tag)
                    <span 
                        class="inline-flex items-center rounded-sm border border-black/10 px-2.5 py-1 text-xs font-medium dark:border-white/10 bg-muted text-muted-foreground"
                        x-show="!alpineReady"
                    >{{ is_array($tag) ? $tag['name'] : $tag->name }}</span>
                @endforeach
            @else
                <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground" x-show="!alpineReady">
                    <flux:icon name="tag" class="size-3" />
                    {{ __('Add tags') }}
                </span>
            @endif

            {{-- Alpine-rendered tags after hydration --}}
            <template x-for="tag in selectedTagPills" :key="String(tag.id)">
                <span 
                    class="inline-flex items-center rounded-sm border border-black/10 px-2.5 py-1 text-xs font-medium dark:border-white/10 bg-muted text-muted-foreground" 
                    x-text="tag.name"
                    x-show="alpineReady"
                    x-cloak
                ></span>
            </template>
            <span 
                x-show="alpineReady && selectedTagPills.length === 0" 
                x-cloak 
                class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground"
            >
                <flux:icon name="tag" class="size-3" />
                {{ __('Add tags') }}
            </span>
        </span>
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
        x-cloak
        @click.outside="close($refs.trigger)"
        @click.stop
        :id="$id('tag-selection-dropdown')"
        :class="panelPlacementClasses"
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
                    @keydown.enter.prevent="!creatingTag && newTagName?.trim() && $dispatch('tag-create-request', { tagName: newTagName })"
                />
                <button
                    type="button"
                    x-bind:disabled="!newTagName || !newTagName.trim() || creatingTag"
                    class="cursor-pointer shrink-0 rounded-md p-1 hover:bg-muted/80 disabled:opacity-50 disabled:cursor-not-allowed"
                    @click="!creatingTag && newTagName?.trim() && $dispatch('tag-create-request', { tagName: newTagName })"
                >
                    <flux:icon name="paper-airplane" class="size-3.5" />
                </button>
            </div>

            <div class="max-h-40 overflow-y-auto">
                <template x-for="tag in mergedTags" :key="String(tag.id)">
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
                                class="cursor-pointer shrink-0 rounded p-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                aria-label="{{ __('Delete tag') }}"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </flux:tooltip>
                    </label>
                </template>
                <div x-show="mergedTags.length === 0" x-cloak class="px-3 py-2 text-sm text-muted-foreground">
                    {{ __('No tags available') }}
                </div>
            </div>
        </div>
    </div>
</div>
