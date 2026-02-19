@props([
    'item',
    'kind' => null,
    'readonly' => false,
])

@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Comment> $comments */
    $comments = $item->comments ?? collect();
    $totalComments = (int) ($item->comments_count ?? $comments->count());
    $initialVisibleCount = min(3, $totalComments);

    $kindLabel = match ($kind) {
        'project' => __('Project'),
        'event' => __('Event'),
        'task' => __('Task'),
        default => null,
    };

    $currentUserId = \Illuminate\Support\Facades\Auth::id();

    $commentsForJs = $comments
        ->map(function (\App\Models\Comment $comment) use ($currentUserId): array {
            $userName = $comment->user?->name ?? $comment->user?->email ?? __('Unknown user');

            return [
                'id' => $comment->id,
                'userName' => $userName,
                'initials' => (string) \Illuminate\Support\Str::of($userName)->substr(0, 2),
                'content' => $comment->content,
                'createdDiff' => optional($comment->created_at)->diffForHumans(),
                'canManage' => $currentUserId && (int) $comment->user_id === (int) $currentUserId,
            ];
        })
        ->values();

    $currentUser = \Illuminate\Support\Facades\Auth::user();
    $currentUserName = $currentUser?->name ?? $currentUser?->email ?? __('You');
    $currentUserInitials = (string) \Illuminate\Support\Str::of($currentUserName)->substr(0, 2);

    $commentableType = get_class($item);
    $commentsPanelId = 'comments-panel-'.($kind ?? 'item').'-'.$item->id;
@endphp

