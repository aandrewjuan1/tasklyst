<?php

namespace App\Support\LLM;

final class TaskAssistantReasonCodes
{
    public const SCHEDULE_REROUTED_NO_LISTING_CONTEXT = 'schedule_rerouted_no_listing_context';

    public const SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_EXPLICIT_HORIZON = 'schedule_promoted_prioritize_schedule_explicit_horizon';

    public const SCHEDULE_PROMOTED_PRIORITIZE_SCHEDULE_DAY_PLANNING = 'schedule_promoted_prioritize_schedule_day_planning';

    public const SCHEDULE_REFINEMENT_TURN = 'schedule_refinement_turn';

    public const SCHEDULE_REFINEMENT_SKIPPED_FRESH_PLANNING = 'schedule_refinement_skipped_fresh_planning';

    public const INTENT_OFF_TOPIC = 'intent_off_topic';

    public const GENERAL_GUIDANCE_GREETING_ONLY = 'general_guidance_greeting_only';
}
