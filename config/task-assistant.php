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
        'enable_typing_effect' => (bool) env('TASK_ASSISTANT_ENABLE_TYPING_EFFECT', true),
        'inter_chunk_delay_ms' => (int) env('TASK_ASSISTANT_INTER_CHUNK_DELAY_MS', 24),
        'max_typing_effect_ms' => (int) env('TASK_ASSISTANT_MAX_TYPING_EFFECT_MS', 900),
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
        /**
         * When the deterministic planner cannot place real tasks (calendar full, no candidates, etc.).
         * Tone aligns with listing.empty_workspace (@see TaskAssistantStructuredFlowGenerator).
         */
        'empty_placement' => [
            'framing' => 'Nothing in this slice could be placed cleanly in open time—your calendar may be full, or the filters may be tight. That happens; it does not mean you are behind.',
            'reasoning' => 'Getting one concrete item on your list is enough to start—try the thing that is due soonest or on your mind the most, then come back for a ranked order or a fresh schedule.',
            'confirmation' => 'Want to widen the window, prioritize what to tackle first, or tell me a time that usually works better for you?',
        ],
    ],

    'tools' => [
        'routes' => [
            'listing' => [],
            'schedule' => ['list_tasks'],
            'prioritize' => [],
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
            'ambiguity_gap_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_GAP_MIN', 0.15),
            // Require the second-best composite to be meaningfully non-zero.
            'ambiguity_second_composite_min' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_SECOND_COMPOSITE_MIN', 0.12),
            // Keep general-guidance override limited to medium confidence.
            'ambiguity_top_composite_max' => (float) env('TASK_ASSISTANT_INTENT_AMBIGUITY_TOP_COMPOSITE_MAX', 0.65),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule refinement (multiturn draft edits)
    |--------------------------------------------------------------------------
    */
    'schedule_refinement' => [
        'use_llm' => (bool) env('TASK_ASSISTANT_SCHEDULE_REFINEMENT_USE_LLM', true),
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
        'max_retries' => (int) env('TASK_ASSISTANT_RETRY_MAX_RETRIES', 2),
    ],
];
