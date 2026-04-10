<flux:dropdown position="bottom" align="end">
    <flux:button
        variant="subtle"
        square
        class="relative"
        aria-label="{{ __('Notifications') }}"
        wire:click="refreshList"
        data-test="notifications-bell-button"
    >
        <flux:icon name="bell" class="size-5" />
        @if ($unreadCount > 0)
            <span
                class="absolute -right-1 -top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white"
                data-test="notifications-unread-badge"
            >
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </flux:button>

    <flux:menu class="w-96 max-w-[calc(100vw-2rem)]">
        <div class="flex items-center justify-between px-2 py-1.5">
            <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
            @if ($unreadCount > 0)
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ trans_choice(':count unread', $unreadCount, ['count' => $unreadCount]) }}
                </flux:text>
            @endif
        </div>

        <flux:menu.separator />

        @forelse ($notifications as $notification)
            <div class="flex items-start gap-2 px-2 py-1.5">
                <button
                    type="button"
                    class="flex min-w-0 flex-1 flex-col rounded-md px-2 py-1.5 text-left transition hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    wire:click="openNotification('{{ $notification['id'] }}')"
                    wire:loading.attr="disabled"
                    wire:target="openNotification('{{ $notification['id'] }}')"
                >
                    <div class="flex items-center gap-2">
                        @if ($notification['read_at'] === null)
                            <span class="inline-block size-2 rounded-full bg-blue-500" aria-hidden="true"></span>
                        @endif
                        <flux:text class="truncate text-sm font-medium">
                            {{ $notification['title'] }}
                        </flux:text>
                    </div>
                    @if ($notification['message'] !== '')
                        <flux:text class="mt-0.5 line-clamp-2 text-xs text-zinc-600 dark:text-zinc-300">
                            {{ $notification['message'] }}
                        </flux:text>
                    @endif
                    <flux:text class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                        {{ $notification['created_at_human'] }}
                    </flux:text>
                </button>

                @if ($notification['read_at'] === null)
                    <flux:menu.item
                        as="button"
                        type="button"
                        icon="check"
                        wire:click="markAsRead('{{ $notification['id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="markAsRead('{{ $notification['id'] }}')"
                    >
                        {{ __('Mark read') }}
                    </flux:menu.item>
                @else
                    <flux:menu.item
                        as="button"
                        type="button"
                        icon="arrow-uturn-left"
                        wire:click="markAsUnread('{{ $notification['id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="markAsUnread('{{ $notification['id'] }}')"
                    >
                        {{ __('Mark unread') }}
                    </flux:menu.item>
                @endif
            </div>
        @empty
            <div class="px-3 py-4">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No notifications yet.') }}
                </flux:text>
            </div>
        @endforelse
    </flux:menu>
</flux:dropdown>
