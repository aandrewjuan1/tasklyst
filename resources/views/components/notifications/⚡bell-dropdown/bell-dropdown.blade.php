@php
    $unreadLabel = $unreadCount > 0
        ? trans_choice(':count unread', $unreadCount, ['count' => $unreadCount])
        : '';
@endphp

<div
    class="relative inline-flex"
    x-data
    @keydown.escape.window="$wire.set('panelOpen', false)"
    @click.outside="$wire.set('panelOpen', false)"
>
    <button
        type="button"
        wire:click="togglePanel"
        wire:loading.attr="disabled"
        class="relative inline-flex size-10 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/60 disabled:opacity-60 dark:text-zinc-300 dark:hover:bg-zinc-700/60"
        data-test="notifications-bell-button"
        aria-haspopup="true"
        aria-expanded="{{ $panelOpen ? 'true' : 'false' }}"
        aria-label="{{ __('Notifications') }}"
    >
        <svg class="size-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($unreadCount > 0)
            <span
                class="absolute -right-0.5 -top-0.5 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white"
                data-test="notifications-unread-badge"
            >
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    @if ($panelOpen)
        <div
            class="absolute right-0 top-full z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] origin-top-right rounded-xl border border-zinc-200 bg-white shadow-lg ring-1 ring-black/5 dark:border-zinc-600 dark:bg-zinc-800 dark:ring-white/10"
            role="region"
            aria-label="{{ __('Notifications') }}"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-600/80">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Notifications') }}</h2>
                    @if ($unreadCount > 0)
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $unreadLabel }}</p>
                    @endif
                </div>
                @if ($unreadCount > 0)
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        class="shrink-0 text-zinc-700 dark:text-zinc-200"
                        wire:click="markAllVisibleAsRead"
                        wire:target="markAllVisibleAsRead"
                        wire:loading.attr="disabled"
                        data-test="notifications-mark-all-read"
                    >
                        <span wire:loading.remove wire:target="markAllVisibleAsRead">{{ __('Mark all as read') }}</span>
                        <span wire:loading wire:target="markAllVisibleAsRead">{{ __('Mark all as read') }}…</span>
                    </flux:button>
                @endif
            </div>

            <div class="max-h-[min(24rem,70vh)] overflow-y-auto">
                @forelse ($notifications as $notification)
                    @php
                        $nid = $notification['id'];
                        $isUnread = ($notification['read_at'] ?? null) === null || $notification['read_at'] === '';
                        $kind = $notification['notification_kind'] ?? 'standard';
                    @endphp
                    @if ($kind === 'collaboration_invite')
                        @php
                            $invite = $notification['collaboration_invite'] ?? [];
                            $interaction = $invite['interaction'] ?? 'unavailable';
                            $itemType = $invite['item_type'] ?? 'item';
                            $itemTypeLabel = match ($itemType) {
                                'task' => __('Task'),
                                'event' => __('Event'),
                                'project' => __('Project'),
                                default => __('Item'),
                            };
                            $inviterName = $invite['inviter_name'] ?? __('Someone');
                            $itemTitle = $invite['item_title'] ?? '';
                            $permissionLabel = $invite['permission_label'] ?? __('Can view');
                        @endphp
                        <div
                            wire:key="notification-bell-row-{{ $nid }}"
                            class="flex flex-col gap-2 border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                        >
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <div
                                    class="flex min-w-0 cursor-default flex-col gap-1 rounded-md px-1 py-0.5 text-left text-zinc-900 dark:text-zinc-50"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        @if ($isUnread)
                                            <span class="inline-block size-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
                                        @endif
                                        <span class="min-w-0 text-sm font-semibold leading-snug">{{ $notification['title'] }}</span>
                                    </div>
                                    @if ($interaction === 'pending')
                                        <span class="text-xs leading-snug text-zinc-700 dark:text-zinc-200">
                                            {{ $inviterName }} {{ __('invited you to') }} {{ $itemTypeLabel }}: {{ $itemTitle }}
                                        </span>
                                    @elseif (($notification['message'] ?? '') !== '')
                                        <span class="line-clamp-2 text-xs leading-snug text-zinc-600 dark:text-zinc-300">{{ $notification['message'] }}</span>
                                    @endif
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $notification['created_at_human'] }}</span>
                                </div>
                                @if ($interaction === 'pending')
                                    <p class="px-1 text-[11px] leading-snug text-zinc-600 dark:text-zinc-300">
                                        {{ __('If you accept, you\'ll get') }}
                                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $permissionLabel }}</span>
                                        {{ __('permission.') }}
                                    </p>
                                    <div class="flex flex-wrap items-center gap-2 px-1 pt-0.5">
                                        <flux:button
                                            size="xs"
                                            variant="primary"
                                            class="shrink-0"
                                            wire:click.stop="acceptCollaborationInvite('{{ $nid }}')"
                                            wire:target="acceptCollaborationInvite"
                                            wire:loading.attr="disabled"
                                        >
                                            <span wire:loading.remove wire:target="acceptCollaborationInvite">{{ __('Accept') }}</span>
                                            <span wire:loading wire:target="acceptCollaborationInvite">{{ __('Accept') }}…</span>
                                        </flux:button>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            class="shrink-0 text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                                            wire:click.stop="declineCollaborationInvite('{{ $nid }}')"
                                            wire:target="declineCollaborationInvite"
                                            wire:loading.attr="disabled"
                                        >
                                            <span wire:loading.remove wire:target="declineCollaborationInvite">{{ __('Decline') }}</span>
                                            <span wire:loading wire:target="declineCollaborationInvite">{{ __('Decline') }}…</span>
                                        </flux:button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        @php
                            $opensWorkspace = (bool) ($notification['click_opens_workspace'] ?? false);
                        @endphp
                        <div
                            wire:key="notification-bell-row-{{ $nid }}"
                            class="border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                        >
                            @if ($opensWorkspace)
                                <button
                                    type="button"
                                    wire:click="openNotification('{{ $nid }}')"
                                    wire:loading.attr="disabled"
                                    class="flex min-w-0 w-full flex-col gap-1 rounded-md px-1 py-0.5 text-left text-zinc-900 transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 disabled:opacity-60 dark:text-zinc-50 dark:hover:bg-zinc-700/40"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        @if ($isUnread)
                                            <span class="inline-block size-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
                                        @endif
                                        <span class="min-w-0 truncate text-sm font-semibold">{{ $notification['title'] }}</span>
                                    </div>
                                    @if (($notification['message'] ?? '') !== '')
                                        <span class="line-clamp-2 text-xs leading-snug text-zinc-600 dark:text-zinc-300">{{ $notification['message'] }}</span>
                                    @endif
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $notification['created_at_human'] }}</span>
                                </button>
                            @else
                                <div
                                    class="flex min-w-0 cursor-default flex-col gap-1 rounded-md px-1 py-0.5 text-left text-zinc-900 dark:text-zinc-50"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        @if ($isUnread)
                                            <span class="inline-block size-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
                                        @endif
                                        <span class="min-w-0 truncate text-sm font-semibold">{{ $notification['title'] }}</span>
                                    </div>
                                    @if (($notification['message'] ?? '') !== '')
                                        <span class="line-clamp-2 text-xs leading-snug text-zinc-600 dark:text-zinc-300">{{ $notification['message'] }}</span>
                                    @endif
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $notification['created_at_human'] }}</span>
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <p class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('No notifications yet.') }}
                    </p>
                @endforelse
            </div>
        </div>
    @endif
</div>
