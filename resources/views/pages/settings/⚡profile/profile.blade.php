<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and scheduler preferences')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="text" inputmode="email" required disabled autocomplete="email" />
            </div>

            <flux:input wire:model="timezone" :label="__('Timezone')" type="text" placeholder="Asia/Manila" />

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="dayBoundsStart" :label="__('Day starts at')" type="time" />
                <flux:input wire:model="dayBoundsEnd" :label="__('Day ends at')" type="time" />
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model="lunchBlockEnabled">
                    <span>{{ __('Block lunch by default') }}</span>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="lunchBlockStart" :label="__('Lunch start')" type="time" />
                    <flux:input wire:model="lunchBlockEnd" :label="__('Lunch end')" type="time" />
                </div>
            </div>

            <div>
                <label for="energy-bias" class="mb-2 block text-sm font-medium">{{ __('Energy bias') }}</label>
                <select id="energy-bias" wire:model="energyBias" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="balanced">{{ __('Balanced') }}</option>
                    <option value="morning">{{ __('Morning focus') }}</option>
                    <option value="evening">{{ __('Evening focus') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <livewire:pages::settings.delete-user-form />
    </x-pages::settings.layout>
</section>