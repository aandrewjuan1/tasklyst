@props([
    'invitations' => [],
    'position' => 'top',
    'align' => 'end',
])

@php
    $invitations = $invitations instanceof \Illuminate\Support\Collection ? $invitations->all() : (is_array($invitations) ? $invitations : []);
    $invitationsForJs = array_values(array_map(fn (array $inv): array => [
        'token' => $inv['token'] ?? '',
        'id' => $inv['id'] ?? 0,
        'item_title' => $inv['item_title'] ?? '',
        'item_type' => $inv['item_type'] ?? 'item',
        'inviter_name' => $inv['inviter_name'] ?? __('Someone'),
        'permission' => $inv['permission'] ?? __('Can view'),
    ], $invitations));

    $triggerBaseClass = 'cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out';
@endphp

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: 320,
        panelWidthEst: 320,
        invitations: @js($invitationsForJs),
        acceptingTokens: new Set(),
        decliningTokens: new Set(),
        acceptErrorToast: @js(__('Could not accept invitation. Please try again.')),
        declineErrorToast: @js(__('Could not decline invitation. Please try again.')),
        panelPlacementClassesValue: 'bottom-full right-0 mb-1',

        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            const vh = window.innerHeight;
            const vw = window.innerWidth;

            // On very small viewports, use a viewport-fixed mobile sheet instead of anchoring to the trigger
            if (vw <= 480) {
                this.placementVertical = 'bottom';
                this.placementHorizontal = 'center';
                this.panelPlacementClassesValue = 'fixed inset-x-3 bottom-4 max-h-[min(70vh,22rem)]';
                this.open = true;
                this.$dispatch('dropdown-opened');
                this.$refs.button && this.$refs.button.focus();

                return;
            }

            const rect = this.$refs.button.getBoundingClientRect();
            const contentLeft = vw < 768 ? 16 : 320;
            const effectivePanelWidth = Math.min(this.panelWidthEst, vw - 32);

            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) {
                this.placementVertical = 'bottom';
            } else {
                this.placementVertical = 'top';
            }

            const endFits = rect.right <= vw && rect.right - effectivePanelWidth >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + effectivePanelWidth <= vw;

            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') {
                this.panelPlacementClassesValue = 'bottom-full right-0 mb-1';
            } else if (v === 'top' && h === 'start') {
                this.panelPlacementClassesValue = 'bottom-full left-0 mb-1';
            } else if (v === 'bottom' && h === 'end') {
                this.panelPlacementClassesValue = 'top-full right-0 mt-1';
            } else if (v === 'bottom' && h === 'start') {
                this.panelPlacementClassesValue = 'top-full left-0 mt-1';
            } else {
                this.panelPlacementClassesValue = 'bottom-full right-0 mb-1';
            }

            this.open = true;
            this.$dispatch('dropdown-opened');
            this.$refs.button && this.$refs.button.focus();
        },

        close(focusAfter) {
            if (!this.open) return;
            this.open = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus();
        },

        rollbackInvitation(invitationsBackup) {
            this.invitations = [...(invitationsBackup ?? [])];
        },

        async accept(inv) {
            if (!inv?.token) return;
            if (this.acceptingTokens?.has(inv.token)) return;

            const backup = [...this.invitations];
            try {
                this.acceptingTokens = this.acceptingTokens || new Set();
                this.acceptingTokens.add(inv.token);
                this.invitations = this.invitations.filter((i) => i.token !== inv.token);

                const ok = await $wire.acceptCollaborationInvitation(inv.token);
                if (!ok) {
                    this.rollbackInvitation(backup);
                    $wire.$dispatch('toast', { type: 'error', message: this.acceptErrorToast });
                }
            } catch (error) {
                this.rollbackInvitation(backup);
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.acceptErrorToast });
            } finally {
                this.acceptingTokens?.delete(inv.token);
            }
        },

        async decline(inv) {
            if (!inv?.token) return;
            if (this.decliningTokens?.has(inv.token)) return;

            const backup = [...this.invitations];
            try {
                this.decliningTokens = this.decliningTokens || new Set();
                this.decliningTokens.add(inv.token);
                this.invitations = this.invitations.filter((i) => i.token !== inv.token);

                const ok = await $wire.declineCollaborationInvitation(inv.token);
                if (!ok) {
                    this.rollbackInvitation(backup);
                    $wire.$dispatch('toast', { type: 'error', message: this.declineErrorToast });
                }
            } catch (error) {
                this.rollbackInvitation(backup);
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.declineErrorToast });
            } finally {
                this.decliningTokens?.delete(inv.token);
            }
        },

        get count() {
            return this.invitations?.length ?? 0;
        },

        itemTypeLabel(type) {
            const t = (type || '').toLowerCase();
            if (t === 'task') return @js(__('Task'));
            if (t === 'event') return @js(__('Event'));
            if (t === 'project') return @js(__('Project'));
            return type || @js(__('Item'));
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.button)"
    x-id="['pending-invitations-popover']"
    class="relative inline-block"
    {{ $attributes }}
