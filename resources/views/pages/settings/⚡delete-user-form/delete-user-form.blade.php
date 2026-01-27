<section class="mt-10 space-y-4">
    <flux:heading size="md">
        {{ __('Delete account') }}
    </flux:heading>

    <p class="text-sm text-muted-foreground">
        {{ __('Permanently delete your account and all of its associated data.') }}
    </p>

    <form wire:submit="deleteUser" class="mt-4">
        <flux:button
            type="submit"
            variant="danger"
        >
            {{ __('Delete account') }}
        </flux:button>
    </form>
</section>
