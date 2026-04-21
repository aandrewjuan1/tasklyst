<?php

namespace App\Support\LLM;

final class TaskAssistantReasonCodes
{
    public const EMPTY_MESSAGE = 'empty_message';

    public const SCHEDULE_REROUTED_NO_LISTING_CONTEXT = 'schedule_rerouted_no_listing_context';

    public const SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_EXPLICIT_HORIZON = 'schedule_promoted_prioritize_schedule_explicit_horizon';

    public const SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_DAY_PLANNING = 'schedule_promoted_prioritize_schedule_day_planning';

    public const SCHEDULE_REFINEMENT_TURN = 'schedule_refinement_turn';

    public const SCHEDULE_REFINEMENT_SKIPPED_FRESH_PLANNING = 'schedule_refinement_skipped_fresh_planning';

    public const INTENT_OFF_TOPIC = 'intent_off_topic';

    public const GREETING_ONLY_DETECTED = 'greeting_only_detected';

    public const GREETING_SHORTCIRCUIT_GENERAL_GUIDANCE = 'greeting_shortcircuit_general_guidance';

    public const GENERAL_GUIDANCE_GREETING_ONLY = 'general_guidance_greeting_only';

    public const GENERAL_GUIDANCE_CLOSING_ONLY = 'general_guidance_closing_only';

    public const GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC = 'general_guidance_greeting_only_deterministic';

    public const CLOSING_THANKS_DETECTED = 'closing_thanks_detected';

    public const CLOSING_GOODBYE_DETECTED = 'closing_goodbye_detected';

    public const CLOSING_SHORT_ACK_DETECTED = 'closing_short_ack_detected';

    public const CLOSING_SUPPRESSED_ACTIONABLE_CUE = 'closing_suppressed_actionable_cue';

    public const CLOSING_BELOW_THRESHOLD = 'closing_below_threshold';

    public const CLOSING_CONTEXT_WEIGHTED = 'closing_context_weighted';

    public const CLOSING_SHORTCIRCUIT_GENERAL_GUIDANCE = 'closing_shortcircuit_general_guidance';
}