<div
    wire:ignore
    class="mt-1.5 pt-1.5 text-[11px]"
    x-data="{
        readonly: @js($readonly),
        alpineReady: false,
        isOpen: false,
        comments: @js($commentsForJs),
        totalCount: {{ $totalComments }},
        visibleCount: {{ $initialVisibleCount }},
        visibleComments: [],
        isAddingComment: false,
        newCommentContent: '',
        savingComment: false,
        commentSnapshot: '',
        commentsBackup: [],
        totalBackup: 0,
        visibleBackup: 0,
        justCanceledComment: false,
        savedCommentViaEnter: false,
        commentErrorToast: @js(__('Could not add comment. Please try again.')),
        commentValidationToast: @js(__('Comment cannot be empty.')),
        currentUserName: @js($currentUserName),
        currentUserInitials: @js($currentUserInitials),
        currentUserRelativeNow: @js(__('Just now')),
        commentableType: @js($commentableType),
        commentableId: @js($item->id),
        editingCommentId: null,
        editedCommentContent: '',
        commentEditSnapshot: '',
        savingCommentEdit: false,
        justCanceledCommentEdit: false,
        savedCommentEditViaEnter: false,
        commentUpdateErrorToast: @js(__('Could not update comment. Please try again.')),
        deletingCommentIds: new Set(),
        deleteCommentErrorToast: @js(__('Could not delete comment. Please try again.')),
        loadingMoreComments: false,
        toggle() {
            this.isOpen = !this.isOpen;
        },
        updateVisibleComments() {
            this.visibleComments = this.comments.slice(0, this.visibleCount);
        },
        async loadMore() {
            if (this.visibleCount < this.comments.length) {
                this.visibleCount = Math.min(this.visibleCount + 3, this.comments.length);
                this.updateVisibleComments();
                return;
            }
            if (this.comments.length >= this.totalCount || this.loadingMoreComments) {
                return;
            }
            this.loadingMoreComments = true;
            try {
                const response = await $wire.$parent.$call('loadMoreComments', this.commentableType, this.commentableId, this.comments.length);
                if (response?.comments?.length) {
                    this.comments.push(...response.comments);
                    this.visibleCount = this.comments.length;
                    this.updateVisibleComments();
                }
            } finally {
                this.loadingMoreComments = false;
            }
        },
        startAddingComment() {
            if (this.readonly || this.savingComment) {
                return;
            }
            this.isOpen = true;
            this.commentSnapshot = this.newCommentContent;
            this.isAddingComment = true;
            this.$nextTick(() => {
                const input = this.$refs.commentInput;
                if (input) {
                    input.focus();
                    const length = input.value.length;
                    input.setSelectionRange(length, length);
                }
            });
        },
        cancelAddingComment() {
            this.justCanceledComment = true;
            this.savedCommentViaEnter = false;
            this.newCommentContent = this.commentSnapshot || '';
            this.isAddingComment = false;
            this.commentSnapshot = '';
            setTimeout(() => { this.justCanceledComment = false; }, 100);
        },
        rollbackNewCommentOptimisticState() {
            this.comments = this.commentsBackup;
            this.totalCount = this.totalBackup;
            this.visibleCount = this.visibleBackup;
            this.updateVisibleComments();
            this.newCommentContent = this.commentSnapshot;
            this.isAddingComment = true;
        },
        async saveComment() {
            if (this.savingComment || this.justCanceledComment) {
                return;
            }

            const trimmed = (this.newCommentContent || '').trim();
            if (!trimmed) {
                $wire.$dispatch('toast', { type: 'error', message: this.commentValidationToast });
                return;
            }

            this.commentsBackup = this.comments.slice();
            this.totalBackup = this.totalCount;
            this.visibleBackup = this.visibleCount;
            this.commentSnapshot = this.newCommentContent;

            const tempId = `temp-${Date.now()}`;
            const optimisticComment = {
                id: tempId,
                userName: this.currentUserName,
                initials: this.currentUserInitials,
                content: trimmed,
                createdDiff: this.currentUserRelativeNow,
                canManage: true,
            };

            this.comments.unshift(optimisticComment);
            this.totalCount = this.totalCount + 1;
            this.visibleCount = this.visibleCount + 1;
            this.updateVisibleComments();
            this.newCommentContent = '';
            this.isAddingComment = false;

            this.savingComment = true;

            try {
                const payload = {
                    commentableType: this.commentableType,
                    commentableId: this.commentableId,
                    content: trimmed,
                };

                const promise = $wire.$parent.$call('addComment', payload);
                const newId = await promise;

                const numericId = Number(newId);
                if (!Number.isFinite(numericId)) {
                    this.rollbackNewCommentOptimisticState();
                    $wire.$dispatch('toast', { type: 'error', message: this.commentErrorToast });

                    return;
                }

                const idx = this.comments.findIndex((c) => String(c.id) === String(tempId));
                if (idx !== -1) {
                    this.comments[idx].id = numericId;
                }
            } catch (error) {
                this.rollbackNewCommentOptimisticState();
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.commentErrorToast });
            } finally {
                this.savingComment = false;
                if (this.savedCommentViaEnter) {
                    setTimeout(() => { this.savedCommentViaEnter = false; }, 100);
                }
            }
        },
        handleCommentKeydown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                this.cancelAddingComment();
            } else if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.savedCommentViaEnter = true;
                this.saveComment();
            }
        },
        handleCommentBlur() {
            if (!this.savedCommentViaEnter && !this.justCanceledComment) {
                this.cancelAddingComment();
            }
        },
        startEditingExistingComment(comment) {
            if (this.readonly || this.savingCommentEdit || !comment?.canManage) {
                return;
            }
            this.commentEditSnapshot = comment.content ?? '';
            this.editedCommentContent = comment.content ?? '';
            this.editingCommentId = comment.id ?? null;
        },
        cancelEditingExistingComment() {
            this.justCanceledCommentEdit = true;
            this.savedCommentEditViaEnter = false;
            this.editingCommentId = null;
            this.editedCommentContent = '';
            this.commentEditSnapshot = '';
            setTimeout(() => { this.justCanceledCommentEdit = false; }, 100);
        },
        async saveEditedComment() {
            if (this.savingCommentEdit || this.justCanceledCommentEdit || this.editingCommentId === null) {
                return;
            }

            const trimmed = (this.editedCommentContent || '').trim();
            const originalTrimmed = (this.commentEditSnapshot || '').toString().trim();

            // If empty or unchanged, just exit edit mode without calling the server.
            if (!trimmed || trimmed === originalTrimmed) {
                this.editingCommentId = null;
                this.editedCommentContent = '';
                this.commentEditSnapshot = '';
                return;
            }

            const index = this.comments.findIndex((c) => String(c.id) === String(this.editingCommentId));
            if (index === -1) {
                this.editingCommentId = null;
                this.editedCommentContent = '';
                this.commentEditSnapshot = '';
                return;
            }

            const snapshot = { ...this.comments[index] };

            // Optimistic update
            this.comments[index].content = trimmed;

            this.savingCommentEdit = true;

            try {
                const numericId = Number(this.editingCommentId);
                if (!Number.isFinite(numericId)) {
                    this.comments[index] = snapshot;
                    this.editingCommentId = null;
                    this.editedCommentContent = '';
                    this.commentEditSnapshot = '';
                    return;
                }

                const payload = { content: trimmed };
                const ok = await $wire.$parent.$call('updateComment', numericId, payload);
                if (!ok) {
                    this.comments[index] = snapshot;
                    $wire.$dispatch('toast', { type: 'error', message: this.commentUpdateErrorToast });

                    this.editingCommentId = null;
                    this.editedCommentContent = '';
                    this.commentEditSnapshot = '';

                    return;
                }

                this.editingCommentId = null;
                this.editedCommentContent = '';
                this.commentEditSnapshot = '';
            } catch (error) {
                this.comments[index] = snapshot;
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.commentUpdateErrorToast });
            } finally {
                this.savingCommentEdit = false;
                if (this.savedCommentEditViaEnter) {
                    setTimeout(() => { this.savedCommentEditViaEnter = false; }, 100);
                }
            }
        },
        handleCommentEditKeydown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                this.cancelEditingExistingComment();
            } else if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.savedCommentEditViaEnter = true;
                this.saveEditedComment();
            }
        },
        handleCommentEditBlur() {
            if (!this.savedCommentEditViaEnter && !this.justCanceledCommentEdit) {
                this.saveEditedComment();
            }
        },
        async deleteExistingComment(comment) {
            if (this.readonly || !comment?.canManage) return;
            const id = comment?.id ?? null;
            if (id === null || String(id).startsWith('temp-')) {
                return;
            }

            if (this.deletingCommentIds?.has(id)) {
                return;
            }

            const index = this.comments.findIndex((c) => String(c.id) === String(id));
            if (index === -1) {
                return;
            }

            const commentsBackup = this.comments.slice();
            const totalBackup = this.totalCount;
            const visibleBackup = this.visibleCount;

            try {
                this.deletingCommentIds = this.deletingCommentIds || new Set();
                this.deletingCommentIds.add(id);

                this.comments.splice(index, 1);
                this.totalCount = Math.max(0, this.totalCount - 1);
                if (this.visibleCount > this.totalCount) {
                    this.visibleCount = this.totalCount;
                }
                this.updateVisibleComments();

                const numericId = Number(id);
                if (!Number.isFinite(numericId)) {
                    this.comments = commentsBackup;
                    this.totalCount = totalBackup;
                    this.visibleCount = visibleBackup;
                    this.updateVisibleComments();

                    return;
                }

                const ok = await $wire.$parent.$call('deleteComment', numericId);
                if (!ok) {
                    this.comments = commentsBackup;
                    this.totalCount = totalBackup;
                    this.visibleCount = visibleBackup;
                    this.updateVisibleComments();
                    $wire.$dispatch('toast', { type: 'error', message: this.deleteCommentErrorToast });

                    return;
                }
            } catch (error) {
                this.comments = commentsBackup;
                this.totalCount = totalBackup;
                this.visibleCount = visibleBackup;
                this.updateVisibleComments();
                $wire.$dispatch('toast', { type: 'error', message: error.message || this.deleteCommentErrorToast });
            } finally {
                this.deletingCommentIds?.delete(id);
            }
        },
    }"
    x-init="updateVisibleComments(); alpineReady = true"
