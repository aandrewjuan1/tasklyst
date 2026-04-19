@php
    $scheduleDateFields = [
        ['label' => __('Schedule starts'), 'model' => 'formData.schoolClass.scheduleStartDate', 'datePickerLabel' => __('First day in range')],
        ['label' => __('Schedule ends'), 'model' => 'formData.schoolClass.scheduleEndDate', 'datePickerLabel' => __('Last day in range')],
    ];
@endphp

<input
    type="text"
    x-model="formData.schoolClass.teacherName"
    data-item-creation-safe
    x-bind:disabled="isSubmitting"
    placeholder="{{ __('Teacher') }}"
    autocomplete="off"
    aria-label="{{ __('Teacher') }}"
    class="w-full min-w-0 rounded-md border border-border/60 bg-background/60 px-2.5 py-1.5 text-sm text-foreground shadow-sm ring-1 ring-border/40 placeholder:text-muted-foreground focus:border-0 focus:outline-none focus:ring-2 focus:ring-brand-blue/35 dark:border-border/50 dark:bg-zinc-900/40 dark:ring-border/35"
/>

<div class="flex w-full flex-col gap-1.5" data-item-creation-safe>
    <span class="text-xs font-medium text-muted-foreground">{{ __('Schedule') }}</span>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap gap-1.5">
            <button
                type="button"
                x-bind:disabled="isSubmitting"
                @click="formData.schoolClass.scheduleMode = 'recurring'"
                class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                :class="formData.schoolClass.scheduleMode === 'recurring'
                    ? 'border-brand-blue/50 bg-brand-blue/10 text-foreground ring-1 ring-brand-blue/30'
                    : 'border-border/60 bg-muted/80 text-muted-foreground hover:bg-muted'"
            >
                {{ __('Repeating class') }}
            </button>
            <button
                type="button"
                x-bind:disabled="isSubmitting"
                @click="formData.schoolClass.scheduleMode = 'one_off'"
                class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                :class="formData.schoolClass.scheduleMode === 'one_off'
                    ? 'border-brand-blue/50 bg-brand-blue/10 text-foreground ring-1 ring-brand-blue/30'
                    : 'border-border/60 bg-muted/80 text-muted-foreground hover:bg-muted'"
            >
                {{ __('One meeting') }}
            </button>
        </div>
        <template x-if="formData.schoolClass.scheduleMode === 'recurring'">
            <div class="inline-flex min-w-0 shrink items-center gap-1.5 border-l border-border/60 pl-2 dark:border-border/50">
                <span class="hidden text-[10px] font-medium uppercase tracking-wide text-muted-foreground sm:inline">
                    {{ __('Repeat') }}
                </span>
                <x-recurring-selection
                    model="formData.schoolClass.recurrence"
                    kind="schoolClass"
                    position="bottom"
                    align="end"
                    :compact-when-disabled="true"
                />
            </div>
        </template>
    </div>
</div>

<template x-if="formData.schoolClass.scheduleMode === 'recurring'">
    <div class="flex w-full flex-col gap-3" data-item-creation-safe>
        <div class="flex w-full flex-wrap gap-2">
            @foreach ($scheduleDateFields as $dateField)
                <x-date-picker
                    :triggerLabel="$dateField['label']"
                    :label="$dateField['datePickerLabel']"
                    :model="$dateField['model']"
                    type="date"
                    position="bottom"
                    align="end"
                />
            @endforeach
        </div>

        <div
            class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/40"
        >
            <p class="mb-3 text-[10px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('Class hours') }}
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div x-data="schoolClassTimeStart" class="space-y-2">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-2 dark:border-zinc-800">
                        <span class="text-xs font-semibold text-foreground">{{ __('Starts') }}</span>
                        <flux:icon name="clock" class="size-3.5 shrink-0 text-zinc-400" />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</span>
                        @include('components.partials.time-12h-controls')
                    </div>
                </div>
                <div x-data="schoolClassTimeEnd" class="space-y-2">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-2 dark:border-zinc-800">
                        <span class="text-xs font-semibold text-foreground">{{ __('Ends') }}</span>
                        <flux:icon name="clock" class="size-3.5 shrink-0 text-zinc-400" />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</span>
                        @include('components.partials.time-12h-controls')
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<template x-if="formData.schoolClass.scheduleMode === 'one_off'">
    <div class="flex w-full flex-col gap-3" data-item-creation-safe>
        <x-date-picker
            :triggerLabel="__('Meeting day')"
            :label="__('Meeting date')"
            model="formData.schoolClass.meetingDate"
            type="date"
            position="bottom"
            align="end"
        />

        <div
            class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/40"
        >
            <p class="mb-3 text-[10px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('Class hours') }}
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div x-data="schoolClassTimeStart" class="space-y-2">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-2 dark:border-zinc-800">
                        <span class="text-xs font-semibold text-foreground">{{ __('Starts') }}</span>
                        <flux:icon name="clock" class="size-3.5 shrink-0 text-zinc-400" />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</span>
                        @include('components.partials.time-12h-controls')
                    </div>
                </div>
                <div x-data="schoolClassTimeEnd" class="space-y-2">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-2 dark:border-zinc-800">
                        <span class="text-xs font-semibold text-foreground">{{ __('Ends') }}</span>
                        <flux:icon name="clock" class="size-3.5 shrink-0 text-zinc-400" />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</span>
                        @include('components.partials.time-12h-controls')
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
