@php
    $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
@endphp

{{-- Event status selector (shares formData.task.status) --}}
<x-simple-select-dropdown position="top" align="end" x-show="creationKind === 'event'" x-cloak>
    <x-slot:trigger>
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 font-semibold transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
            x-bind:class="[getEventStatusBadgeClass(formData.task.status), open && 'shadow-md scale-[1.02]']"
            data-task-creation-safe
            aria-haspopup="menu"
        >
            <flux:icon name="check-circle" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Status') }}:
                </span>
                <span class="text-xs uppercase" x-text="eventStatusLabel(formData.task.status)"></span>
            </span>
            <flux:icon name="chevron-down" class="size-3" />
        </button>
    </x-slot:trigger>

    <div class="flex flex-col py-1" data-task-creation-safe>
        @foreach ([['value' => 'scheduled', 'label' => __('Scheduled')], ['value' => 'ongoing', 'label' => __('Ongoing')], ['value' => 'tentative', 'label' => __('Tentative')], ['value' => 'completed', 'label' => __('Completed')], ['value' => 'cancelled', 'label' => __('Cancelled')]] as $opt)
            <button
                type="button"
                class="{{ $dropdownItemClass }}"
                x-bind:class="{ 'font-semibold text-foreground': formData.task.status === '{{ $opt['value'] }}' }"
                @click="$dispatch('task-form-updated', { path: 'formData.task.status', value: '{{ $opt['value'] }}' })"
            >
                {{ $opt['label'] }}
            </button>
        @endforeach
    </div>
</x-simple-select-dropdown>

{{-- Event all-day toggle --}}
<button
    type="button"
    class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
    :class="formData.task.allDay ? 'bg-emerald-500/10 text-emerald-500 shadow-sm' : 'bg-muted text-muted-foreground'"
    x-show="creationKind === 'event'"
    x-cloak
    data-task-creation-safe
    @click="formData.task.allDay = !formData.task.allDay"
>
    <flux:icon name="sun" class="size-3" />
    <span class="inline-flex items-baseline gap-1">
        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
            {{ __('All Day') }}:
        </span>
        <span class="uppercase" x-text="formData.task.allDay ? '{{ __('Yes') }}' : '{{ __('No') }}'"></span>
    </span>
</button>