>
    <flux:tooltip content="{{ __('Pending invitations') }}">
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            :aria-controls="$id('pending-invitations-popover')"
            class="{{ $triggerBaseClass }}"
            :class="open ? 'pointer-events-none shadow-md scale-[1.02]' : ''"
        >
            <flux:icon name="envelope" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Invites') }}:
                </span>
                <span class="text-xs" x-text="count">{{ count($invitations) }}</span>
            </span>
        </button>
    </flux:tooltip>

    <div
        x-ref="panel"
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.outside="close($refs.button)"
        @click.stop
        :id="$id('pending-invitations-popover')"
        :class="panelPlacementClassesValue"
        class="absolute z-50 w-fit min-w-[240px] max-w-[min(320px,calc(100vw-2rem))] flex flex-col rounded-lg border border-border bg-white shadow-lg dark:bg-zinc-900"
    >
        <div class="flex flex-col gap-2 p-3">
            <div class="flex items-center justify-center border-b border-border/50 pb-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Pending invitations') }}
                </h3>
            </div>
            <div class="max-h-64 overflow-y-auto">
                <ul class="space-y-3" x-show="invitations.length > 0" x-cloak>
                    <template x-for="inv in invitations" :key="inv.token">
                        <li class="flex flex-col gap-2 rounded-lg border border-border/50 bg-muted/40 px-3 py-2.5">
                            <p class="text-xs font-medium text-foreground leading-snug" x-text="inv.inviter_name + ' ' + @js(__('invited you to')) + ' ' + itemTypeLabel(inv.item_type) + ': ' + (inv.item_title || '')"></p>
                            <p class="text-[11px] text-muted-foreground leading-snug">
                                <span>{{ __('If you accept, you\'ll get') }}</span>
                                <span x-text="inv.permission" class="font-medium text-foreground/80"></span>
                                <span>{{ __('permission.') }}</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <flux:button
                                    size="xs"
                                    variant="primary"
                                    class="shrink-0"
                                    x-bind:disabled="acceptingTokens?.has(inv.token) || decliningTokens?.has(inv.token)"
                                    @click="accept(inv)"
                                >
                                    {{ __('Accept') }}
                                </flux:button>
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    class="shrink-0 text-muted-foreground hover:text-foreground"
                                    x-bind:disabled="acceptingTokens?.has(inv.token) || decliningTokens?.has(inv.token)"
                                    @click="decline(inv)"
                                >
                                    {{ __('Decline') }}
                                </flux:button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            <div
                class="flex flex-col items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/30 px-3 py-4 text-center"
                x-show="invitations.length === 0"
                x-cloak
            >
                <p class="text-xs font-medium text-muted-foreground">
                    {{ __('No pending invitations') }}
                </p>
            </div>
        </div>
    </div>
</div>
