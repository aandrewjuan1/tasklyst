@props([
    'item',
    'kind',
    'position' => 'top',
    'align' => 'end',
])

@php
    $kind = strtolower((string) $kind);

    $collaboratorCount = $item->collaborators->count();

    $collaborationsByUserId = ($item->collaborations ?? collect())
        ->keyBy('user_id');

    $permissionLabelMap = static function (?string $label): string {
        $normalized = strtolower((string) $label);

        return match ($normalized) {
            'view' => __('Can view'),
            'edit' => __('Can edit'),
            default => $label ? ucfirst($normalized) : __('Can view'),
        };
    };

    $acceptedCollaborators = $item->collaborators
        ->map(function ($user) use ($permissionLabelMap, $collaborationsByUserId) {
            $permissionEnum = $user->pivot?->permission
                ? \App\Enums\CollaborationPermission::tryFrom($user->pivot->permission)
                : null;

            $permissionLabel = $permissionEnum?->label() ?? 'View';

            /** @var \App\Models\Collaboration|null $collaboration */
            $collaboration = $collaborationsByUserId->get($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'permission_value' => $permissionEnum?->value ?? \App\Enums\CollaborationPermission::View->value,
                'permission' => $permissionLabelMap($permissionLabel),
                'collaboration_id' => $collaboration?->id,
            ];
        })
        ->values()
        ->all();

    $invitations = $item->collaborationInvitations ?? collect();

    $pendingInvites = $invitations
        ->where('status', 'pending')
        ->map(function (\App\Models\CollaborationInvitation $invitation) use ($permissionLabelMap) {
            $permissionEnum = $invitation->permission;

            $permissionLabel = $permissionEnum?->label() ?? 'View';

            return [
                'id' => $invitation->id,
                'name' => $invitation->invitee?->name,
                'email' => $invitation->invitee_email,
                'permission' => $permissionLabelMap($permissionLabel),
                'status' => 'pending',
            ];
        })
        ->values()
        ->all();

    $declinedInvites = $invitations
        ->whereIn('status', ['declined', 'rejected'])
        ->map(function (\App\Models\CollaborationInvitation $invitation) use ($permissionLabelMap) {
            $permissionEnum = $invitation->permission;

            $permissionLabel = $permissionEnum?->label() ?? 'View';

            return [
                'id' => $invitation->id,
                'name' => $invitation->invitee?->name,
                'email' => $invitation->invitee_email,
                'permission' => $permissionLabelMap($permissionLabel),
                'status' => $invitation->status,
            ];
        })
        ->values()
        ->all();
    $acceptedCollection = collect($acceptedCollaborators)
        ->map(fn (array $person) => [
            ...$person,
            'status' => 'accepted',
        ]);

    $pendingCollection = collect($pendingInvites);
    $declinedCollection = collect($declinedInvites);

    $allCollaborators = $acceptedCollection
        ->merge($pendingCollection)
        ->merge($declinedCollection)
        ->values()
        ->all();

    $triggerBaseClass = 'cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out';

    $labelByKind = match ($kind) {
        'task' => __('Task collaborators'),
        'event' => __('Event collaborators'),
        'project' => __('Project collaborators'),
        default => __('Collaborators'),
    };
