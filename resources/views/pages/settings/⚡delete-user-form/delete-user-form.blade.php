<section class="mt-10 space-y-4">
    <flux:heading size="md">
        {{ __('Delete account') }}
    </flux:heading>

    <p class="text-sm text-muted-foreground">
        {{ __('Permanently delete your account and all of its associated data.') }}
    </p>

    <div class="mt-4">
        <flux:button
            type="button"
            variant="danger"
            x-on:click="$flux.modal('delete-account-confirmation').show()"
        >
            {{ __('Delete account') }}
        </flux:button>
    </div>

    <flux:modal name="delete-account-confirmation" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete account?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('You are about to permanently delete your account and all associated data. This action cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="button"
                    variant="danger"
                    wire:click="deleteUser"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="deleteUser">{{ __('Delete account') }}</span>
                    <span wire:loading wire:target="deleteUser">{{ __('Deleting...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
