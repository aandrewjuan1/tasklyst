@props([
    'item',
    'kind',
    'position' => 'top',
    'align' => 'end',
])

@php
    $kind = strtolower((string) $kind);

    $collaboratorCount = $item->collaborators->count();

    $acceptedCollaborators = $item->collaborators
        ->map(function ($user) {
            $permissionEnum = $user->pivot?->permission
                ? \App\Enums\CollaborationPermission::tryFrom($user->pivot->permission)
                : null;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'permission' => $permissionEnum?->label() ?? 'View',
            ];
        })
        ->values()
        ->all();

    $invitations = $item->collaborationInvitations ?? collect();

    $pendingInvites = $invitations
        ->where('status', 'pending')
        ->map(function (\App\Models\CollaborationInvitation $invitation) {
            $permissionEnum = $invitation->permission;

            return [
                'id' => $invitation->id,
                'name' => $invitation->invitee?->name,
                'email' => $invitation->invitee_email,
                'permission' => $permissionEnum?->label() ?? 'View',
                'status' => 'pending',
            ];
        })
        ->values()
        ->all();

    $declinedInvites = $invitations
        ->whereIn('status', ['declined', 'rejected'])
        ->map(function (\App\Models\CollaborationInvitation $invitation) {
            $permissionEnum = $invitation->permission;

            return [
                'id' => $invitation->id,
                'name' => $invitation->invitee?->name,
                'email' => $invitation->invitee_email,
                'permission' => $permissionEnum?->label() ?? 'View',
                'status' => $invitation->status,
            ];
        })
        ->values()
        ->all();

    $collaboratorCount = count($acceptedCollaborators) + count($pendingInvites) + count($declinedInvites);

    $triggerBaseClass = 'cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out';

    $labelByKind = match ($kind) {
        'task' => __('Task collaborators'),
        'event' => __('Event collaborators'),
        'project' => __('Project collaborators'),
        default => __('Collaborators'),
    };
@endphp

<div
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: 320,
        panelWidthEst: 280,
        collaborators: @js($acceptedCollaborators),
        pendingInvites: @js($pendingInvites),
        declinedInvites: @js($declinedInvites),

        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            this.$refs.button && this.$refs.button.focus();

            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;

            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) {
                this.placementVertical = 'bottom';
            } else {
                this.placementVertical = 'top';
            }

            const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + this.panelWidthEst <= vw;

            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            this.open = true;
            this.$dispatch('dropdown-opened');
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;

            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

            focusAfter && focusAfter.focus();
        },

        get panelPlacementClasses() {
            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') return 'bottom-full right-0 mb-1';
            if (v === 'top' && h === 'start') return 'bottom-full left-0 mb-1';
            if (v === 'bottom' && h === 'end') return 'top-full right-0 mt-1';
            if (v === 'bottom' && h === 'start') return 'top-full left-0 mt-1';
            return 'bottom-full right-0 mb-1';
        },

        get totalCount() {
            return (this.collaborators?.length || 0)
                + (this.pendingInvites?.length || 0)
                + (this.declinedInvites?.length || 0);
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['collaborators-popover']"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <flux:tooltip content="{{ $labelByKind }}">
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            :aria-controls="$id('collaborators-popover')"
            class="{{ $triggerBaseClass }}"
            x-effect="
                const base = @js($triggerBaseClass);
                const openState = open ? ' pointer-events-none shadow-md scale-[1.02]' : '';
                $el.className = base + openState;
            "
        >
            <flux:icon name="users" class="size-3" />

            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Collab') }}:
                </span>
                <span class="text-xs">
                    {{ $collaboratorCount }}
                </span>
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
        :id="$id('collaborators-popover')"
        :class="panelPlacementClasses"
        class="absolute z-50 flex min-w-64 max-w-xs flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        data-task-creation-safe
    >
        <div class="flex flex-col gap-2 p-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Collaborators') }}
                </h3>
                <span class="text-[11px] text-muted-foreground">
                    <span x-text="totalCount"></span>
                    <span>
                        {{ __('total') }}
                    </span>
                </span>
            </div>
            <div class="space-y-3 max-h-64 overflow-auto">
                <!-- Accepted collaborators -->
                <div>
                    <div class="mb-1 flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <span>{{ __('Current (accepted)') }}</span>
                        <span x-text="collaborators.length"></span>
                    </div>

                    <template x-if="collaborators.length > 0">
                        <ul class="space-y-1">
                            <template x-for="person in collaborators" :key="person.id">
                                <li class="flex items-start gap-2 rounded-md px-2 py-1.5 hover:bg-muted/60">
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs font-medium text-foreground" x-text="person.name || person.email"></p>
                                        <p class="truncate text-[11px] text-muted-foreground opacity-80" x-text="person.email"></p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-600">
                                        <span x-text="person.permission"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <p
                        class="text-[11px] text-muted-foreground/80"
                        x-show="collaborators.length === 0"
                        x-cloak
                    >
                        {{ __('No accepted collaborators yet.') }}
                    </p>
                </div>

                <div class="h-px bg-border/60"></div>

                <!-- Pending invitations -->
                <div>
                    <div class="mb-1 flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <span>{{ __('Pending invites') }}</span>
                        <span x-text="pendingInvites.length"></span>
                    </div>

                    <template x-if="pendingInvites.length > 0">
                        <ul class="space-y-1">
                            <template x-for="invite in pendingInvites" :key="invite.id">
                                <li class="flex items-start gap-2 rounded-md px-2 py-1.5 hover:bg-muted/60">
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs font-medium text-foreground" x-text="invite.name || invite.email"></p>
                                        <p class="truncate text-[11px] text-muted-foreground opacity-80" x-text="invite.email"></p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-600">
                                        <span x-text="invite.permission"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <p
                        class="text-[11px] text-muted-foreground/80"
                        x-show="pendingInvites.length === 0"
                        x-cloak
                    >
                        {{ __('No pending invites.') }}
                    </p>
                </div>

                <div class="h-px bg-border/60"></div>

                <!-- Declined / rejected invitations -->
                <div>
                    <div class="mb-1 flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <span>{{ __('Declined / rejected') }}</span>
                        <span x-text="declinedInvites.length"></span>
                    </div>

                    <template x-if="declinedInvites.length > 0">
                        <ul class="space-y-1">
                            <template x-for="invite in declinedInvites" :key="invite.id">
                                <li class="flex items-start gap-2 rounded-md px-2 py-1.5 hover:bg-muted/60">
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs font-medium text-foreground" x-text="invite.name || invite.email"></p>
                                        <p class="truncate text-[11px] text-muted-foreground opacity-80" x-text="invite.email"></p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-red-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-600">
                                        <span x-text="invite.permission"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </template>

                    <p
                        class="text-[11px] text-muted-foreground/80"
                        x-show="declinedInvites.length === 0"
                        x-cloak
                    >
                        {{ __('No declined invites.') }}
                    </p>
                </div>
            </div>

            <div
                class="mt-2 flex flex-col items-center justify-center rounded-md border border-dashed border-border/70 px-3 py-3 text-center"
                x-show="totalCount === 0"
                x-cloak
            >
                <p class="mb-1 text-xs font-medium text-muted-foreground">
                    {{ __('No collaborators yet') }}
                </p>
                <p class="text-[11px] text-muted-foreground/80">
                    {{ __('Use this menu later to invite collaborators.') }}
                </p>
            </div>
        </div>
    </div>
</div>

