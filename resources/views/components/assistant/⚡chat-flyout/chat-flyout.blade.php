
<div
    wire:ignore
    x-data="assistantChatFlyout($wire, {{ \Illuminate\Support\Js::from([
        'threadId' => $this->threadId,
        'workspaceUrl' => route('workspace'),
        'messages' => $this->messages,
        'pendingAssistantCount' => $this->pendingAssistantCount,
        'currentTraceId' => $this->currentTraceId,
        'suggestedPrompts' => [
            (string) __('Prioritise my tasks for this week.'),
            (string) __('Schedule my events for the next few days.'),
            (string) __('List tasks, events, and projects with no due date.'),
            (string) __('Filter my projects to those I should focus on today.'),
        ],
    ]) }})"
    class="flex h-full flex-col"
>
    <div class="border-b border-border/60 px-4 py-3">
        <div class="flex items-center gap-2 min-w-0">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <img
                    src="{{ asset('images/robot-face-svgrepo-com.svg') }}"
                    alt="{{ __('TaskLyst assistant avatar') }}"
                    class="h-7 w-7 rounded-full border border-emerald-500/40 bg-background"
                >
            </div>
            <div class="flex-1 min-w-0">
                <flux:heading size="md" class="truncate">
                    {{ __('TaskLyst Assistant') }}
                </flux:heading>
                <flux:text class="mt-0.5 text-xs text-muted-foreground">
                    {{ __('Helps you prioritise and schedule tasks, events, and projects.') }}
                </flux:text>
            </div>
        </div>
    </div>

    <div
        x-ref="scroller"
        class="flex-1 overflow-y-auto px-4 py-3 space-y-3"
    >
        <template x-if="messages.length === 0">
            <div class="flex h-full flex-col items-center justify-center text-center gap-3">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-foreground">
                        {{ __('Plan what to work on next') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Use the assistant to prioritise, schedule, and filter your tasks, events, and projects.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 max-w-sm">
                    <template x-for="prompt in suggestedPrompts" :key="prompt">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="text-[11px]! px-3 py-1.5! whitespace-normal text-left cursor-pointer hover:bg-muted"
                            @click="usePrompt(prompt)"
                        >
                            <span x-text="prompt"></span>
                        </flux:button>
                    </template>
                </div>
            </div>
        </template>

        <template x-for="message in messages" :key="message.id">
            <div
                class="flex w-full"
                :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
            >
                <div
                    class="flex items-start gap-2 max-w-[80%]"
                    x-data="{ structured: getStructured(message), snapshot: getSnapshot(message) }"
                >
                    <template x-if="message.role === 'assistant'">
                        <div class="mt-0.5 shrink-0">
                            <img
                                src="{{ asset('images/robot-face-svgrepo-com.svg') }}"
                                alt="{{ __('TaskLyst assistant avatar') }}"
                                class="h-6 w-6 rounded-full border border-border/60 bg-background"
                            >
                        </div>
                    </template>

                    <div
                        class="flex-1 rounded-lg px-3 py-2 text-xs leading-relaxed"
                        :class="message.role === 'user'
                            ? 'bg-emerald-500 text-white dark:bg-emerald-500'
                            : 'bg-muted text-foreground'"
                    >
                    <p class="whitespace-pre-wrap" x-text="message.content"></p>

                    <template
                        x-if="message.role === 'user'
                            && message.metadata
                            && message.metadata.llm_cancelled"
                    >
                        <div class="mt-1 inline-flex items-center gap-1 rounded-full bg-background/20 px-2 py-0.5 text-[10px] font-medium text-zinc-900/90 dark:text-zinc-100/90">
                            <flux:icon name="stop-circle" class="size-3" />
                            <span>{{ __('Request stopped') }}</span>
                        </div>
                    </template>

                    <template x-if="message.role === 'assistant'">
                        <div class="mt-2 space-y-2">
                            <template x-if="snapshot.reasoning === 'off_topic_query'">
                                <div class="flex gap-1.5 rounded-md border border-amber-500/40 bg-amber-500/5 px-2.5 py-2 text-[11px] text-amber-900 dark:text-amber-100">
                                    <flux:icon name="shield-exclamation" class="mt-0.5 size-3.5 text-amber-500" />
                                    <div class="space-y-0.5">
                                        <p class="font-medium">
                                            {{ __('Out of scope for TaskLyst Assistant') }}
                                        </p>
                                        <p class="text-[10px] text-amber-900/80 dark:text-amber-100/80">
                                            {{ __('I can only help with your tasks, events, and projects. Try asking about your schedule, priorities, or workload.') }}
                                        </p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="snapshot.reasoning === 'social_closing'">
                                <div class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-[10px] text-muted-foreground">
                                    <flux:icon name="hand-thumb-up" class="size-3" />
                                    <span>{{ __('Closing reply') }}</span>
                                </div>
                            </template>

                            <template x-if="isSchedulingIntent(message)">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Proposed schedule') }}
                                    </p>

                                    <div class="space-y-0.5 text-[11px] text-muted-foreground">
                                        <template x-if="structured.start_datetime || structured.end_datetime">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('When:') }}
                                                </span>
                                                <span
                                                    x-text="formatTimeRange(structured)"
                                                ></span>
                                            </p>
                                        </template>

                                        <template x-if="structured.duration">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Duration:') }}
                                                </span>
                                                <span x-text="structured.duration"></span>
                                            </p>
                                        </template>

                                        <template x-if="structured.timezone">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Timezone:') }}
                                                </span>
                                                <span x-text="structured.timezone"></span>
                                            </p>
                                        </template>

                                        <template x-if="structured.location">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Location:') }}
                                                </span>
                                                <span x-text="structured.location"></span>
                                            </p>
                                        </template>

                                        <template x-if="structured.priority">
                                            <p class="flex items-center gap-1.5">
                                                <span class="font-medium text-foreground">
                                                    {{ __('Priority:') }}
                                                </span>
                                                <span
                                                    class="inline-flex rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] uppercase tracking-tight text-emerald-700 dark:text-emerald-300"
                                                    x-text="structured.priority"
                                                ></span>
                                            </p>
                                        </template>
                                    </div>

                                    <template x-if="structured.blockers && structured.blockers.length">
                                        <div class="mt-1 space-y-0.5">
                                            <p class="text-[11px] font-medium text-muted-foreground">
                                                {{ __('Blockers') }}
                                            </p>
                                            <ul class="list-disc pl-4 space-y-0.5 text-[11px] text-muted-foreground">
                                                <template
                                                    x-for="(blocker, index) in structured.blockers"
                                                    :key="`${index}-${blocker}`"
                                                >
                                                    <li x-text="blocker"></li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="structured.ranked_tasks && structured.ranked_tasks.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised tasks') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="item in structured.ranked_tasks"
                                            :key="`${item.rank}-${item.title}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${item.rank} ${item.title}`"></span>
                                                <span
                                                    x-show="item.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemEnd(item)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="structured.ranked_events && structured.ranked_events.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised events') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="event in structured.ranked_events"
                                            :key="`${event.rank}-${event.title}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${event.rank} ${event.title}`"></span>
                                                <span
                                                    x-show="event.start_datetime || event.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemRange(event)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="structured.ranked_projects && structured.ranked_projects.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised projects') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="project in structured.ranked_projects"
                                            :key="`${project.rank}-${project.name}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${project.rank} ${project.name}`"></span>
                                                <span
                                                    x-show="project.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemEnd(project)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="structured.scheduled_tasks && structured.scheduled_tasks.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled tasks') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="item in structured.scheduled_tasks"
                                            :key="item.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="item.title"></span>
                                                <span
                                                    x-show="item.start_datetime || item.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemRange(item)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="structured.scheduled_events && structured.scheduled_events.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled events') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="event in structured.scheduled_events"
                                            :key="event.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="event.title"></span>
                                                <span
                                                    x-show="event.start_datetime || event.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemRange(event)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="structured.scheduled_projects && structured.scheduled_projects.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled projects') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="project in structured.scheduled_projects"
                                            :key="project.name"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="project.name"></span>
                                                <span
                                                    x-show="project.start_datetime || project.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="formatItemRange(project)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="structured.listed_items && structured.listed_items.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Suggested items') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="item in structured.listed_items"
                                            :key="item.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="item.title"></span>
                                                <span
                                                    x-show="item.priority"
                                                    class="ml-1 inline-flex rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] uppercase tracking-tight text-emerald-700 dark:text-emerald-300"
                                                    x-text="item.priority"
                                                ></span>
                                                <span
                                                    x-show="item.end_datetime"
                                                    class="ml-1 text-[11px] text-muted-foreground"
                                                    x-text="formatItemEnd(item)"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="structured.next_steps && structured.next_steps.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Next steps') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="(step, index) in structured.next_steps"
                                            :key="`${index}-${step}`"
                                        >
                                            <li class="text-[11px]" x-text="step"></li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <div
                                x-show="
                                    snapshot.used_fallback
                                    || (
                                        typeof snapshot.validation_confidence === 'number'
                                        && snapshot.validation_confidence < 0.5
                                    )
                                "
                                x-cloak
                                class="mt-1 flex items-center gap-1.5"
                            >
                                <flux:icon name="information-circle" class="size-3 text-muted-foreground" />
                                <p class="text-[10px] text-muted-foreground">
                                    <span x-show="snapshot.used_fallback">
                                        {{ __('This suggestion used a fallback. Consider double-checking details.') }}
                                    </span>
                                    <span
                                        x-show="
                                            !snapshot.used_fallback
                                            && typeof snapshot.validation_confidence === 'number'
                                            && snapshot.validation_confidence < 0.5
                                        "
                                    >
                                        {{ __('Confidence is lower than usual. Check details before acting.') }}
                                    </span>
                                </p>
                            </div>

                            <template
                                x-if="
                                    isActionableIntent(message)
                                    && hasAppliableChanges(message)
                                    && !isRecommendationApplied(message)
                                "
                            >
                                <div class="mt-2 flex flex-col gap-1.5 rounded-md bg-emerald-500/5 px-2.5 py-2 ring-1 ring-emerald-500/40 dark:bg-emerald-500/10">
                                    <div class="flex items-start gap-1.5">
                                        <flux:icon name="question-mark-circle" class="mt-0.5 size-3.5 text-emerald-600 dark:text-emerald-300" />
                                        <p class="text-[11px] text-emerald-900 dark:text-emerald-100" x-text="formatAppliableSummary(message)"></p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="primary"
                                            class="text-[11px]! px-2.5 py-1!"
                                            x-bind:disabled="pendingRecommendationIds && pendingRecommendationIds.has(message.id)"
                                            @click="acceptRecommendation(message)"
                                        >
                                            <span>{{ __('Apply changes') }}</span>
                                        </flux:button>
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="ghost"
                                            class="text-[11px]! px-2.5 py-1!"
                                            x-bind:disabled="pendingRecommendationIds && pendingRecommendationIds.has(message.id)"
                                            @click="rejectRecommendation(message)"
                                        >
                                            <span>{{ __('Dismiss') }}</span>
                                        </flux:button>
                                    </div>
                                </div>
                            </template>

                            <template
                                x-if="
                                    isActionableIntent(message)
                                    && hasAppliableChanges(message)
                                    && isRecommendationApplied(message)
                                "
                            >
                                <div class="mt-2 inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10px] text-emerald-800 dark:text-emerald-100">
                                    <flux:icon name="check-circle" class="size-3" />
                                    <span x-show="snapshot.user_action === 'accept'">{{ __('Changes applied from this suggestion') }}</span>
                                    <span x-show="snapshot.user_action === 'reject'">{{ __('Suggestion dismissed') }}</span>
                                </div>
                            </template>

                            <div
                                x-show="contextEntityLabel(message) || contextIntentLabel(message)"
                                x-cloak
                                class="mt-1 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    x-show="contextEntityLabel(message)"
                                    class="inline-flex items-center rounded-full bg-background/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground ring-1 ring-border/60"
                                    x-text="contextEntityLabel(message)"
                                ></span>
                                <span
                                    x-show="contextIntentLabel(message)"
                                    class="inline-flex items-center rounded-full bg-background/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground ring-1 ring-border/60"
                                    x-text="contextIntentLabel(message)"
                                ></span>
                                <span
                                    x-show="isActionableIntent(message)"
                                    class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-emerald-500/40 dark:text-emerald-300"
                                >
                                    {{ __('Actionable') }}
                                </span>
                            </div>
                        </div>
                    </template>
                    </div>
                </div>
            </div>
        </template>

        <div
            x-show="(isSending && isSubmittingMessage) || pendingAssistantCount > 0"
            x-cloak
            class="flex w-full justify-start"
            aria-live="polite"
            aria-busy="true"
        >
            <div class="max-w-[80%] rounded-lg bg-muted px-3 py-2 text-xs text-foreground">
                <div class="flex items-center gap-2">
                    <flux:icon name="arrow-path" class="size-3.5 animate-spin text-muted-foreground" />
                    <span>{{ __('The assistant is reviewing everything and preparing a recommendation…') }}</span>
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        class="ml-1 inline-flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground px-1.5 py-0.5"
                        @click="cancelPending()"
                    >
                        <flux:icon name="stop-circle" class="size-3" />
                        <span>{{ __('Stop') }}</span>
                    </flux:button>
                </div>
            </div>
        </div>

    </div>

    <div
        x-show="showJumpToLatest"
        x-cloak
        class="flex justify-center px-4 pb-1"
    >
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full bg-zinc-900/90 px-3 py-1 text-[11px] font-medium text-zinc-50 shadow-lg ring-1 ring-black/30 dark:bg-zinc-100 dark:text-zinc-900 dark:ring-white/40"
            @click="scrollToBottom(true)"
        >
            <flux:icon name="chevron-down" class="size-3" />
            <span>{{ __('Jump to latest') }}</span>
        </button>
    </div>

    <div class="border-t border-border/60 px-3 py-2">
        <div class="flex flex-col gap-1.5">
            <div
                x-show="computedFollowups.length > 0 && !isSending && pendingAssistantCount === 0"
                x-cloak
                class="mb-1.5 rounded-md bg-emerald-500/5 px-2.5 py-2 ring-1 ring-emerald-500/40 dark:bg-emerald-500/10"
            >
                <div class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-emerald-800 dark:text-emerald-100">
                    <flux:icon name="sparkles" class="size-3.5 text-emerald-600 dark:text-emerald-300" />
                    <span>{{ __('Follow-up suggestions') }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <template x-for="item in computedFollowups" :key="item.prompt">
                        <flux:button
                            type="button"
                            size="xs"
                            variant="outline"
                            class="text-[11px]! px-2.5 py-1! whitespace-normal text-left cursor-pointer border-emerald-500/60 text-emerald-800 hover:bg-emerald-500/10 dark:text-emerald-100 dark:border-emerald-400/70"
                            x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0"
                            @click="submitPrompt(item.prompt)"
                        >
                            <span class="inline-flex items-center gap-1">
                                <flux:icon name="arrow-up" class="size-3 text-emerald-600 dark:text-emerald-300" />
                                <span x-text="item.prompt"></span>
                            </span>
                        </flux:button>
                    </template>
                </div>
            </div>

            <div
                x-show="errorMessage"
                x-cloak
                class="mb-1 flex items-center gap-1.5 rounded-md bg-red-500/5 px-2 py-1 text-[11px] text-red-600 dark:text-red-400"
            >
                <flux:icon name="exclamation-triangle" class="size-3.5" />
                <p x-text="errorMessage"></p>
            </div>

            <div class="flex items-center gap-2">
                <flux:textarea
                    x-ref="input"
                    x-model="input"
                    rows="2"
                    class="flex-1 resize-none text-xs! max-h-24"
                    style="resize: none;"
                    placeholder="{{ __('Ask about your tasks, events, or projects…') }}"
                    x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0"
                    @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); submit(); }"
                    @input="resizeInput()"
                />

                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    class="shrink-0 inline-flex h-9 w-9 items-center justify-center rounded-full p-0"
                    x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0 || !input.trim()"
                    aria-label="{{ __('Send') }}"
                    @click="submit()"
                >
                    <flux:icon name="arrow-up" class="size-4" />
                </flux:button>
            </div>

            <div class="mt-2 flex items-center justify-start">
                <flux:tooltip :content="__('Start a new conversation')">
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        icon="plus"
                        class="inline-flex h-7 px-2 items-center gap-1 rounded-full text-[11px]"
                        @click="startNewChat()"
                        aria-label="{{ __('New chat') }}"
                    >
                        <span>{{ __('New chat') }}</span>
                    </flux:button>
                </flux:tooltip>
            </div>

        </div>
    </div>
</div>
