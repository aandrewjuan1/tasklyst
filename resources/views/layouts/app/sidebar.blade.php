<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head')
    </head>
    <body
        class="min-h-screen text-brand-navy-blue {{ request()->routeIs('dashboard') || request()->routeIs('workspace') ? 'bg-brand-light-lavender' : 'bg-white' }}"
        x-data="{}"
        x-init="
            Alpine.store('datePicker', Alpine.store('datePicker') ?? { open: null });
            Alpine.store('simpleSelectDropdown', Alpine.store('simpleSelectDropdown') ?? { openDropdowns: [] });
            Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: null, focusReady: false });
            Alpine.store('focusModal', Alpine.store('focusModal') ?? { openItemId: null });
        "
        x-effect="
            const focusModalOpen = !!(Alpine.store('focusModal')?.openItemId ?? Alpine.store('focusSession')?.session);
            const el = $el;
            function applyLock() {
                const scrollbarWidth = focusModalOpen ? (window.innerWidth - document.documentElement.clientWidth) : 0;
                if (focusModalOpen) {
                    var scrollY;
                    var reapplyLock = el.dataset.lockedScrollY != null && el.dataset.lockedScrollY !== '';
                    if (reapplyLock) {
                        scrollY = parseInt(el.dataset.lockedScrollY, 10);
                    } else {
                        scrollY = window.scrollY ?? document.documentElement.scrollTop;
                        el.dataset.lockedScrollY = String(scrollY);
                    }
                    var stickyTopsBefore = [];
                    if (!reapplyLock) {
                        var viewportSticky = el.querySelectorAll('[data-focus-lock-viewport]');
                        viewportSticky.forEach(function (node) { stickyTopsBefore.push(node.getBoundingClientRect().top); });
                    }
                    const translateY = 'translateY(' + scrollY + 'px)';
                    var headers = el.querySelectorAll('[data-flux-header]');
                    headers.forEach(function (node) { node.style.transition = 'none'; node.style.transform = translateY; });
                    var sidebars = el.querySelectorAll('[data-flux-sidebar]');
                    sidebars.forEach(function (node) { node.style.transition = 'none'; });
                    document.documentElement.style.overflow = 'hidden';
                    document.documentElement.style.paddingRight = scrollbarWidth ? scrollbarWidth + 'px' : '';
                    el.style.position = 'fixed';
                    el.style.top = '-' + scrollY + 'px';
                    el.style.left = '0';
                    el.style.right = '0';
                    el.style.width = '100%';
                    el.style.overflow = 'hidden';
                    el.style.paddingRight = scrollbarWidth ? scrollbarWidth + 'px' : '';
                    if (!reapplyLock && stickyTopsBefore.length) {
                        var viewportSticky2 = el.querySelectorAll('[data-focus-lock-viewport]');
                        var stickyTop = 24, stickyThreshold = 60;
                        viewportSticky2.forEach(function (node, i) {
                            node.style.transition = 'none';
                            if (stickyTopsBefore[i] <= stickyThreshold) {
                                var r = node.getBoundingClientRect();
                                node.style.transform = 'translateY(' + (stickyTop - r.top) + 'px)';
                            } else node.style.transform = '';
                        });
                    }
                } else {
                    unlock();
                }
            }
            function unlock() {
                if (! Object.prototype.hasOwnProperty.call(el.dataset, 'lockedScrollY')) {
                    return;
                }
                const scrollY = parseInt(el.dataset.lockedScrollY, 10);
                const body = $el;
                const sidebars = body.querySelectorAll('[data-flux-sidebar], [data-flux-header], [data-focus-lock-viewport]');
                var rects = [];
                sidebars.forEach(function (node) {
                    var r = node.getBoundingClientRect();
                    rects.push({ node: node, top: r.top, left: r.left, width: r.width, height: r.height });
                });
                function restoreScroll() {
                    document.documentElement.scrollTop = scrollY;
                    body.scrollTop = scrollY;
                    window.scrollTo(0, scrollY);
                }
                requestAnimationFrame(function () {
                    var prevScrollBehavior = document.documentElement.style.scrollBehavior;
                    document.documentElement.style.scrollBehavior = 'auto';
                    rects.forEach(function (o) {
                        o.node.style.transition = 'none';
                        o.node.style.position = 'fixed';
                        o.node.style.top = o.top + 'px';
                        o.node.style.left = o.left + 'px';
                        o.node.style.width = o.width + 'px';
                        o.node.style.height = o.height + 'px';
                        o.node.style.transform = '';
                        o.node.style.margin = '0';
                        o.node.style.boxSizing = 'border-box';
                    });
                    body.style.position = '';
                    body.style.top = '';
                    body.style.left = '';
                    body.style.right = '';
                    body.style.width = '';
                    body.style.overflow = '';
                    body.style.paddingRight = '';
                    document.documentElement.style.overflow = '';
                    document.documentElement.style.paddingRight = '';
                    restoreScroll();
                    requestAnimationFrame(function () {
                        rects.forEach(function (o) {
                            o.node.style.position = '';
                            o.node.style.top = '';
                            o.node.style.left = '';
                            o.node.style.width = '';
                            o.node.style.height = '';
                            o.node.style.margin = '';
                            o.node.style.boxSizing = '';
                            o.node.style.transition = '';
                        });
                        document.documentElement.style.scrollBehavior = prevScrollBehavior || '';
                        delete body.dataset.lockedScrollY;
                        restoreScroll();
                        setTimeout(restoreScroll, 0);
                        setTimeout(restoreScroll, 100);
                    });
                });
            }
            if (focusModalOpen) requestAnimationFrame(applyLock);
            else applyLock();
        "
        @focusin.window="
            const dp = Alpine.store('datePicker');
            if (dp?.open?.panel && !dp.open.panel.contains($event.target)) dp.open.close();
            const ss = Alpine.store('simpleSelectDropdown');
            const target = $event.target;
            (ss.openDropdowns || []).filter(e => e.panel && !e.panel.contains(target)).forEach(e => e.closeFn());
        "
        @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
    >
        <flux:sidebar 
            sticky 
            collapsible 
            persist="false"
            class="z-80 border-e border-zinc-200 bg-brand-light-lavender lg:z-auto"
        >
            <flux:sidebar.header class="pb-1">
                <x-app-logo
                    :sidebar="true"
                    href="{{ route('dashboard') }}"
                    wire:navigate
                    class="in-data-flux-sidebar-collapsed-desktop:hidden"
                />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav class="pt-0">
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard') || request()->is('/')" wire:navigate>
                        <span class="font-bold">{{ __('Dashboard') }}</span>
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="computer-desktop" :href="route('workspace')" :current="request()->routeIs('workspace')" wire:navigate>
                        <span class="font-bold">{{ __('Workspace') }}</span>
                    </flux:sidebar.item>
                    @auth
                        <flux:modal.trigger name="task-assistant-chat">
                            <flux:sidebar.item
                                icon="chat-bubble-left-right"
                                type="button"
                                :tooltip="__('Assistant')"
                                aria-label="{{ __('Open task assistant') }}"
                            >
                                <span class="font-bold">{{ __('Assistant') }}</span>
                            </flux:sidebar.item>
                        </flux:modal.trigger>
                    @endauth
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                {{-- <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item> --}}
            </flux:sidebar.nav>

            @auth
                <div class="w-full border-t border-zinc-200/80 px-2 pb-2 pt-3 dark:border-zinc-700/60">
                    <x-desktop-user-menu class="hidden lg:block w-full" :name="auth()->user()->name" />
                </div>
            @endauth
        </flux:sidebar>

        <!-- Mobile User Menu: omit top-bar bell on dashboard/workspace — hero already includes it. -->
        <flux:header class="z-90 lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @unless (request()->routeIs('dashboard', 'workspace') || request()->path() === '/')
                <livewire:notifications.bell-dropdown />
            @endunless

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form x-data x-on:submit="window.__tasklystLoggingOut = true" method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="button"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                            x-on:click.prevent.stop="window.__tasklystLoggingOut = true; setTimeout(() => window.__tasklystLoggingOut = false, 10_000); $root.requestSubmit()"
                        >
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-toast />

        @auth
            <flux:modal
                name="task-assistant-chat"
                flyout
                position="right"
                class="h-full max-h-full w-full max-w-lg p-0! bg-transparent! border-transparent!"
            >
                <livewire:assistant.chat-flyout />
            </flux:modal>
        @endauth

        {{-- Trash popover teleports here so it stacks above the Flux sidebar (ui-sidebar z-index). --}}
        {{-- Do not use pointer-events-none on this wrapper: it breaks hit-testing for the fixed teleported panel. --}}
        <div id="workspace-trash-portal" class="relative z-2147483646 isolate"></div>

        @fluxScripts
    </body>
</html>
