<?php

namespace App\Support\LLM;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class TaskAssistantSchemas
{
    /**
     * Full schedule plan schema (proposals, blocks, narrative fields).
     *
     * Covers single-day and multi-day placement horizons; naming is not limited to “today”.
     */
    public static function schedulePlanSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_plan',
            description: 'Ordered list of time blocks and proposals across the planning horizon.',
            properties: [
                new ArraySchema(
                    name: 'proposals',
                    description: 'Per-item schedule proposals that can be accepted or declined individually.',
                    items: new ObjectSchema(
                        name: 'proposal',
                        description: 'A schedulable proposal for one entity.',
                        properties: [
                            new StringSchema(
                                name: 'proposal_id',
                                description: 'Unique proposal identifier.'
                            ),
                            new StringSchema(
                                name: 'status',
                                description: 'Decision state: pending, accepted, declined, or failed.'
                            ),
                            new StringSchema(
                                name: 'entity_type',
                                description: 'Entity type: task, event, or project.'
                            ),
                            new NumberSchema(
                                name: 'entity_id',
                                description: 'Entity ID reference.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Display title for the proposal.'
                            ),
                            new NumberSchema(
                                name: 'reason_score',
                                description: 'Deterministic score used by planner.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'start_datetime',
                                description: 'Proposed ISO start datetime.'
                            ),
                            new StringSchema(
                                name: 'end_datetime',
                                description: 'Proposed ISO end datetime when applicable.',
                                nullable: true
                            ),
                            new NumberSchema(
                                name: 'duration_minutes',
                                description: 'Proposed duration in minutes when applicable.',
                                nullable: true
                            ),
                            new ArraySchema(
                                name: 'conflict_notes',
                                description: 'Conflict or planner notes for this proposal.',
                                items: new StringSchema(name: 'note', description: 'Conflict note.'),
                                nullable: true
                            ),
                            new ObjectSchema(
                                name: 'apply_payload',
                                description: 'Tool payload used when user accepts this item.',
                                nullable: true,
                                properties: [
                                    new StringSchema(
                                        name: 'tool',
                                        description: 'Tool name to apply.'
                                    ),
                                    new ObjectSchema(
                                        name: 'arguments',
                                        description: 'Tool arguments.',
                                        properties: [],
                                        requiredFields: []
                                    ),
                                ],
                                requiredFields: []
                            ),
                        ],
                        requiredFields: [
                            'proposal_id',
                            'status',
                            'entity_type',
                            'title',
                            'start_datetime',
                        ]
                    ),
                    nullable: true
                ),
                new ArraySchema(
                    name: 'blocks',
                    description: 'Ordered time blocks.',
                    items: new ObjectSchema(
                        name: 'block',
                        description: 'Single block with start/end and small metadata.',
                        properties: [
                            new StringSchema(
                                name: 'start_time',
                                description: 'Start time in HH:MM or ISO format.'
                            ),
                            new StringSchema(
                                name: 'end_time',
                                description: 'End time in HH:MM or ISO format.'
                            ),
                            new StringSchema(
                                name: 'label',
                                description: 'Optional label for the block.',
                                nullable: true
                            ),
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Optional task id reference.',
                                nullable: true
                            ),
                            new NumberSchema(
                                name: 'event_id',
                                description: 'Optional event id reference.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'note',
                                description: 'Optional note describing the block.',
                                nullable: true
                            ),
                        ],
                        requiredFields: [
                            'start_time',
                            'end_time',
                        ]
                    )
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'Optional overview of the proposed schedule window.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'assistant_note',
                    description: 'Optional friendly line from the assistant.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'reasoning',
                    description: 'Short explanation of why this schedule is arranged this way.',
                    nullable: true
                ),
                new ArraySchema(
                    name: 'strategy_points',
                    description: 'Key strategic choices used in this schedule.',
                    items: new StringSchema(name: 'point', description: 'One strategy point.'),
                    nullable: true
                ),
                new ArraySchema(
                    name: 'suggested_next_steps',
                    description: 'Practical next actions for the user after reviewing the schedule.',
                    items: new StringSchema(name: 'step', description: 'One practical next step.'),
                    nullable: true
                ),
                new ArraySchema(
                    name: 'assumptions',
                    description: 'Assumptions made while planning (e.g., availability, estimated durations).',
                    items: new StringSchema(name: 'assumption', description: 'Assumption item.'),
                    nullable: true
                ),
            ],
            requiredFields: [
                'blocks',
            ]
        );
    }

    /**
     * Narrative fields for prioritize output: assistant voice for a prioritized list.
     */
    public static function prioritizeNarrativeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'prioritize_narrative',
            description: 'Role: student task coach and motivator—warm, concise, practical (not a dry narrator). Student-visible order is OUTPUT_FIELD_ORDER in the user message: intro (framing) → Doing coach when required → app-rendered ranked list → filter_interpretation → reasoning (coach/why) → next_options last. Hermes/small models: keep strings short; one main idea per field; follow field order; spread empathy and tips across fields. Never mention snapshot, JSON, ITEMS_JSON, FILTER_CONTEXT, backend, or database.',
            properties: [
                new StringSchema(
                    name: 'filter_interpretation',
                    description: 'Optional: one short sentence on how filters or request wording shaped this slice. The student sees this AFTER the numbered list; explain the slice briefly, not as a second intro. Null if not needed.',
                    nullable: true
                ),
                new ArraySchema(
                    name: 'assumptions',
                    description: 'Optional: prefer null. Only if strictly needed to interpret a filter (e.g. calendar "today"). Never meta-assumptions about the user viewing their list; no invented dates. Up to 4 short strings. Empty or null if none.',
                    items: new StringSchema(name: 'assumption', description: 'One assumption.'),
                    nullable: true
                ),
                new StringSchema(
                    name: 'acknowledgment',
                    description: 'Optional but recommended when user expresses intent/emotion conversationally (e.g. overwhelmed, excited, frustrated). When present, it should be 1 short sentence acknowledging their request in student-friendly language. When absent, it may be null. When LISTED_ITEM_COUNT is 1, keep singular grammar consistent with that single row.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'doing_progress_coach',
                    description: 'When DOING_COACH_REQUIRED is true: one short warm paragraph (generic motivation only—no quoted titles). Must NOT name any title from ITEMS_JSON; ranked rows are not Doing tasks. When DOING_COACH_REQUIRED is false: must be null.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'framing',
                    description: 'Required: short intro only—natural coach voice (I recommend, I suggest, Let\'s—vary phrasing). Usually 1–3 sentences. When DOING_COACH_REQUIRED is true and LISTED_ITEM_COUNT >= 1, do NOT name or quote any ITEMS_JSON title in framing—orient to in-progress work; top-row explanation belongs in reasoning (after filter, before next_options). Because framing appears before the app renders the numbered ITEMS_JSON list, avoid vague deixis like "starting with this/these" that implies the top-ranked row is already visible—server-side cleanup may strip it. Do not restate the same stress/quiz-prep beat as acknowledgment; keep framing a short handoff. Illegal text may be sanitized server-side. When DOING_COACH_REQUIRED is false and LISTED_ITEM_COUNT >= 1, keep framing light—save "why row #1 is first" for reasoning. One practical tip allowed here OR reserve the main micro-step for reasoning—do not repeat the same overdue/complex/status points later. Never say the student "found" or "discovered" tasks. Use "your" attention/focus, not "our". When LISTED_ITEM_COUNT is 1, singular grammar for that row where you mention it. Do not invent due dates or reorder items. Avoid brochure openers and "I reviewed your list" claims.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'next_options',
                    description: 'Required: 1-2 sentences offering a follow-up (e.g., scheduling). The student sees this LAST, after reasoning. Scheduling/follow-up only—do not summarize the full ranked list here. When LISTED_ITEM_COUNT is 1, refer to scheduling "this task" (or event/project per row), not "these tasks". If you mention rescheduling, it must be about remaining work, not tasks already completed.',
                    nullable: false
                ),
                new ArraySchema(
                    name: 'next_options_chip_texts',
                    description: 'Required: array of 1-3 short chip strings that a student can click to trigger the follow-up (e.g., scheduling windows). No question marks. No bullets.',
                    items: new StringSchema(name: 'next_option_chip_text', description: 'One chip text.'),
                    nullable: false
                ),
                new StringSchema(
                    name: 'reasoning',
                    description: 'Required: appears after filter_interpretation and before next_options—main coaching paragraph: motivation, empathy, why row #1 is first using ITEMS_JSON when LISTED_ITEM_COUNT >= 1, and one concrete micro-step or habit when helpful. Mention the first row\'s exact title at least once. Describe the work using words grounded in that row\'s title and fields (priority, due_phrase, complexity_label)—do not invent assignment types (e.g. "programming exercise", "lab hand-in") not supported by the title. When LISTED_ITEM_COUNT is 1 and DOING_COACH_REQUIRED is true, do not drag in other task titles from in-progress work; stay on row #1. Do not repeat the same overdue/complex/status points as framing or filter_interpretation; do not repeat scheduling lines that belong in next_options. When LISTED_ITEM_COUNT > 1, add one short phrase for row 2\'s role vs row 1 using only entity_type, titles, and due fields—no invented times. Direct address (I/You/Let\'s); no "the user". Singular grammar when LISTED_ITEM_COUNT is 1. Avoid rhetorical "Today," unless due_phrase supports calendar-today wording. No "ordered list" boilerplate.',
                    nullable: false
                ),
            ],
            requiredFields: [
                'framing',
                'next_options',
                'next_options_chip_texts',
                'reasoning',
            ]
        );
    }

    /**
     * General guidance for vague/help-seeking/overwhelmed prompts.
     *
     * The LLM should generate mode-aware, non-overlapping user-facing fields.
     */
    public static function generalGuidanceSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'general_guidance',
            description: 'General help for greetings/help prompts, gibberish/unclear input, and out-of-scope boundaries. Do not mention snapshot, JSON, backend, database, or other internal terms. Output only these intent-driven fields.',
            properties: [
                new StringSchema(
                    name: 'intent',
                    description: 'One of: task, out_of_scope, unclear.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'acknowledgement',
                    description: 'One short empathetic acknowledgement sentence in clear user-facing language. No refusal/boundary language here.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'message',
                    description: 'Main message body (1-3 short sentences). For out_of_scope intent, include a single gentle refusal/boundary here (and only here), then redirect to task help. For unclear intent, ask for a rephrase without sounding robotic. For task intent, give a small actionable next step.',
                    nullable: false
                ),
                new ArraySchema(
                    name: 'suggested_next_actions',
                    description: '2-3 short clausal (verb-led) actionable follow-ups. Must include explicit prioritize/schedule options. These support routing quality; the student-visible closing line is next_options.',
                    items: new StringSchema(name: 'action', description: 'One suggested next action.'),
                    nullable: false
                ),
                new StringSchema(
                    name: 'next_options',
                    description: 'Final paragraph (one or two sentences): warm offer starting with If you want or If you would like—offer to help rank what to do first and/or schedule time for the most important work. No bullets. No chips text here.',
                    nullable: false
                ),
            ],
            requiredFields: [
                'intent',
                'acknowledgement',
                'message',
                'suggested_next_actions',
                'next_options',
            ]
        );
    }

    public static function generalGuidanceModeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'general_guidance_mode',
            description: 'Classify the guidance mode for assistant response behavior.',
            properties: [
                new StringSchema(
                    name: 'guidance_mode',
                    description: 'One of: friendly_general, gibberish_unclear, off_topic.'
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence between 0 and 1.'
                ),
                new StringSchema(
                    name: 'rationale',
                    description: 'Short reason for the mode selection.',
                    nullable: true
                ),
            ],
            requiredFields: ['guidance_mode', 'confidence']
        );
    }

    /**
     * Target selection after the user answers the guidance question.
     */
    public static function generalGuidanceTargetSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'general_guidance_target',
            description: 'Choose whether the user answer indicates prioritize or schedule. If unclear, return either/unknown with low confidence.',
            properties: [
                new StringSchema(
                    name: 'target',
                    description: 'One of: prioritize, schedule, or either.',
                    nullable: false
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence between 0 and 1.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'rationale',
                    description: 'Short reason (optional).',
                    nullable: true
                ),
            ],
            requiredFields: [
                'target',
                'confidence',
            ]
        );
    }

    /**
     * Structured LLM output for schedule coaching. Proposals, ISO start/end times, and block times
     * are computed in PHP; the model must not invent or revise clock times. Aligns with prioritize
     * narrative roles: framing, filter_interpretation, reasoning, next_options, chips.
     */
    public static function scheduleNarrativeRefinementSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_narrative_aligned',
            description: 'Coach narrative for a deterministic schedule plan. Do not mention task_id/event_id/project_id, JSON, snapshot, or backend. Do not state exact clock times or dates—the app shows blocks. Optional display_block_order is a permutation of block indices for narrative sentence order only.',
            properties: [
                new StringSchema(
                    name: 'acknowledgment',
                    description: 'Optional one short sentence if the user sounded stressed or conversational.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'framing',
                    description: 'Required short intro in warm coach voice before the app-rendered time blocks. Do not quote internal titles as if the user already sees the list.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'filter_interpretation',
                    description: 'Optional one sentence after blocks: how filters or wording shaped this slice.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'reasoning',
                    description: 'Required coaching paragraph after filter_interpretation: why this sequencing fits—without inventing times.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'next_options',
                    description: 'Required closing offer: scheduling follow-ups or reprioritizing—seen last. Singular/plural must match how many items were scheduled when obvious.',
                    nullable: false
                ),
                new ArraySchema(
                    name: 'next_options_chip_texts',
                    description: 'Required 2–3 short chip labels for follow-ups (no question marks).',
                    items: new StringSchema(name: 'chip', description: 'Chip label.'),
                    nullable: false,
                    minItems: 2,
                    maxItems: 3
                ),
                new ArraySchema(
                    name: 'strategy_points',
                    description: 'Optional 2–4 practical bullets supporting the plan (no exact times).',
                    items: new StringSchema(name: 'point', description: 'Strategy point.'),
                    nullable: true,
                    maxItems: 6
                ),
                new ArraySchema(
                    name: 'suggested_next_steps',
                    description: 'Optional 2–4 execution steps after reviewing proposals (no exact times).',
                    items: new StringSchema(name: 'step', description: 'Execution step.'),
                    nullable: true,
                    maxItems: 8
                ),
                new ArraySchema(
                    name: 'assumptions',
                    description: 'Optional short assumptions.',
                    items: new StringSchema(name: 'assumption', description: 'Assumption.'),
                    nullable: true,
                    maxItems: 6
                ),
                new StringSchema(
                    name: 'assistant_note',
                    description: 'Optional friendly one-liner.',
                    nullable: true
                ),
                new ArraySchema(
                    name: 'display_block_order',
                    description: 'Optional permutation of 0-based block indices for narrative order only.',
                    items: new NumberSchema(name: 'block_index', description: 'Index into the fixed blocks array.'),
                    nullable: true,
                    minItems: 1,
                    maxItems: 48
                ),
            ],
            requiredFields: [
                'framing',
                'reasoning',
                'next_options',
                'next_options_chip_texts',
            ]
        );
    }
}
