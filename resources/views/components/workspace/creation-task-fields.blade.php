@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
@endphp

<x-simple-select-dropdown position="top" align="end">
    <x-slot:trigger>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
            x-bind:class="[getStatusBadgeClass(formData.item.status), open && 'shadow-md scale-[1.02]']"
            data-task-creation-safe
            aria-haspopup="menu"
        >
            <flux:icon name="check-circle" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Status') }}:
                </span>
                <span class="text-xs uppercase" x-text="statusLabel(formData.item.status)"></span>
            </span>
            <flux:icon name="chevron-down" class="size-3" />
        </button>
    </x-slot:trigger>

    <div class="flex flex-col py-1" data-task-creation-safe>
        @foreach ([['value' => 'to_do', 'label' => __('To Do')], ['value' => 'doing', 'label' => __('Doing')], ['value' => 'done', 'label' => __('Done')]] as $opt)
            <button
                type="button"
                class="{{ $dropdownItemClass }}"
                x-bind:class="{ 'font-semibold text-foreground': formData.item.status === '{{ $opt['value'] }}' }"
                @click="$dispatch('item-form-updated', { path: 'formData.item.status', value: '{{ $opt['value'] }}' })"
            >
                {{ $opt['label'] }}
            </button>
        @endforeach
    </div>
</x-simple-select-dropdown>

<x-simple-select-dropdown position="top" align="end">
    <x-slot:trigger>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
            x-bind:class="[getPriorityBadgeClass(formData.item.priority), open && 'shadow-md scale-[1.02]']"
            data-task-creation-safe
            aria-haspopup="menu"
        >
            <flux:icon name="bolt" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Priority') }}:
                </span>
                <span class="text-xs uppercase" x-text="priorityLabel(formData.item.priority)"></span>
            </span>
            <flux:icon name="chevron-down" class="size-3" />
        </button>
    </x-slot:trigger>

    <div class="flex flex-col py-1" data-task-creation-safe>
        @foreach ([['value' => 'low', 'label' => __('Low')], ['value' => 'medium', 'label' => __('Medium')], ['value' => 'high', 'label' => __('High')], ['value' => 'urgent', 'label' => __('Urgent')]] as $opt)
            <button
                type="button"
                class="{{ $dropdownItemClass }}"
                x-bind:class="{ 'font-semibold text-foreground': formData.item.priority === '{{ $opt['value'] }}' }"
                @click="$dispatch('item-form-updated', { path: 'formData.item.priority', value: '{{ $opt['value'] }}' })"
            >
                {{ $opt['label'] }}
            </button>
        @endforeach
    </div>
</x-simple-select-dropdown>

<x-simple-select-dropdown position="top" align="end">
    <x-slot:trigger>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
            x-bind:class="[getComplexityBadgeClass(formData.item.complexity), open && 'shadow-md scale-[1.02]']"
            data-task-creation-safe
            aria-haspopup="menu"
        >
            <flux:icon name="squares-2x2" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Complexity') }}:
                </span>
                <span class="text-xs uppercase" x-text="complexityLabel(formData.item.complexity)"></span>
            </span>
            <flux:icon name="chevron-down" class="size-3" />
        </button>
    </x-slot:trigger>

    <div class="flex flex-col py-1" data-task-creation-safe>
        @foreach ([['value' => 'simple', 'label' => __('Simple')], ['value' => 'moderate', 'label' => __('Moderate')], ['value' => 'complex', 'label' => __('Complex')]] as $opt)
            <button
                type="button"
                class="{{ $dropdownItemClass }}"
                x-bind:class="{ 'font-semibold text-foreground': formData.item.complexity === '{{ $opt['value'] }}' }"
                @click="$dispatch('item-form-updated', { path: 'formData.item.complexity', value: '{{ $opt['value'] }}' })"
            >
                {{ $opt['label'] }}
            </button>
        @endforeach
    </div>
</x-simple-select-dropdown>

<x-simple-select-dropdown position="top" align="end">
    <x-slot:trigger>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
            :class="{ 'shadow-md scale-[1.02]': open }"
            data-task-creation-safe
            aria-haspopup="menu"
        >
            <flux:icon name="clock" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Duration') }}:
                </span>
                <span class="text-xs uppercase" x-text="formatDurationLabel(formData.item.duration)"></span>
            </span>
            <flux:icon name="chevron-down" class="size-3" />
        </button>
    </x-slot:trigger>

    <div
        class="flex flex-col py-1"
        data-task-creation-safe
        x-data="{
            customDurationValue: '',
            customDurationUnit: 'minutes',
            maxDurationMinutes: @js(\App\Support\Validation\TaskPayloadValidation::MAX_DURATION_MINUTES),
            applyCustomDuration() {
                const n = parseInt(this.customDurationValue, 10);
                if (!Number.isFinite(n) || n <= 0) return;
                let minutes = this.customDurationUnit === 'hours' ? n * 60 : n;
                const max = Number(this.maxDurationMinutes) || 1440;
                if (minutes > max) {
                    minutes = max;
                }
                this.$dispatch('item-form-updated', { path: 'formData.item.duration', value: String(minutes) });
            },
        }"
    >
        @foreach ([['value' => '15', 'label' => '15 min'], ['value' => '30', 'label' => '30 min'], ['value' => '60', 'label' => '1 hour'], ['value' => '120', 'label' => '2 hours'], ['value' => '240', 'label' => '4 hours'], ['value' => '480', 'label' => '8 hours']] as $dur)
            <button
                type="button"
                class="{{ $dropdownItemClass }}"
                x-bind:class="{ 'font-semibold text-foreground': formData.item.duration == '{{ $dur['value'] }}' }"
                @click="$dispatch('item-form-updated', { path: 'formData.item.duration', value: '{{ $dur['value'] }}' })"
            >
                {{ $dur['label'] }}
            </button>
        @endforeach

        <div class="mt-1 border-t border-border/60 pt-2 px-3 pb-1 text-xs text-muted-foreground">
            <div class="mb-1 text-[11px] font-medium">
                {{ __('Custom duration') }}
            </div>
            <div class="flex items-center gap-2">
                <input
                    type="number"
                    min="1"
                    step="1"
                    x-model.number="customDurationValue"
                    placeholder="30"
                    @click.stop
                    @blur="applyCustomDuration()"
                    @keydown.enter.prevent.stop="applyCustomDuration()"
                    class="h-8 w-16 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                />
                <div class="inline-flex overflow-hidden rounded-full border border-zinc-200 bg-zinc-50 text-[11px] shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <button
                        type="button"
                        class="px-2 py-1 transition-colors"
                        :class="customDurationUnit === 'minutes'
                            ? 'bg-pink-500 text-white dark:bg-pink-500'
                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        @click.stop.prevent="customDurationUnit = 'minutes'; applyCustomDuration()"
                    >
                        {{ __('Min') }}
                    </button>
                    <button
                        type="button"
                        class="px-2 py-1 transition-colors"
                        :class="customDurationUnit === 'hours'
                            ? 'bg-pink-500 text-white dark:bg-pink-500'
                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                        @click.stop.prevent="customDurationUnit = 'hours'; applyCustomDuration()"
                    >
                        {{ __('Hours') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-simple-select-dropdown>

