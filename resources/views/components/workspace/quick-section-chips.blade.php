@props([
    'currentSection' => 'all',
    'counts' => [],
    'viewMode' => 'list',
])

@php
    $chips = [
        ['key' => 'all', 'label' => __('All')],
        ['key' => 'overdue', 'label' => __('Overdue')],
        ['key' => 'today', 'label' => __('Today')],
        ['key' => 'tomorrow', 'label' => __('Tomorrow')],
        ['key' => 'upcoming', 'label' => __('Upcoming')],
    ];
@endphp

<div
    class="rounded-xl border border-border/60 bg-muted/25 px-2.5 py-2 shadow-sm ring-1 ring-brand-purple/10 dark:border-border/50 dark:bg-muted/15 dark:ring-zinc-600/35 sm:px-3"
    data-workspace-quick-sections
    x-data="{
        displaySection: @js($currentSection),
        _quickSectionCleanup: null,
        init() {
            const onQuickSectionOptimistic = (event) => {
                const next = event?.detail?.section ?? null;
                if (typeof next === 'string' && next !== '') {
                    this.displaySection = next;
                }
            };

            window.addEventListener('quick-section-optimistic', onQuickSectionOptimistic);
            this.$watch('$wire.quickSection', (value) => {
                this.displaySection = value ?? 'all';
            });

            this._quickSectionCleanup = () => {
                window.removeEventListener('quick-section-optimistic', onQuickSectionOptimistic);
            };
        },
        destroy() {
            this._quickSectionCleanup?.();
        },
        applyOptimistic(section) {
            this.displaySection = section;
            window.dispatchEvent(new CustomEvent('quick-section-optimistic', { detail: { section } }));
        },
    }"
>
    <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="{{ __('Quick sections') }}">
        @foreach ($chips as $chip)
            @php
                $key = $chip['key'];
                $isActive = $currentSection === $key;
                $count = (int) ($counts[$key] ?? 0);
            @endphp
            <button
                type="button"
                role="tab"
                :aria-selected="displaySection === '{{ $key }}'"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/45 active:bg-inherit active:text-inherit {{ $isActive
                    ? 'bg-brand-blue text-white shadow-sm'
                    : 'bg-white/85 text-muted-foreground ring-1 ring-border/60 hover:bg-white hover:text-foreground dark:bg-zinc-900/65 dark:text-zinc-300 dark:ring-zinc-700/55 dark:hover:bg-zinc-800/85 dark:hover:text-zinc-100' }}"
                :class="displaySection === '{{ $key }}'
                    ? 'bg-brand-blue text-white shadow-sm'
                    : 'bg-white/85 text-muted-foreground ring-1 ring-border/60 hover:bg-white hover:text-foreground dark:bg-zinc-900/65 dark:text-zinc-300 dark:ring-zinc-700/55 dark:hover:bg-zinc-800/85 dark:hover:text-zinc-100'"
                style="-webkit-tap-highlight-color: transparent;"
                @click="applyOptimistic('{{ $key }}')"
                wire:click="setQuickSection('{{ $key }}')"
                wire:key="quick-section-{{ $viewMode }}-{{ $key }}"
            >
                <span>{{ $chip['label'] }}</span>
                <span
                    class="inline-flex min-w-[1.25rem] items-center justify-center rounded-md px-1 py-0.5 text-[10px] leading-none tabular-nums {{ $isActive
                        ? 'bg-white/20 text-white'
                        : 'bg-muted/80 text-muted-foreground dark:bg-zinc-800/70 dark:text-zinc-300' }}"
                    :class="displaySection === '{{ $key }}'
                        ? 'bg-white/20 text-white'
                        : 'bg-muted/80 text-muted-foreground dark:bg-zinc-800/70 dark:text-zinc-300'"
                >{{ $count }}</span>
            </button>
        @endforeach
    </div>
</div>