@endphp

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: 320,
        panelWidthEst: 300,
        people: @js($allCollaborators),
        removingKeys: new Set(),
        updatingPermissionKeys: new Set(),
        removeErrorToast: @js(__('Could not remove collaborator. Please try again.')),
        permissionViewLabel: @js(__('Can view')),
        permissionEditLabel: @js(__('Can edit')),
        permissionUpdateErrorToast: @js(__('Could not update collaborator permission. Please try again.')),

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
            return this.people?.length || 0;
        },

        get acceptedCount() {
            const people = this.people || [];

            return people.filter((person) => (person.status ?? 'accepted') === 'accepted').length;
        },

        async removePerson(person) {
            if (!person) {
                return;
            }

            const status = person.status ?? 'accepted';
            const key = `${status}-${person.id ?? person.email}`;

            if (this.removingKeys?.has(key)) {
                return;
            }

            // Determine backend method + identifier based on status
            let method = null;
            let id = null;

            if (status === 'accepted') {
                method = 'removeCollaborator';
                id = person.collaboration_id ?? null;
            } else {
                method = 'deleteCollaborationInvitation';
                id = person.id ?? null;
            }

            if (!method || id === null) {
                return;
            }

            const peopleBackup = [...this.people];

            try {
                this.removingKeys = this.removingKeys || new Set();
                this.removingKeys.add(key);

                // Optimistic removal from local list
                this.people = this.people.filter((p) => p !== person);

                const numericId = Number(id);
                if (!Number.isFinite(numericId)) {
                    this.people = peopleBackup;
                    return;
                }

                const ok = await $wire.$parent.$call(method, numericId);
                if (!ok) {
                    this.people = peopleBackup;
                    $wire.$dispatch('toast', { type: 'error', message: this.removeErrorToast });
                }
            } catch (error) {
                this.people = peopleBackup;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.removeErrorToast });
            } finally {
                this.removingKeys?.delete(key);
            }
        },

        togglePersonPermission(person) {
            if (!person) {
                return;
            }

            const status = person.status ?? 'accepted';
            if (status !== 'accepted') {
                return;
            }

            const current = String(person.permission_value ?? 'view').toLowerCase();
            const next = current === 'edit' ? 'view' : 'edit';

            this.changePersonPermission(person, next);
        },

        async changePersonPermission(person, permission) {
            if (!person) {
                return;
            }

            const status = person.status ?? 'accepted';
            if (status !== 'accepted') {
                return;
            }

            const allowed = ['view', 'edit'];
            const normalized = String(permission).toLowerCase();
            if (!allowed.includes(normalized)) {
                return;
            }

            const collaborationId = person.collaboration_id ?? null;
            if (collaborationId === null) {
                return;
            }

            const key = `perm-${collaborationId}`;
            if (this.updatingPermissionKeys?.has(key)) {
                return;
            }

            const previousValue = person.permission_value ?? 'view';
            const previousLabel = person.permission;

            const label = normalized === 'edit'
                ? this.permissionEditLabel
                : this.permissionViewLabel;

            // Optimistic UI update
            person.permission_value = normalized;
            person.permission = label;

            this.updatingPermissionKeys = this.updatingPermissionKeys || new Set();
            this.updatingPermissionKeys.add(key);

            try {
                const numericId = Number(collaborationId);
                if (!Number.isFinite(numericId)) {
                    person.permission_value = previousValue;
                    person.permission = previousLabel;

                    return;
                }

                const ok = await $wire.$parent.$call('updateCollaboratorPermission', numericId, normalized);
                if (!ok) {
                    person.permission_value = previousValue;
                    person.permission = previousLabel;
                    $wire.$dispatch('toast', { type: 'error', message: this.permissionUpdateErrorToast });
                }
            } catch (error) {
                person.permission_value = previousValue;
                person.permission = previousLabel;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.permissionUpdateErrorToast });
            } finally {
                this.updatingPermissionKeys?.delete(key);
            }
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
                <span class="text-xs" x-text="acceptedCount">
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
        @click.stop=""
        :id="$id('collaborators-popover')"
        :class="panelPlacementClasses"
        class="absolute z-50 w-fit min-w-[220px] flex flex-col rounded-lg border border-border bg-white shadow-lg dark:bg-zinc-900"
        data-task-creation-safe
    >
        <div class="flex flex-col gap-2 p-3">
            <div class="flex items-center justify-center border-b border-border/50 pb-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('Collaborators') }}
                </h3>
            </div>
            <div class="max-h-64 overflow-y-auto">
                <template x-if="people.length > 0">
                    <ul class="space-y-1.5">
                        <template
                            x-for="person in people"
                            :key="person.id + '-' + (person.status ?? 'accepted')"
                        >
                            <li class="group flex items-center justify-between gap-2 rounded-md bg-muted/60 px-2 py-1.5 transition-colors hover:bg-muted/80">
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-[11px] font-medium text-foreground/90"
                                        x-text="person.email"
                                        :title="person.email"
                                    ></p>
                                </div>

                                <div class="flex shrink-0 items-center gap-1.5">
                                    <!-- Accepted collaborators: show permission dropdown -->
                                    <template x-if="(person.status ?? 'accepted') === 'accepted'">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon:trailing="chevron-down"
                                                class="!h-auto !rounded-full !px-2 !py-0.5 !text-[10px] !font-semibold !uppercase !tracking-wide"
                                                @click.stop=""
                                            >
                                                <span x-text="person.permission"></span>
                                            </flux:button>

                                            <flux:menu class="!min-w-36 !text-[11px]">
                                                <flux:menu.radio.group>
                                                    <flux:menu.radio
                                                        @click="changePersonPermission(person, 'view')"
                                                        x-bind:checked="(person.permission_value ?? 'view') === 'view'"
                                                    >
                                                        {{ __('Can view') }}
                                                    </flux:menu.radio>
                                                    <flux:menu.radio
                                                        @click="changePersonPermission(person, 'edit')"
                                                        x-bind:checked="(person.permission_value ?? 'view') === 'edit'"
                                                    >
                                                        {{ __('Can edit') }}
                                                    </flux:menu.radio>
                                                </flux:menu.radio.group>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </template>

                                    <!-- Invitations (pending / declined / rejected): show status only -->
                                    <template x-if="person.status && (person.status ?? 'accepted') !== 'accepted'">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                            :class="{
                                                'bg-amber-500/10 text-amber-600 dark:text-amber-500': person.status === 'pending',
                                                'bg-red-500/10 text-red-600 dark:text-red-500': ['declined', 'rejected'].includes(person.status),
                                            }"
                                            role="status"
                                        >
                                            <span
                                                x-text="person.status === 'pending'
                                                    ? '{{ __('Pending') }}'
                                                    : (['declined', 'rejected'].includes(person.status)
                                                        ? '{{ __('Declined') }}'
                                                        : person.status)"
                                            ></span>
                                        </span>
                                    </template>

                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-full p-0.5 text-[10px] text-muted-foreground/60 transition-colors hover:text-red-600 hover:bg-red-500/10"
                                        @click.stop="removePerson(person)"
                                        :aria-label="(person.status ?? 'accepted') === 'accepted'
                                            ? '{{ __('Remove collaborator') }}'
                                            : '{{ __('Remove invitation') }}'"
                                    >
                                        <flux:icon name="x-mark" class="size-3.5" />
                                    </button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>

            <div
                class="flex flex-col items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/30 px-3 py-4 text-center"
                x-show="totalCount === 0"
                x-cloak
            >
                <p class="text-xs font-medium text-muted-foreground">
                    {{ __('No collaborators yet') }}
                </p>
                <p class="mt-1 text-[11px] text-muted-foreground/70">
                    {{ __('Use this menu later to invite collaborators.') }}
                </p>
            </div>
        </div>
    </div>
</div>

