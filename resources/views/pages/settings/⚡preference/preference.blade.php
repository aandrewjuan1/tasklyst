<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Preference Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Preferences')" :subheading="__('Adjust scheduler preferences to fit your workflow')">
        <form wire:submit="updatePreferences" class="my-6 w-full space-y-6" x-data="{ lunchBlockEnabled: @entangle('lunchBlockEnabled') }">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Day boundaries') }}</p>
                <div class="mt-3 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="dayBoundsStart" :label="__('Day starts at')" type="time" />
                    <flux:input wire:model="dayBoundsEnd" :label="__('Day ends at')" type="time" />
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Lunch block') }}</p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Automatically reserve lunch time in schedules.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="lunchBlockEnabled = ! lunchBlockEnabled"
                        role="switch"
                        :aria-checked="lunchBlockEnabled.toString()"
                        :class="lunchBlockEnabled ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-zinc-300 dark:bg-zinc-700'"
                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border border-transparent p-1 transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900"
                    >
                        <span
                            :class="lunchBlockEnabled ? 'translate-x-5' : 'translate-x-0'"
                            class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200"
                        ></span>
                    </button>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2" :class="{ 'opacity-60': ! lunchBlockEnabled }">
                    <div :class="{ 'pointer-events-none': ! lunchBlockEnabled }">
                        <flux:input wire:model="lunchBlockStart" :label="__('Lunch start')" type="time" />
                    </div>
                    <div :class="{ 'pointer-events-none': ! lunchBlockEnabled }">
                        <flux:input wire:model="lunchBlockEnd" :label="__('Lunch end')" type="time" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
                <label for="energy-bias" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Energy bias') }}</label>
                <div class="relative">
                    <select
                        id="energy-bias"
                        wire:model="energyBias"
                        class="w-full appearance-none rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2.5 pr-10 text-sm text-zinc-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-emerald-400 dark:focus:ring-emerald-400/30"
                    >
                        <option value="balanced">{{ __('Balanced') }}</option>
                        <option value="morning">{{ __('Morning focus') }}</option>
                        <option value="afternoon">{{ __('Afternoon focus') }}</option>
                        <option value="evening">{{ __('Evening focus') }}</option>
                    </select>
                    <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.12l3.71-3.9a.75.75 0 0 1 1.08 1.04l-4.25 4.47a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="preferences-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>