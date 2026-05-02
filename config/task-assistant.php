<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Task Assistant LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Central place for the model used by the Task Assistant module.
    | The Prism provider URL and request timeout remain configured in config/prism.php.
    |
    */

    'provider' => env('TASK_ASSISTANT_PROVIDER', 'ollama'),

    'model' => env('TASK_ASSISTANT_MODEL', 'hermes3:3b'),

    'generation' => [
        'temperature' => (float) env('TASK_ASSISTANT_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('TASK_ASSISTANT_MAX_TOKENS', 1200),
        'top_p' => (float) env('TASK_ASSISTANT_TOP_P', 0.9),
        'schedule' => [
            'temperature' => env('TASK_ASSISTANT_SCHEDULE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_SCHEDULE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_TOP_P'),
        ],
        'prioritize' => [
            'temperature' => env('TASK_ASSISTANT_PRIORITIZE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_PRIORITIZE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_TOP_P'),
        ],
        /*
        | Prioritize listing narrative (structured JSON: framing, reasoning, etc.).
        | Default model hermes3:3b: prompts + schema stress coach/motivator role in every
        | string field; temperature kept moderate for schema adherence. Prefer temperature
        | only—set TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TOP_P only if your stack benefits.
        */
        'prioritize_narrative' => [
            'temperature' => (float) env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TEMPERATURE', 0.38),
            'max_tokens' => (int) env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_MAX_TOKENS', 900),
            'top_p' => env('TASK_ASSISTANT_PRIORITIZE_NARRATIVE_TOP_P'),
        ],
        'schedule_narrative' => [
            'temperature' => (float) env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_TEMPERATURE', 0.38),
            'max_tokens' => (int) env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_MAX_TOKENS', 900),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_TOP_P'),
        ],
        'schedule_narrative_followup' => [
            'temperature' => (float) env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_FOLLOWUP_TEMPERATURE', 0.45),
            'max_tokens' => (int) env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_FOLLOWUP_MAX_TOKENS', 900),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_FOLLOWUP_TOP_P'),
        ],
        'schedule_confirmation' => [
            'temperature' => (float) env('TASK_ASSISTANT_SCHEDULE_CONFIRMATION_TEMPERATURE', 0.28),
            'max_tokens' => (int) env('TASK_ASSISTANT_SCHEDULE_CONFIRMATION_MAX_TOKENS', 420),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_CONFIRMATION_TOP_P', 0.9),
        ],
        'schedule_refinement_ops' => [
            'temperature' => (float) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_OPS_TEMPERATURE', 0.12),
            'max_tokens' => (int) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_OPS_MAX_TOKENS', 400),
            'top_p' => env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_OPS_TOP_P'),
        ],
        'general_guidance' => [
            'temperature' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TEMPERATURE', 0.35),
            'max_tokens' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_MAX_TOKENS', 500),
            'top_p' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TOP_P', 0.9),
        ],
        'general_guidance_target' => [
            'temperature' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_TEMPERATURE', 0.12),
            'max_tokens' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_MAX_TOKENS', 200),
            'top_p' => env('TASK_ASSISTANT_GENERAL_GUIDANCE_TARGET_TOP_P', 0.85),
        ],
        'listing_narrative' => [
            'temperature' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_TEMPERATURE', 0.25),
            'max_tokens' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_MAX_TOKENS', 700),
            'top_p' => env('TASK_ASSISTANT_BROWSE_NARRATIVE_TOP_P', 0.88),
        ],
        'context' => [
            'temperature' => env('TASK_ASSISTANT_CONTEXT_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_CONTEXT_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_CONTEXT_TOP_P'),
        ],
        'intent' => [
            'temperature' => env('TASK_ASSISTANT_INTENT_TEMPERATURE', 0.1),
            'max_tokens' => env('TASK_ASSISTANT_INTENT_MAX_TOKENS', 200),
            'top_p' => env('TASK_ASSISTANT_INTENT_TOP_P', 0.85),
        ],
        'listing_followup' => [
            'temperature' => (float) env('TASK_ASSISTANT_LISTING_FOLLOWUP_TEMPERATURE', 0.35),
            'max_tokens' => (int) env('TASK_ASSISTANT_LISTING_FOLLOWUP_MAX_TOKENS', 450),
            'top_p' => env('TASK_ASSISTANT_LISTING_FOLLOWUP_TOP_P', 0.88),
            // Keep follow-up answers deterministic by default to avoid an extra narrative inference call.
            'use_llm_narrative' => (bool) env('TASK_ASSISTANT_LISTING_FOLLOWUP_USE_LLM_NARRATIVE', false),
        ],
        'listing' => [
            'temperature' => env('TASK_ASSISTANT_BROWSE_TEMPERATURE'),
            'max_tokens' => env('TASK_ASSISTANT_BROWSE_MAX_TOKENS'),
            'top_p' => env('TASK_ASSISTANT_BROWSE_TOP_P'),
        ],
    ],

    'rate_limit' => [
        'submissions_per_minute' => (int) env('TASK_ASSISTANT_SUBMISSIONS_PER_MINUTE', 15),
    ],

    'queue' => env('TASK_ASSISTANT_QUEUE', 'task-assistant'),

    /*
    |--------------------------------------------------------------------------
    | UI Stream Rendering
    |--------------------------------------------------------------------------
    |
    | Controls post-generation chunk streaming behavior. This does not stream
    | LLM tokens directly; it renders final assistant text in paced deltas so
    | chat feels live without adding large delays.
    |
    */
    'streaming' => [
        'chunk_size' => (int) env('TASK_ASSISTANT_STREAM_CHUNK_SIZE', 40),
        'enable_typing_effect' => (bool) env('TASK_ASSISTANT_ENABLE_TYPING_EFFECT', false),
        'inter_chunk_delay_ms' => (int) env('TASK_ASSISTANT_INTER_CHUNK_DELAY_MS', 24),
        'max_typing_effect_ms' => (int) env('TASK_ASSISTANT_MAX_TYPING_EFFECT_MS', 900),
        'health_timeout_seconds' => (int) env('TASK_ASSISTANT_STREAM_HEALTH_TIMEOUT_SECONDS', 20),
        // Fallback polling cadence when realtime broadcast is unavailable.
        'fallback_poll_initial_ms' => (int) env('TASK_ASSISTANT_FALLBACK_POLL_INITIAL_MS', 2000),
        'fallback_poll_mid_ms' => (int) env('TASK_ASSISTANT_FALLBACK_POLL_MID_MS', 3500),
        'fallback_poll_slow_ms' => (int) env('TASK_ASSISTANT_FALLBACK_POLL_SLOW_MS', 5000),
        // Escalation windows for adaptive fallback polling.
        'fallback_poll_mid_after_ms' => (int) env('TASK_ASSISTANT_FALLBACK_POLL_MID_AFTER_MS', 10000),
        'fallback_poll_slow_after_ms' => (int) env('TASK_ASSISTANT_FALLBACK_POLL_SLOW_AFTER_MS', 25000),
        // Health timeout checks should run less frequently than fallback polling.
        'timeout_poll_ms' => (int) env('TASK_ASSISTANT_TIMEOUT_POLL_MS', 10000),
        // Re-check "stop streaming" signal every N chunks (reduces DB pressure during long streams).
        'stop_check_interval_chunks' => (int) env('TASK_ASSISTANT_STREAM_STOP_CHECK_INTERVAL_CHUNKS', 4),
        // Minimum elapsed time between cancellation checks to avoid query bursts on very fast chunk loops.
        'stop_check_min_interval_ms' => (int) env('TASK_ASSISTANT_STREAM_STOP_CHECK_MIN_INTERVAL_MS', 120),
        // Logging full structured envelope can be expensive/noisy in production.
        'log_structured_envelope' => (bool) env('TASK_ASSISTANT_STREAM_LOG_STRUCTURED_ENVELOPE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule horizon (deterministic placement window)
    |--------------------------------------------------------------------------
    |
    | max_horizon_days caps multi-day search ranges. Week boundaries use ISO Monday.
    |
    */
    'schedule' => [
        'max_horizon_days' => (int) env('TASK_ASSISTANT_SCHEDULE_MAX_HORIZON_DAYS', 14),
        // For explicit top-N requests, require confirmation before finalizing
        // an underfilled schedule (e.g. asked top 3, only 1 fit).
        'top_n_shortfall_policy' => env('TASK_ASSISTANT_TOP_N_SHORTFALL_POLICY', 'confirm_if_shortfall'),
        // Overflow handling policy when requested window cannot fit requested count.
        // require_confirm means no automatic spill/expansion before user approval.
        'overflow_strategy' => env('TASK_ASSISTANT_SCHEDULE_OVERFLOW_STRATEGY', 'require_confirm'),
        // Partial placement policy for top-N flows.
        // top1_only allows partial fit only for highest-priority item.
        'partial_policy' => env('TASK_ASSISTANT_SCHEDULE_PARTIAL_POLICY', 'top1_only'),
        /**
         * When {@see ScheduleConfirmationSignalsBuilder} sets triggers, require user confirmation if
         * any trigger is listed here. Legacy digest without confirmation_signals still uses
         * {@see ScheduleFallbackPolicy::legacyShouldRequireConfirmation}.
         */
        'confirmation_triggers' => [
            'empty_placement',
            'unplaced_units',
            'adaptive_relaxed_placement',
            'strict_window_no_fit',
            'requested_window_unsatisfied',
            'hinted_window_unsatisfied',
            'placement_outside_horizon',
            'top_n_shortfall',
        ],
        // Deterministic confirmation copy is faster and stable; enable LLM narration only when explicitly needed.
        'confirmation_use_llm_narrative' => (bool) env('TASK_ASSISTANT_SCHEDULE_CONFIRMATION_USE_LLM_NARRATIVE', false),
        // Deterministic explanations are primary; optional LLM polish can be enabled explicitly.
        'narrative_use_llm_polish' => (bool) env('TASK_ASSISTANT_SCHEDULE_NARRATIVE_USE_LLM_POLISH', false),
        /**
         * When the user gives a vague schedule request (horizon label default_today), search this many
         * consecutive local days starting from today, capped by max_horizon_days.
         */
        'smart_default_spread_days' => (int) env('TASK_ASSISTANT_SCHEDULE_SMART_DEFAULT_SPREAD_DAYS', 7),
        /**
         * Hard-block buffer (minutes) around effective SchoolClass intervals when placing schedule proposals.
         * Set to 0 to disable the extra prep/travel margin.
         */
        'school_class_buffer_minutes' => (int) env('TASK_ASSISTANT_SCHOOL_CLASS_BUFFER_MINUTES', 15),
        /**
         * Learned scheduling signals from historical focus sessions.
         */
        'focus_signals' => [
            // Minimum confidence required before learned signals override preferences/defaults.
            'override_threshold' => (float) env('TASK_ASSISTANT_SCHEDULE_FOCUS_OVERRIDE_THRESHOLD', 0.6),
            // Lookback window for completed sessions used in signal learning.
            'lookback_days' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_LOOKBACK_DAYS', 30),
            'min_work_sessions_energy' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_WORK_SESSIONS_ENERGY', 8),
            'min_work_sessions_duration' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_WORK_SESSIONS_DURATION', 6),
            'min_work_sessions_day_bounds' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_WORK_SESSIONS_DAY_BOUNDS', 10),
            'fallback_work_duration_minutes' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_FALLBACK_WORK_DURATION_MINUTES', 60),
            'min_day_bounds_span_minutes' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_DAY_BOUNDS_SPAN_MINUTES', 8 * 60),
            'min_break_sessions_lunch' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_BREAK_SESSIONS_LUNCH', 5),
            'min_work_gaps' => (int) env('TASK_ASSISTANT_SCHEDULE_FOCUS_MIN_WORK_GAPS', 5),
            // If an active session has no declared duration, keep a small forward guard.
            'active_session_fallback_minutes' => (int) env('TASK_ASSISTANT_SCHEDULE_ACTIVE_SESSION_FALLBACK_MINUTES', 25),
        ],
        /**
         * Local clock-hour bands [start_hour, end_hour) for energy_bias learning and placement scoring.
         * Hour 12 is unbucketed (neutral). See {@see ScheduleEnergyDaypart}.
         */
        'energy_dayparts' => [
            'morning' => [
                'start_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_MORNING_START_HOUR', 8),
                'end_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_MORNING_END_HOUR', 12),
            ],
            'afternoon' => [
                'start_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_AFTERNOON_START_HOUR', 13),
                'end_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_AFTERNOON_END_HOUR', 18),
            ],
            'evening' => [
                'start_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_EVENING_START_HOUR', 18),
                'end_hour' => (int) env('TASK_ASSISTANT_SCHEDULE_ENERGY_EVENING_END_HOUR', 22),
            ],
        ],
        /**
         * Fallback duration for timed events missing an explicit end datetime.
         */
        'event_fallback_duration_minutes' => (int) env('TASK_ASSISTANT_SCHEDULE_EVENT_FALLBACK_DURATION_MINUTES', 60),
        /**
         * Default lunch block applied as busy time during placement.
         * Can be overridden per-user via users.schedule_preferences.lunch_block.
         */
        'lunch_block' => [
            'enabled' => (bool) env('TASK_ASSISTANT_SCHEDULE_LUNCH_BLOCK_ENABLED', true),
            'start' => (string) env('TASK_ASSISTANT_SCHEDULE_LUNCH_BLOCK_START', '12:00'),
            'end' => (string) env('TASK_ASSISTANT_SCHEDULE_LUNCH_BLOCK_END', '13:00'),
        ],
        /**
         * Candidate start-time scoring weights for deterministic window placement.
         */
        'window_scoring' => [
            'weights' => [
                'earlier_start_bonus' => (float) env('TASK_ASSISTANT_SCHEDULE_WEIGHT_EARLIER_START', 1.0),
                'due_soon_multiplier' => (float) env('TASK_ASSISTANT_SCHEDULE_WEIGHT_DUE_SOON', 1.0),
                'complexity_fit_multiplier' => (float) env('TASK_ASSISTANT_SCHEDULE_WEIGHT_COMPLEXITY_FIT', 1.0),
                'class_adjacency_multiplier' => (float) env('TASK_ASSISTANT_SCHEDULE_WEIGHT_CLASS_ADJACENCY', 1.0),
                'energy_bias_multiplier' => (float) env('TASK_ASSISTANT_SCHEDULE_WEIGHT_ENERGY_BIAS', 1.0),
            ],
        ],
        /**
         * When the deterministic planner cannot place real tasks (calendar full, no candidates, etc.).
         * Tone aligns with listing.empty_workspace (@see TaskAssistantStructuredFlowGenerator).
         */
        'empty_placement' => [
            'framing' => 'Nothing in this slice could be placed cleanly in open time—your calendar may be full, or the filters may be tight. That happens; it does not mean you are behind.',
            'reasoning' => 'Getting one concrete item on your list is enough to start—try the thing that is due soonest or on your mind the most, then come back for a ranked order or a fresh schedule.',
            'confirmation' => 'Want to widen the window, prioritize what to tackle first, or tell me a time that usually works better for you?',
        ],
        /**
         * When deterministic multi-clause refinement cannot parse a segment, optionally call a small
         * structured Prism step to infer {@see ScheduleDraftMutationService} operations.
         */
        'refinement' => [
            'llm_fallback_enabled' => (bool) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_LLM_FALLBACK', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prioritization (student-first defaults)
    |--------------------------------------------------------------------------
    */
    'prioritization' => [
        // When true, mixed prioritize requests default to task-led ordering.
        'student_first_global_task_dominance' => (bool) env('TASK_ASSISTANT_STUDENT_FIRST_GLOBAL_TASK_DOMINANCE', true),
        // Events can only outrank tasks if ongoing or starting within this window.
        'event_override_window_minutes' => (int) env('TASK_ASSISTANT_EVENT_OVERRIDE_WINDOW_MINUTES', 45),
        // Tier weights for deterministic student-first ranking.
        'student_focus_tier' => [
            'non_recurring_academic' => (int) env('TASK_ASSISTANT_TIER_NON_RECURRING_ACADEMIC', 400),
            'non_recurring_general' => (int) env('TASK_ASSISTANT_TIER_NON_RECURRING_GENERAL', 300),
            'recurring_academic' => (int) env('TASK_ASSISTANT_TIER_RECURRING_ACADEMIC', 200),
            'recurring_general' => (int) env('TASK_ASSISTANT_TIER_RECURRING_GENERAL', 100),
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Intent routing (LLM + heuristic validation)
    |--------------------------------------------------------------------------
    |
    | This is the only routing pipeline for TaskAssistantService. When use_llm is
    | false, heuristic signals only are used; ambiguous or weak signals fall
    | back to the chat flow.
    |
    | listing_route_context: extra system prompt line for prioritize ranked narrative (rank flow only).
    |
    */
    'listing' => [
        /*
        | Student-visible prioritize narrative order (TaskAssistantMessageFormatter::formatPrioritizeListingMessage):
        | optional acknowledgment → when Doing exists: doing_progress_coach → in-progress titles → framing →
        | bridge → numbered ranked rows → filter_interpretation → reasoning → next_options (last).
        | Without Doing: acknowledgment → framing → ranked rows → …
        */
        /** If Doing-coach bleed sanitizer removes too much, fall back to deterministic coach text. */
        'prioritize_doing_coach_min_chars_after_bleed_strip' => (int) env('TASK_ASSISTANT_PRIORITIZE_DOING_COACH_MIN_AFTER_BLEED', 45),
        'snapshot_task_limit' => (int) env('TASK_ASSISTANT_BROWSE_SNAPSHOT_TASK_LIMIT', 200),
        /**
         * Deterministic student-visible copy when tasks, events, and projects are all absent
         * (@see \App\Services\LLM\TaskAssistant\TaskAssistantService::runEmptyWorkspaceFlow).
         */
        'empty_workspace' => [
            'focus_main_task' => 'Add your first task',
            'framing' => 'You do not have any tasks, events, or projects here yet. Once you add something, I can help you choose what to tackle first or block time for it.',
            'reasoning' => 'Getting one concrete item on your list is enough to start—try the thing that is due soonest or on your mind the most, then come back and ask for a ranked order or a schedule.',
            'next_options' => 'Add something from your workspace, then tell me what to do first or when you want to work on it.',
        ],
        'ambiguous_top_limit' => (int) env('TASK_ASSISTANT_BROWSE_AMBIGUOUS_TOP', 5),
        'max_items' => (int) env('TASK_ASSISTANT_BROWSE_MAX_ITEMS', 50),
        /** Must match Prism prioritize listing narrative output clamp and response validation. */
        'max_reasoning_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_REASONING_CHARS', 1200),
        /** Prioritize `framing` max length; must stay in sync with TaskAssistantPrioritizeOutputDefaults::maxFramingChars() and validation. */
        'max_framing_chars' => (int) env('TASK_ASSISTANT_MAX_FRAMING_CHARS', 900),
        /** Prioritize Doing coach paragraph (LLM motivation when Doing tasks exist; deterministic fallback possible); must match TaskAssistantPrioritizeOutputDefaults::maxDoingProgressCoachChars() and validation. */
        'max_doing_progress_coach_chars' => (int) env('TASK_ASSISTANT_MAX_DOING_PROGRESS_COACH_CHARS', 600),
        /** Single-paragraph recommendations field (suggested_guidance). */
        'max_suggested_guidance_chars' => (int) env('TASK_ASSISTANT_BROWSE_MAX_SUGGESTED_GUIDANCE_CHARS', 1200),
        /** Per-row LLM placement line merged into items[].placement_blurb; must match validator max. */
        'max_item_placement_blurb_chars' => (int) env('TASK_ASSISTANT_MAX_ITEM_PLACEMENT_BLURB_CHARS', 200),
        /**
         * Token Jaccard threshold when comparing a prioritize reasoning sentence to a framing/ack/filter sentence.
         * Lower values drop more echo; used by TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeReasoningVersusPriorFields.
         */
        'prioritize_reasoning_dedupe_sentence_jaccard' => (float) env('TASK_ASSISTANT_PRIORITIZE_REASONING_DEDUPE_SENTENCE_JACCARD', 0.5),
        /**
         * When a reasoning sentence mentions row #1 plus status keywords, drop it if too similar to framing (reduces duplicate overdue/complex beats).
         */
        'prioritize_reasoning_framing_status_overlap_jaccard' => (float) env('TASK_ASSISTANT_PRIORITIZE_REASONING_FRAMING_STATUS_OVERLAP_JACCARD', 0.4),
        /**
         * Regex patterns (one per line) dropped from prioritize_narrative assumptions after LLM output.
         */
        'prioritize_assumption_denylist_patterns' => [
            '/already\s+(looked|viewed|seen|checked)\b.{0,80}\b(list|tasks?)\b/iu',
            '/\b(you\'ve|you have)\s+already\s+(looked|viewed|seen|checked)\b/iu',
            '/\byou\s+already\s+(looked|viewed|seen)\b/iu',
            '/\b(today|tomorrow)\s+is\s+\w+\s+\d{1,2},?\s+\d{4}\b/iu',
        ],
        /**
         * When Doing tasks exist alongside a ranked slice, framing sentences matching these patterns
         * are dropped (ITEMS_JSON titles in any sentence are always dropped). Extend in code if needed.
         */
        'prioritize_framing_when_doing_sentence_drop_patterns' => [
            '/\bfirst\s+on\s+your\s+list\b/iu',
            '/\bstart with completing (?:this|the) (?:important )?task first\b/iu',
            '/\bcomplete (?:this|the) (?:important )?task first\b/iu',
            '/\btackle this task head-?on\b/iu',
            // Ranked rows are To Do; Doing is separate. These falsely imply the top-ranked item is in progress.
            '/\bI see that you\'ve started\b/iu',
            '/\b(you\'ve|you have)\s+started\s+(working\s+on|work\s+on)\b/iu',
            '/\byou\'re already\s+working\s+on\b/iu',
        ],
        /**
         * If framing is shorter than this after stripping, replace with Doing-first intro fallback.
         */
        'prioritize_framing_when_doing_min_chars_after_strip' => (int) env('TASK_ASSISTANT_PRIORITIZE_FRAMING_DOING_MIN_CHARS', 40),
        /**
         * When Doing + ranked rows exist, framing is shown before the numbered ranked list. Drop sentences that
         * use "this/these" as if the top-ranked task were already visible—avoids vague deixis (model-agnostic).
         */
        'prioritize_framing_premature_deictic_sentence_patterns' => [
            '/\bI think starting with this\b/iu',
            '/\b(starting|start|focusing|focus)\s+with\s+this\b/iu',
            '/\bwith this will help\b/iu',
            '/\bfocusing on this\b/iu',
            '/\bthis will help you get\b/iu',
        ],
        /**
         * Drop framing sentences that mostly repeat acknowledgment themes (stress/quiz/prep) when similarity is high.
         */
        'prioritize_framing_ack_dedupe_sentence_jaccard' => (float) env('TASK_ASSISTANT_PRIORITIZE_FRAMING_ACK_DEDUPE_JACCARD', 0.42),
        /**
         * Extra regexes for reasoning: phrase must appear in ranked titles blob or the sentence is dropped.
         *
         * @see TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithInventedStudyArtifacts
         */
        'prioritize_reasoning_invented_artifact_patterns' => [
            '/\bprogramming\s+exercise\b/iu',
            '/\bcoding\s+exercise\b/iu',
            '/\bsoftware\s+(development\s+)?project\b/iu',
        ],
        /*
        | School vs chores browse domains use subjects, teachers, and tags — not a raw
        | substring match on the word "school" in titles (avoids false positives like
        | "school bag"). Title patterns below exclude common errands from school lists.
        */
        'school_academic_tag_keywords' => [
            'school', 'academic', 'education', 'course', 'class', 'lecture', 'homework',
            'assignment', 'exam', 'quiz', 'study', 'math', 'science', 'english', 'history',
        ],
        'chore_indicator_tags' => [
            'household', 'health', 'chores', 'cleaning', 'laundry', 'errands',
        ],
        'school_exclusion_title_patterns' => [
            '/\bschool\s+bag\b/i',
            '/\b(groceries|grocery)\b/i',
            '/\b(laundry|dishes|vacuum|trash)\b/i',
            '/\b(pack|packing)\b.*\b(bag|lunch)\b/i',
        ],
    ],

    'listing_route_context' => <<<'TXT'
Listing mode (read-only listing): Task order and membership are fixed. Speak as the assistant: "you/your" or neutral. Never say snapshot, snapshot data, JSON, backend, or database in student-visible text.

Reasoning: briefly why this list matches their request—only use titles and dates from the task list.

Suggested guidance: one paragraph starting with "I suggest" or "I recommend"; warm tips (time management, avoiding overwhelm). No bullets. No invented durations.

Do not suggest calendar blocking unless they asked about scheduling.
TXT,

    'intent' => [
        'use_llm' => env('TASK_ASSISTANT_INTENT_USE_LLM', true),
        'inference' => [
            // Skip intent LLM classification when regex/signal scores are already decisive.
            'skip_when_signal_confident' => (bool) env('TASK_ASSISTANT_INTENT_SKIP_CONFIDENT_SIGNAL_INFERENCE', true),
            'signal_confident_min_score' => (float) env('TASK_ASSISTANT_INTENT_CONFIDENT_SIGNAL_MIN_SCORE', 0.78),
            'signal_confident_min_margin' => (float) env('TASK_ASSISTANT_INTENT_CONFIDENT_SIGNAL_MIN_MARGIN', 0.20),
        ],
        /**
         * Default prioritize slice size when the message asks for several ranked items (e.g. “top tasks … first”)
         * but does not name a number. Must be 2–10; used by IntentRoutingPolicy::extractCountLimit.
         */
        'prioritize_default_multi_count' => max(2, min((int) env('TASK_ASSISTANT_PRIORITIZE_DEFAULT_MULTI_COUNT', 3), 10)),
        'off_topic_min_confidence' => (float) env('TASK_ASSISTANT_OFF_TOPIC_MIN_CONFIDENCE', 0.65),
        'merge' => [
            'llm_weight' => (float) env('TASK_ASSISTANT_INTENT_LLM_WEIGHT', 0.5),
            'signal_weight' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_WEIGHT', 0.5),
            'validator_override_signal_min' => (float) env('TASK_ASSISTANT_INTENT_OVERRIDE_SIGNAL_MIN', 0.72),
            'llm_weak_below' => (float) env('TASK_ASSISTANT_INTENT_LLM_WEAK_BELOW', 0.55),
            'weak_composite_max' => (float) env('TASK_ASSISTANT_INTENT_WEAK_COMPOSITE_MAX', 0.38),
            'clarify_margin' => (float) env('TASK_ASSISTANT_INTENT_CLARIFY_MARGIN', 0.12),
            'clarify_composite_ceiling' => (float) env('TASK_ASSISTANT_INTENT_CLARIFY_COMPOSITE_CEILING', 0.55),
            'signal_only_weak_max' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_WEAK_MAX', 0.35),
            'signal_only_clarify_margin' => (float) env('TASK_ASSISTANT_INTENT_SIGNAL_CLARIFY_MARGIN', 0.15),
            // When the confidence gap between the top two candidate composites
            // is small, prefer general guidance to avoid wrong hard routing.
            'ambiguity_gap_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_GAP_MIN', 0.18),
            // Require the second-best composite to be meaningfully non-zero.
            'ambiguity_second_composite_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_SECOND_COMPOSITE_MIN', 0.12),
            // Keep general-guidance override limited to medium confidence.
            'ambiguity_top_composite_max' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_TOP_COMPOSITE_MAX', 0.75),
            /**
             * Minimum min(prioritization, scheduling) before non-pattern hybrid signal blends apply
             * (@see TaskAssistantIntentHybridCue::scoreHybridSignal).
             */
            'hybrid_signal_floor' => (float) env('TASK_ASSISTANT_INTENT_HYBRID_SIGNAL_FLOOR', 0.18),
            /**
             * When the top two merged composites are prioritize vs schedule with a small margin,
             * route to prioritize_schedule if the hybrid composite meets this floor.
             */
            'hybrid_ambiguity_resolution_min' => (float) env('TASK_ASSISTANT_INTENT_HYBRID_AMBIGUITY_RESOLUTION_MIN', 0.42),
            /**
             * When both prioritize and scheduling signals are each meaningfully strong, this allows
             * a hybrid promotion to prioritize_schedule even when a single-flow composite narrowly wins.
             */
            'hybrid_dual_signal_min' => (float) env('TASK_ASSISTANT_INTENT_HYBRID_DUAL_SIGNAL_MIN', 0.5),
            /**
             * When the LLM says prioritize_schedule but scheduling heuristics are weaker than this,
             * demote to prioritize unless the message matches a combined rank+time pattern.
             */
            'prioritize_schedule_min_schedule_signal' => (float) env('TASK_ASSISTANT_PRIORITIZE_SCHEDULE_MIN_SCHEDULE_SIGNAL', 0.35),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic closing detection
    |--------------------------------------------------------------------------
    |
    | Matches thanks/goodbye/short acknowledgements so we can reply warmly and
    | avoid accidentally triggering planning flows.
    |
    */
    'closing' => [
        'default_min_confidence' => (float) env('TASK_ASSISTANT_CLOSING_DEFAULT_MIN_CONFIDENCE', 0.88),
        'context_weighted_min_confidence' => (float) env('TASK_ASSISTANT_CLOSING_CONTEXT_MIN_CONFIDENCE', 0.70),
        'thanks_patterns' => [
            '/^(ok(?:ay)?\s+)?(thanks|thank\s*you|thankyou|thx|ty|salamat)(\s+so\s+much)?[!. ]*$/iu',
            '/^(ok(?:ay)?\s+)?(thanks|thank\s*u|thankyou|thx|ty|tysm|salamat)(\s+(for\s+your\s+help|so\s+much|a\s+lot))?[!. ]*$/iu',
            '/^(got\s*it|gotcha|copy|noted)[,\s]+(thanks|thank\s*you|thank\s*u|thx|ty)[!. ]*$/iu',
            '/^(ok(?:ay)?\s+)?(thanks|thank\s*you|thank\s*u|thx|ty|tysm|salamat)\b.*$/iu',
            '/\b(maraming\s+salamat)\b/iu',
            '/^(ok(?:ay)?|okie|okii|oki)?[,\s]*(bro|bruh|boss|man)?[,\s]*(thanks|thank\s*you|thankyou|thx|ty|tysm|salamat)(\s+(bro|bruh|boss|man))?[!. ]*$/iu',
            '/^(i\s+said\s+)?(thanks|thank\s*you|thankyou|thx|ty|tysm|salamat)\b.*$/iu',
            '/^(appreciate\s+it|much\s+appreciated|thanks\s+a\s+ton|thank\s+you\s+very\s+much)[!. ]*$/iu',
        ],
        'goodbye_patterns' => [
            '/^(ok(?:ay)?\s+)?(bye|goodbye|see\s*ya|see\s+you|cya|later|good\s*night|take\s*care)[!. ]*$/iu',
            '/^(ok(?:ay)?\s+)?(bye\s*bye|see\s+you\s+later|see\s*ya\s+later|catch\s+you\s+later)[!. ]*$/iu',
            '/^(ok(?:ay)?\s+)?(thanks|thank\s*you|thank\s*u|thx|ty)\s+(bye|goodbye|see\s*ya|see\s+you|later)[!. ]*$/iu',
            '/^(ok(?:ay)?|okie|okii|oki)?[,\s]*(bro|bruh|boss|man)?[,\s]*(good[\s-]?bye|bye|bye\s*bye|see\s*(ya|you)(\s+later)?|cya|later|take\s*care|peace\s*out|have\s+a\s+good\s+one|have\s+a\s+nice\s+day)([,\s]+(bro|bruh|boss|man))?[!. ]*$/iu',
        ],
        'short_ack_patterns' => [
            '/^(ok|okay|got\s*it|noted|nice|alright|all\s*right|copy|understood|sige|ige)[!. ]*$/iu',
            '/^(kk|k|aight|gotcha|cool|sounds\s+good|all\s+good)[!. ]*$/iu',
            '/^(yes|yup|yep|roger|noted\s+bro|all\s+set|that\s+works|works\s+for\s+me)[!. ]*$/iu',
        ],
        'actionable_guard_patterns' => [
            '/\b(schedule|reschedule|priorit(?:ize|y)|move|shift|swap|plan|block|set|remind|tomorrow|today|tonight|later|morning|afternoon|evening|next\s+week|at\s+\d{1,2}(:\d{2})?\s*(am|pm)?)\b/iu',
        ],
        'response' => [
            'acknowledgement' => 'You are welcome.',
            'goodbye_acknowledgement' => 'Take care, and great job today.',
            'message' => 'Nice work staying consistent today. You are building momentum one step at a time.',
            'message_after_planning' => 'You have a clear plan now. Keep going one block at a time and you will make steady progress.',
            'next_options' => 'If you want, I can help again anytime to prioritize your next tasks or block time for them.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic greeting-only detection
    |--------------------------------------------------------------------------
    */
    'greeting' => [
        'allow_name_mentions' => (bool) env('TASK_ASSISTANT_GREETING_ALLOW_NAME_MENTIONS', true),
        'patterns' => [
            '/^(hi|hii|hello|helloo|hey|yow|yo|hiya|kumusta|kamusta|good\s+morning|good\s+afternoon|good\s+evening)(\s+(bro|bruh|boss|tasklyst|andrew))?[!?. ]*$/iu',
            '/^(hi|hii|hello|helloo|hey|yo|hiya)(\s+(there|bro|bruh|boss|man|tasklyst|andrew|yo))?[!?. ]*$/iu',
        ],
        'allowed_name_patterns' => [
            '/\b(tasklyst|andrew)\b/iu',
        ],
        'actionable_guard_patterns' => [
            '/\b(help|priorit(?:ize|y)|schedule|reschedule|what\s+should\s+i\s+do|plan|task|tasks|move|shift|later|today|tomorrow)\b/iu',
        ],
        'response' => [
            'acknowledgement' => "Hi, I'm TaskLyst—your task assistant.",
            'message' => 'Great to have you here. Small focused steps today can build strong momentum.',
            'next_options' => 'If you want, we can rank what to do first, schedule your tasks, or do both in one pass.',
            'next_options_chip_texts' => [
                'What should I do first',
                'Schedule my tasks',
                'Prioritize then schedule my tasks',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | General guidance (structured flow)
    |--------------------------------------------------------------------------
    |
    | Default closing offer when the model omits next_options; chip labels are
    | always attached in TaskAssistantGeneralGuidanceService (not model-generated).
    |
    */
    'general_guidance' => [
        'default_next_options' => 'If you want, I can help you decide what to tackle first, or block time on your calendar for what matters most.',
        'next_options_chip_texts' => [
            'What should I do first',
            'Schedule my most important task',
        ],
        // Secondary mode-classifier inference can add latency; keep heuristics-only mode selection by default.
        'enable_mode_classifier' => (bool) env('TASK_ASSISTANT_GENERAL_GUIDANCE_ENABLE_MODE_CLASSIFIER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule refinement (multiturn draft edits)
    |--------------------------------------------------------------------------
    */
    'schedule_refinement' => [
        'use_llm' => (bool) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_USE_LLM', true),
        'parser' => env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_PARSER', 'rules_only'),
        'ambiguity_policy' => env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_AMBIGUITY_POLICY', 'clarify'),
        'relative_dates' => (bool) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_RELATIVE_DATES', true),
        'strict_target_required' => (bool) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_STRICT_TARGET_REQUIRED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Controls how many times structured LLM generation is retried after
    | schema/quality validation failures.
    |
    */
    'retry' => [
        'max_retries' => (int) env('TASK_ASSISTANT_RETRY_MAX_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance guardrails
    |--------------------------------------------------------------------------
    |
    | Soft latency budget for a single assistant orchestration run (milliseconds).
    | Optional LLM steps may fall back to deterministic paths once exceeded.
    | Set 0 to disable.
    |
    */
    'performance' => [
        'latency_budget_ms' => (int) env('TASK_ASSISTANT_LATENCY_BUDGET_MS', 8000),
    ],
];
