{{-- Popover state on item-creation root (toggleClassHoursPopover); time rows use schoolClassTimeStart / schoolClassTimeEnd. --}}
<div
    @keydown.escape.prevent.stop="classHoursPopoverOpen && closeClassHoursPopover($refs.classHoursTrigger)"
    x-id="['school-class-hours-dropdown']"
    class="relative inline-flex w-fit max-w-full min-w-0 flex-col items-stretch"
    data-item-creation-safe
    @click.outside="classHoursPopoverOpen && closeClassHoursPopover($refs.classHoursTrigger)"
    {{ $attributes }}
>
    <button
        x-ref="classHoursTrigger"
        type="button"
        x-bind:disabled="isSubmitting"
        @click="toggleClassHoursPopover()"
        aria-haspopup="true"
        :aria-expanded="classHoursPopoverOpen"
        :aria-controls="$id('school-class-hours-dropdown')"
        class="inline-flex w-max max-w-full cursor-pointer items-center gap-1 rounded-full border border-border/60 bg-muted px-2 py-1 text-left font-medium text-muted-foreground outline-none transition-[box-shadow,transform] duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring"
        :class="{ 'shadow-md ring-1 ring-border/50': classHoursPopoverOpen }"
        data-item-creation-safe
    >
        <flux:icon name="clock" class="size-3 shrink-0" />
        <span class="shrink-0 text-[10px] font-semibold uppercase leading-tight">{{ __('Class hours') }}</span>
        <span
            class="min-w-0 max-w-[min(100%,10rem)] truncate text-[10px] font-medium leading-tight text-muted-foreground sm:max-w-[14rem]"
            x-show="schoolClassHoursTriggerSummary()"
            x-text="schoolClassHoursTriggerSummary()"
        ></span>
        <flux:icon name="chevron-down" class="size-3 shrink-0 text-muted-foreground opacity-80" />
    </button>

    <div
        x-ref="classHoursPanel"
        x-show="classHoursPopoverOpen"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.stop
        :id="$id('school-class-hours-dropdown')"
        :class="classHoursPopoverPanelClasses()"
        class="absolute z-50 flex w-[min(100vw-2rem,18rem)] flex-col gap-0 overflow-hidden rounded-md border border-border bg-white py-2 text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        data-item-creation-safe
        role="dialog"
        aria-label="{{ __('Class hours') }}"
    >
        <div wire:ignore class="flex flex-col gap-3 px-3">
            <div x-data="schoolClassTimeStart" class="flex flex-col gap-1.5">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Starts') }}</span>
                @include('components.partials.time-12h-controls')
            </div>
            <div class="border-t border-border/60 pt-2 dark:border-border/50"></div>
            <div x-data="schoolClassTimeEnd" class="flex flex-col gap-1.5">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Ends') }}</span>
                @include('components.partials.time-12h-controls')
            </div>
        </div>
    </div>
</div>