>
    {{-- Server-rendered first paint --}}
    <button
        x-show="!alpineReady"
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted/80"
        aria-controls="{{ $commentsPanelId }}"
    >
        <flux:icon name="chat-bubble-left-ellipsis" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Comments') }}
            </span>
            <span class="text-[11px]">
                ({{ $totalComments }})
            </span>
        </span>
        <span class="inline-flex items-center justify-center transition-transform duration-150 focus-hide-chevron">
            <flux:icon
                name="chevron-down"
                class="size-3"
            />
        </span>
    </button>

    {{-- Alpine reactive (replaces server content when hydrated) --}}
    <button
        x-show="alpineReady"
        x-cloak
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted/80"
        @click="toggle()"
        :aria-expanded="isOpen.toString()"
        aria-controls="{{ $commentsPanelId }}"
    >
        <flux:icon name="chat-bubble-left-ellipsis" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Comments') }}
            </span>
            <span class="text-[11px]">
                (<span x-text="totalCount"></span>)
            </span>
        </span>
        <span
            class="inline-flex items-center justify-center transition-transform duration-150 focus-hide-chevron"
            :class="isOpen ? 'rotate-180' : ''"
        >
            <flux:icon
                name="chevron-down"
                class="size-3"
            />
        </span>
    </button>

    <div
        id="{{ $commentsPanelId }}"
        x-show="isOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-0.5"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-0.5"
        class="mt-1.5 space-y-1.5"
        role="region"
        :aria-hidden="(!isOpen).toString()"
    >
        <div class="space-y-1 pb-1.5" x-show="!readonly">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-muted-foreground hover:text-foreground/80"
                x-show="!isAddingComment"
                x-cloak
                @click="startAddingComment()"
            >
                <flux:icon name="plus" class="size-3" />
                <span>{{ __('Add comment') }}</span>
            </button>

            <div x-show="isAddingComment" x-cloak>
                <textarea
                    x-ref="commentInput"
                    x-model="newCommentContent"
                    x-on:keydown="handleCommentKeydown($event)"
                    x-on:blur="handleCommentBlur()"
                    rows="2"
                    class="w-full min-w-0 rounded-md bg-muted/30 px-2 py-1 text-[11px] leading-snug outline-none ring-1 ring-transparent focus:bg-background/70 focus:ring-1 focus:ring-border dark:bg-muted/20"
                    placeholder="{{ __('Add a comment...') }}"
                ></textarea>
                <p class="mt-0.5 text-[10px] text-muted-foreground/80">
                    {{ __('Press Enter to save, Shift+Enter for a new line, or Esc to cancel.') }}
                </p>
            </div>
        </div>

        <template x-if="totalCount === 0">
            <p class="text-[11px] text-muted-foreground/80">
                {{ __('No comments yet.') }}
                <span class="ml-1" x-show="!readonly" x-cloak>
                    {{ __('Be the first to comment.') }}
                </span>
            </p>
        </template>

        <template x-if="totalCount > 0">
            <div class="space-y-1.5">
                <template x-for="(comment, index) in visibleComments" :key="comment.id ?? index">
                    <div
                        class="flex items-start gap-2 rounded-md bg-muted/60 px-2 py-1.5"
                        x-cloak
                    >
                        <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary uppercase">
                            <span x-text="comment.initials"></span>
                        </div>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-[11px] font-semibold text-foreground/90" x-text="comment.userName"></p>
                                <div class="flex items-center gap-1">
                                    <span class="shrink-0 text-[10px] text-muted-foreground/80" x-text="comment.createdDiff"></span>
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-full p-0.5 text-[10px] text-muted-foreground hover:text-foreground/80 hover:bg-muted/80"
                                        x-show="!readonly && comment.canManage && comment.id && !String(comment.id).startsWith('temp-')"
                                        x-cloak
                                        @click="startEditingExistingComment(comment)"
                                        aria-label="{{ __('Edit comment') }}"
                                        title="{{ __('Edit comment') }}"
                                    >
                                        <flux:icon name="pencil-square" class="size-3" />
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-full p-0.5 text-[10px] text-red-500/80 hover:text-red-600 hover:bg-red-500/5"
                                        x-show="!readonly && comment.canManage && comment.id && !String(comment.id).startsWith('temp-')"
                                        x-cloak
                                        @click="deleteExistingComment(comment)"
                                        aria-label="{{ __('Delete comment') }}"
                                        title="{{ __('Delete comment') }}"
                                    >
                                        <flux:icon name="trash" class="size-3" />
                                    </button>
                                </div>
                            </div>
                            <div
                                x-effect="
                                    if (editingCommentId === comment.id) {
                                        $nextTick(() => {
                                            const input = $el.querySelector('textarea');
                                            if (input) {
                                                input.focus();
                                                const length = input.value.length;
                                                input.setSelectionRange(length, length);
                                            }
                                        });
                                    }
                                "
                            >
                                <div x-show="editingCommentId !== comment.id" x-cloak>
                                    <p class="whitespace-pre-line break-words text-[11px] text-foreground/90" x-text="comment.content"></p>
                                </div>
                                <div x-show="editingCommentId === comment.id" x-cloak>
                                    <textarea
                                        x-model="editedCommentContent"
                                        x-on:keydown="handleCommentEditKeydown($event)"
                                        x-on:blur="handleCommentEditBlur()"
                                        rows="2"
                                        class="w-full min-w-0 rounded-md bg-muted/30 px-2 py-1 text-[11px] leading-snug outline-none ring-1 ring-transparent focus:bg-background/70 focus:ring-1 focus:ring-border dark:bg-muted/20"
                                        placeholder="{{ __('Edit comment...') }}"
                                    ></textarea>
                                    <p class="mt-0.5 text-[10px] text-muted-foreground/80">
                                        {{ __('Press Enter to save, Shift+Enter for a new line, or Esc to cancel. Clicking outside will also save changes.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <button
                    type="button"
                    class="mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-primary hover:text-primary/80 disabled:opacity-70"
                    :class="{ 'animate-pulse': loadingMoreComments }"
                    x-show="visibleCount < totalCount"
                    x-cloak
                    :disabled="loadingMoreComments"
                    @click="loadMore()"
                >
                    <flux:icon name="chevron-down" class="size-3 focus-hide-chevron" />
                    <span x-text="loadingMoreComments ? '{{ __('Loading...') }}' : '{{ __('Load more comments') }}'"></span>
                </button>
            </div>
        </template>
    </div>
</div>

