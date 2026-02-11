@props([
    'item',
    'kind',
    'position' => 'top',
    'align' => 'end',
])

@php
    $kind = strtolower((string) $kind);

    // Only the owner can manage collaborations
    $currentUser = auth()->user();
    $isOwner = $currentUser && $item->user_id && (int) $currentUser->id === (int) $item->user_id;
    $readonly = !$isOwner;

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
        ->when(!$readonly, fn ($collection) => $collection
            ->merge($pendingCollection)
            ->merge($declinedCollection)
        )
        ->values()
        ->all();

    $triggerBaseClass = 'cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out';

    $labelByKind = match ($kind) {
        'task' => __('Task collaborators'),
        'event' => __('Event collaborators'),
        'project' => __('Project collaborators'),
        default => __('Collaborators'),
    };

    $collaboratableType = match ($kind) {
        'task' => 'task',
        'event' => 'event',
        'project' => 'project',
        default => null,
    };

    // Only owner can manage collaborations
    $canManageCollaborations = $isOwner;
    
    // Check if user can edit the item (for message display)
    $canEditItem = $currentUser?->can('update', $item) ?? false;
@endphp

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: 320,
        panelWidthEst: 300,
        canManageCollaborations: @js($canManageCollaborations),
        currentUserId: @js($currentUser?->id),
        people: @js($allCollaborators),
        removingKeys: new Set(),
        updatingPermissionKeys: new Set(),
        removeErrorToast: @js(__('Could not remove collaborator. Please try again.')),
        permissionViewLabel: @js(__('Can view')),
        permissionEditLabel: @js(__('Can edit')),
        permissionUpdateErrorToast: @js(__('Could not update collaborator permission. Please try again.')),

        collabType: @js($collaboratableType),
        collabId: @js($item->id),
        peopleBackup: [],
        inviteSnapshot: { email: '', permission: @js(\App\Enums\CollaborationPermission::Edit->value) },
        newEmail: '',
        newPermission: @js(\App\Enums\CollaborationPermission::Edit->value),
        invitePermissionIsEdit: true,
        isInviting: false,
        savingInvite: false,
        inviteInlineError: '',
        justCanceledInvite: false,
        savedInviteViaEnter: false,
        inviteValidationToast: @js(__('Please enter a valid email address.')),
        inviteErrorToast: @js(__('Could not send invitation. Please try again.')),

        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            this.$refs.button && this.$refs.button.focus();

            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = vw < 480 ? 24 : 320;
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

        rollbackRemovePerson(peopleBackup) {
            this.people = [...(peopleBackup ?? [])];
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

            // Determine backend method + identifier based on status and whether this is the current user
            let method = null;
            let id = null;

            if (status === 'accepted') {
                const isSelf = this.currentUserId != null
                    && Number(person.id ?? NaN) === Number(this.currentUserId);

                method = isSelf ? 'leaveCollaboration' : 'removeCollaborator';
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

                // For self-leave, optimistically hide the card immediately
                if (method === 'leaveCollaboration') {
                    this.$dispatch('collaboration-self-left');
                }

                const numericId = Number(id);
                if (!Number.isFinite(numericId)) {
                    this.rollbackRemovePerson(peopleBackup);
                    return;
                }

                const ok = await $wire.$parent.$call(method, numericId);
                if (!ok) {
                    this.rollbackRemovePerson(peopleBackup);
                    $wire.$dispatch('toast', { type: 'error', message: this.removeErrorToast });

                    if (method === 'leaveCollaboration') {
                        this.$dispatch('item-update-rollback');
                    }
                }
            } catch (error) {
                this.rollbackRemovePerson(peopleBackup);
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.removeErrorToast });

                if (method === 'leaveCollaboration') {
                    this.$dispatch('item-update-rollback');
                }
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

            const index = this.people.findIndex((p) => p === person);
            if (index === -1) {
                return;
            }

            const snapshot = { ...person };

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
                    this.people[index] = snapshot;

                    return;
                }

                const ok = await $wire.$parent.$call('updateCollaboratorPermission', numericId, normalized);
                if (!ok) {
                    this.people[index] = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.permissionUpdateErrorToast });
                }
            } catch (error) {
                this.people[index] = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.permissionUpdateErrorToast });
            } finally {
                this.updatingPermissionKeys?.delete(key);
            }
        },

        startInviting() {
            if (this.savingInvite) return;
            this.open = true;
            this.inviteSnapshot = { email: this.newEmail, permission: this.newPermission };
            this.invitePermissionIsEdit = (this.newPermission === 'edit');
            this.isInviting = true;
            this.inviteInlineError = '';
            this.$nextTick(() => {
                const input = this.$refs.inviteEmailInput;
                if (input) {
                    input.focus();
                    const length = input.value.length;
                    input.setSelectionRange(length, length);
                }
            });
        },

        cancelInviting() {
            this.justCanceledInvite = true;
            this.savedInviteViaEnter = false;
            this.newEmail = this.inviteSnapshot?.email || '';
            this.newPermission = this.inviteSnapshot?.permission || @js(\App\Enums\CollaborationPermission::Edit->value);
            this.invitePermissionIsEdit = (this.newPermission === 'edit');
            this.isInviting = false;
            this.inviteSnapshot = { email: '', permission: @js(\App\Enums\CollaborationPermission::Edit->value) };
            setTimeout(() => { this.justCanceledInvite = false; }, 100);
        },

        rollbackInviteOptimisticState() {
            this.people = [...(this.peopleBackup ?? [])];
        },

        async saveInvite() {
            if (this.savingInvite || this.justCanceledInvite) return;

            const trimmed = (this.newEmail || '').trim();
            if (!trimmed || !trimmed.includes('@')) {
                this.inviteInlineError = this.inviteValidationToast;
                return;
            }

            if (!this.collabType || this.collabId == null) {
                this.inviteInlineError = this.inviteErrorToast;
                return;
            }

            this.peopleBackup = [...this.people];
            const permission = this.newPermission ?? @js(\App\Enums\CollaborationPermission::Edit->value);
            this.inviteSnapshot = { email: trimmed, permission };

            const permissionLabel = permission === 'edit' ? this.permissionEditLabel : this.permissionViewLabel;
            const optimisticRow = {
                id: 'temp-' + Date.now(),
                email: trimmed,
                name: null,
                permission_value: permission,
                permission: permissionLabel,
                status: 'sending',
            };

            this.people = [...this.people, optimisticRow];
            this.newEmail = '';
            this.newPermission = @js(\App\Enums\CollaborationPermission::Edit->value);
            this.invitePermissionIsEdit = true;
            this.isInviting = false;
            this.inviteInlineError = '';
            this.savingInvite = true;

            try {
                const payload = {
                    collaboratableType: this.collabType,
                    collaboratableId: this.collabId,
                    email: trimmed,
                    permission: this.inviteSnapshot.permission,
                };
                const result = await $wire.$parent.$call('inviteCollaborator', payload);

                if (result?.success === true) {
                    const idx = this.people.findIndex((p) => String(p.id) === String(optimisticRow.id));
                    if (idx !== -1) {
                        this.people[idx].status = 'pending';
                        if (result?.invitationId != null) {
                            this.people[idx].id = result.invitationId;
                        }
                    }
                } else {
                    this.rollbackInviteOptimisticState();
                    this.inviteInlineError = result?.message || this.inviteErrorToast;
                }
            } catch (error) {
                this.rollbackInviteOptimisticState();
                this.inviteInlineError = error?.message || this.inviteErrorToast;
            } finally {
                this.savingInvite = false;
                if (this.savedInviteViaEnter) {
                    setTimeout(() => { this.savedInviteViaEnter = false; }, 100);
                }
            }
        },

        handleInviteKeydown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                this.cancelInviting();
            } else if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.savedInviteViaEnter = true;
                this.saveInvite();
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
        class="absolute z-50 w-fit min-w-[220px] max-w-[min(320px,calc(100vw-2rem))] flex flex-col rounded-lg border border-border bg-white shadow-lg dark:bg-zinc-900"
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
                            :key="(person.collaboration_id ?? person.id) + '-' + (person.status ?? 'accepted')"
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
                                    <!-- Accepted collaborators: show permission toggle only if user can update collaboration -->
                                    <template x-if="(person.status ?? 'accepted') === 'accepted' && canManageCollaborations">
                                        <button
                                            type="button"
                                            class="shrink-0 inline-flex items-center gap-1 rounded-full border border-black/10 px-2 py-0.5 text-[11px] font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                                            :class="(person.permission_value ?? 'view') === 'edit' ? 'bg-emerald-500/10 text-emerald-600 shadow-sm dark:text-emerald-400' : 'bg-muted text-muted-foreground'"
                                            :disabled="updatingPermissionKeys?.has(`perm-${person.collaboration_id ?? ''}`)"
                                            @click.stop="togglePersonPermission(person)"
                                        >
                                            <span class="uppercase" x-text="person.permission"></span>
                                        </button>
                                    </template>

                                    <!-- Invitations (pending / sending / declined / rejected): show status only -->
                                    <template x-if="person.status && (person.status ?? 'accepted') !== 'accepted'">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide inline-flex items-center gap-1"
                                            :class="{
                                                'bg-amber-500/10 text-amber-600 dark:text-amber-500': person.status === 'pending',
                                                'bg-muted text-muted-foreground': person.status === 'sending',
                                                'bg-red-500/10 text-red-600 dark:text-red-500': ['declined', 'rejected'].includes(person.status),
                                            }"
                                            role="status"
                                        >
                                            <flux:icon x-show="person.status === 'sending'" class="size-3 animate-spin" name="arrow-path" x-cloak />
                                            <span
                                                x-text="person.status === 'sending'
                                                    ? '{{ __('Sending…') }}'
                                                    : (person.status === 'pending'
                                                        ? '{{ __('Pending') }}'
                                                        : (['declined', 'rejected'].includes(person.status)
                                                            ? '{{ __('Declined') }}'
                                                            : person.status))"
                                            ></span>
                                        </span>
                                    </template>

                                    <!-- Current user can always remove themselves from an accepted collaboration -->
                                    <flux:tooltip content="{{ __('Remove yourself from this item') }}">
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center rounded-full border border-black/10 px-2 py-0.5 text-[10px] font-medium text-muted-foreground hover:text-red-600 hover:bg-red-500/10"
                                            x-show="(person.status ?? 'accepted') === 'accepted' && currentUserId != null && Number(person.id ?? NaN) === Number(currentUserId)"
                                            x-cloak
                                            :disabled="removingKeys?.has(`${person.status ?? 'accepted'}-${person.id ?? person.email}`)"
                                            @click.stop="removePerson(person)"
                                        >
                                            {{ __('Remove') }}
                                        </button>
                                    </flux:tooltip>

                                    <flux:tooltip content="{{ __('Remove collaborator or invitation') }}">
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center rounded-full p-0.5 text-[10px] text-muted-foreground/60 transition-colors hover:text-red-600 hover:bg-red-500/10"
                                            x-show="canManageCollaborations && (person.status ?? 'accepted') !== 'sending'"
                                            x-cloak
                                            :disabled="removingKeys?.has(`${person.status ?? 'accepted'}-${person.id ?? person.email}`)"
                                            @click.stop="removePerson(person)"
                                            :aria-label="(person.status ?? 'accepted') === 'accepted'
                                                ? '{{ __('Remove collaborator') }}'
                                                : '{{ __('Remove invitation') }}'"
                                        >
                                            <flux:icon name="x-mark" class="size-3.5" />
                                        </button>
                                    </flux:tooltip>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>

            <div
                class="flex flex-col items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/30 px-3 py-4 text-center"
                x-show="acceptedCount === 0"
                x-cloak
            >
                <p class="text-xs font-medium text-muted-foreground">
                    {{ __('No collaborators yet') }}
                </p>
                <p class="mt-1 text-[11px] text-muted-foreground/70">
                    {{ __('Invite someone by email below.') }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5 border-t border-border/50 pt-2">
                @if($isOwner)
                <div class="flex w-full items-center gap-1.5" x-show="inviteInlineError" x-cloak>
                    <flux:icon name="exclamation-triangle" class="size-3.5 shrink-0 text-red-600 dark:text-red-400" />
                    <p class="text-[11px] font-medium text-red-600 dark:text-red-400" x-text="inviteInlineError"></p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-muted-foreground hover:text-foreground/80 disabled:pointer-events-none disabled:opacity-50"
                    x-show="!isInviting"
                    x-cloak
                    :disabled="savingInvite"
                    @click="startInviting()"
                >
                    <flux:icon name="plus" class="size-3" />
                    <span>{{ __('Invite collaborator') }}</span>
                </button>

                <div x-show="isInviting" x-cloak class="flex flex-col gap-2">
                    <flux:input
                        type="email"
                        x-ref="inviteEmailInput"
                        x-model="newEmail"
                        @keydown="handleInviteKeydown($event)"
                        placeholder="{{ __('colleague@example.com') }}"
                        class="w-full text-[11px]! py-1.5!"
                    />
                    <div class="flex flex-row items-center justify-center gap-2 mx-2">
                        <button
                            type="button"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2 py-0.5 text-[11px] font-medium transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10"
                            :class="invitePermissionIsEdit ? 'bg-emerald-500/10 text-emerald-600 shadow-sm dark:text-emerald-400' : 'bg-muted text-muted-foreground'"
                            @click="invitePermissionIsEdit = !invitePermissionIsEdit; newPermission = invitePermissionIsEdit ? 'edit' : 'view';"
                        >
                            <flux:icon name="pencil-square" class="size-3" />
                            <span class="inline-flex items-baseline gap-1">
                                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                    {{ __('Permission') }}:
                                </span>
                                <span class="uppercase" x-text="invitePermissionIsEdit ? permissionEditLabel : permissionViewLabel"></span>
                            </span>
                        </button>
                        <flux:button
                            size="xs"
                            icon="paper-airplane"
                            class="shrink-0"
                            x-bind:disabled="savingInvite || !(newEmail || '').trim()"
                            @click="saveInvite()"
                        >
                            {{ __('Invite') }}
                        </flux:button>
                    </div>
                </div>
                @else
                <p class="text-[11px] text-muted-foreground text-center py-1">
                    @if($canEditItem)
                        {{ __('You can edit this item, but only the owner can manage collaborators.') }}
                    @else
                        {{ __('You can view this item only.') }}
                    @endif
                </p>
                @endif
            </div>
        </div>
    </div>
</div>

