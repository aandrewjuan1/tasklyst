@php
    $unreadLabel = $unreadCount > 0
        ? trans_choice(':count unread', $unreadCount, ['count' => $unreadCount])
        : '';
    $isHeroVariant = $variant === 'hero';
    $triggerButtonClasses = $isHeroVariant
        ? 'relative inline-flex size-10 items-center justify-center rounded-xl border-2 border-brand-blue/55 bg-white text-brand-blue shadow-md shadow-brand-blue/15 ring-2 ring-brand-blue/20 transition hover:border-brand-blue hover:shadow-lg hover:shadow-brand-blue/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/55 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-light-lavender disabled:opacity-60 dark:border-brand-blue/60 dark:bg-zinc-800 dark:text-brand-blue dark:ring-brand-blue/35 dark:hover:border-brand-blue dark:hover:bg-zinc-700/90 dark:focus-visible:ring-offset-zinc-950 sm:size-11'
        : 'relative inline-flex size-9 items-center justify-center rounded-lg bg-white text-zinc-900 transition hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/60 disabled:opacity-60 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700/60';
    $triggerIconClasses = $isHeroVariant ? 'size-[1.35rem] shrink-0 sm:size-6' : 'size-5 shrink-0';
@endphp

<div
    class="relative inline-flex {{ $panelOpen ? 'z-[60]' : 'z-20' }}"
    x-data
    @keydown.escape.window="$wire.set('panelOpen', false)"
    @click.outside="$wire.set('panelOpen', false)"
>
    <button
        type="button"
        wire:click="togglePanel"
        wire:loading.attr="disabled"
        class="{{ $triggerButtonClasses }}"
        data-test="notifications-bell-button"
        aria-haspopup="true"
        aria-expanded="{{ $panelOpen ? 'true' : 'false' }}"
        aria-label="{{ __('Notifications') }}"
    >
        <svg class="{{ $triggerIconClasses }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="{{ $isHeroVariant ? '2' : '1.5' }}" stroke="currentColor" aria-hidden="true">
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
            class="fixed right-3 top-14 z-[500] flex w-96 max-h-[calc(100dvh-4rem)] max-w-[min(24rem,calc(100vw-1.5rem))] origin-top-right flex-col rounded-xl border border-zinc-200 bg-white shadow-lg ring-1 ring-black/5 sm:right-4 lg:top-4 lg:max-h-[calc(100dvh-2rem)] dark:border-zinc-600 dark:bg-zinc-800 dark:ring-white/10"
            role="region"
            aria-label="{{ __('Notifications') }}"
        >
            <div class="flex shrink-0 items-center gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-600/80">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Notifications') }}</h2>
                    @if ($unreadCount > 0)
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $unreadLabel }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-0.5">
                    @if ($unreadCount > 0)
                        <flux:button
                            type="button"
                            size="xs"
                            variant="ghost"
                            class="text-zinc-700 dark:text-zinc-200"
                            wire:click="markAllAsRead"
                            wire:target="markAllAsRead"
                            wire:loading.attr="disabled"
                            data-test="notifications-mark-all-read"
                        >
                            <span wire:loading.remove wire:target="markAllAsRead">{{ __('Mark all as read') }}</span>
                            <span wire:loading wire:target="markAllAsRead">{{ __('Mark all as read') }}…</span>
                        </flux:button>
                    @endif
                    <button
                        type="button"
                        wire:click="closePanel"
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 dark:text-zinc-400 dark:hover:bg-zinc-700/50 dark:hover:text-zinc-100"
                        aria-label="{{ __('Close notifications') }}"
                        data-test="notifications-close-panel"
                    >
                        <flux:icon name="x-mark" class="size-4 shrink-0" />
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
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
                                'school_class', 'schoolclass' => __('Class'),
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
                    @elseif (($notification['click_behavior'] ?? null) === 'assistant_response_ready')
                        <div
                            wire:key="notification-bell-row-{{ $nid }}"
                            class="border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                        >
                            <button
                                type="button"
                                wire:click="openAssistantResponseReadyNotification('{{ $nid }}')"
                                wire:loading.attr="disabled"
                                wire:target="openAssistantResponseReadyNotification"
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
                        </div>
                    @elseif (($notification['click_behavior'] ?? null) === 'calendar_feed_sync_completed')
                        <div
                            wire:key="notification-bell-row-{{ $nid }}"
                            class="border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                        >
                            <button
                                type="button"
                                wire:click="openCalendarFeedSyncCompletedNotification('{{ $nid }}')"
                                wire:loading.attr="disabled"
                                wire:target="openCalendarFeedSyncCompletedNotification"
                                class="flex min-w-0 w-full flex-col gap-1 rounded-md px-1 py-0.5 text-left text-zinc-900 transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 disabled:opacity-60 dark:text-zinc-50 dark:hover:bg-zinc-700/40"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    @if ($isUnread)
                                        <span class="inline-block size-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
                                    @endif
                                    <span class="min-w-0 truncate text-sm font-semibold">{{ $notification['title'] }}</span>
                                </div>
                                @if (($notification['message'] ?? '') !== '')
                                    <span class="max-h-48 overflow-y-auto whitespace-pre-line text-xs leading-snug text-zinc-600 dark:text-zinc-300">{{ $notification['message'] }}</span>
                                @endif
                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $notification['created_at_human'] }}</span>
                            </button>
                        </div>
                    @else
                        @php
                            $opensWorkspace = (bool) ($notification['click_opens_workspace'] ?? false);
                            $wfKind = $notification['workspace_focus_kind'] ?? null;
                            $wfId = (int) ($notification['workspace_focus_id'] ?? 0);
                            $useWorkspaceInstantFocus = $opensWorkspace
                                && request()->routeIs('workspace')
                                && is_string($wfKind)
                                && in_array($wfKind, ['task', 'event', 'project', 'schoolClass'], true)
                                && $wfId > 0;
                        @endphp
                        <div
                            wire:key="notification-bell-row-{{ $nid }}"
                            class="border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                        >
                            @if ($opensWorkspace && $useWorkspaceInstantFocus)
                                <button
                                    type="button"
                                    wire:loading.attr="disabled"
                                    wire:target="markWorkspaceNotificationOpened,openNotificationFromWorkspaceBell"
                                    @click="
                                        const k = @js($wfKind);
                                        const i = {{ $wfId }};
                                        const instant = typeof window.workspaceCalendarTryInstantFocus === 'function' && window.workspaceCalendarTryInstantFocus(k, i);
                                        if (instant) {
                                            $wire.markWorkspaceNotificationOpened('{{ $nid }}');
                                        } else {
                                            $wire.openNotificationFromWorkspaceBell('{{ $nid }}', true);
                                        }
                                    "
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
                            @elseif ($opensWorkspace)
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

            @if ($hasMoreNotifications)
                <div class="shrink-0 border-t border-zinc-100 px-3 py-2 dark:border-zinc-600/80">
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        class="w-full justify-center text-zinc-700 dark:text-zinc-200"
                        wire:click="loadMoreNotifications"
                        wire:target="loadMoreNotifications"
                        wire:loading.attr="disabled"
                        data-test="notifications-load-more"
                    >
                        <span wire:loading.remove wire:target="loadMoreNotifications">{{ __('Load more notifications') }}</span>
                        <span wire:loading wire:target="loadMoreNotifications">{{ __('Loading…') }}</span>
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</div>
