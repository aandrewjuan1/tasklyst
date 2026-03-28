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
            description: 'Assistant voice for a prioritized slice. Follow LISTED_ITEM_COUNT in the user message: if it is 1, use strictly singular grammar (this task/event/project, it)—never pluralize to tasks/priorities/they when only one row is listed. If LISTED_ITEM_COUNT > 1, plural forms are fine for the set. Never mention snapshot, JSON, ITEMS_JSON, FILTER_CONTEXT, backend, or database.',
            properties: [
                new StringSchema(
                    name: 'acknowledgment',
                    description: 'Optional but recommended when user expresses intent/emotion conversationally (e.g. overwhelmed, excited, frustrated). When present, it should be 1 short sentence acknowledging their request in student-friendly language. When absent, it may be null. When LISTED_ITEM_COUNT is 1, keep singular grammar consistent with that single row.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'framing',
                    description: 'Required: natural assistant voice (I recommend, I suggest, Let\'s, We could, here\'s what I\'d do)—vary phrasing. You may use multiple sentences when it helps. When LISTED_ITEM_COUNT is 1, never say priorities/tasks/these in the plural for that one row—use the row entity type (task, event, or project) in singular. Do not invent due dates or reorder items. Avoid brochure-style openers like "Here is your top priority in a simple order". Avoid claiming you reviewed/checked their full list (no “I reviewed your tasks”, “I took a look at your to-do list”).',
                    nullable: false
                ),
                new StringSchema(
                    name: 'next_options',
                    description: 'Required: 1-2 sentences offering a follow-up option (e.g., scheduling). When LISTED_ITEM_COUNT is 1, refer to scheduling "this task" (or event/project per row), not "these tasks". Keep it student-friendly. If you mention rescheduling, it must be about remaining work, not tasks already completed.',
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
                    description: 'Required: explanation written directly to the student (I, You, Let\'s, or We are all fine). Do not use third-person phrasing like "the user ...", "they match ...", or "this list matches ...". Ground claims in the listed rows (titles, due_phrase, priority). Mention the exact title of the first row at least once and tie why it is first to that row\'s fields. When LISTED_ITEM_COUNT is 1, use singular nouns and it/this row only—no "they/them" for that single item. You may use multiple sentences. Do not include internal terms, stiff meta lines about "ordered list"/"first on this list", or template closers like "when you\'re ready". If you include counts, it must match LISTED_ITEM_COUNT.',
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
                    description: '2-3 short clausal (verb-led) actionable follow-ups. Must include explicit prioritize/schedule options.',
                    items: new StringSchema(name: 'action', description: 'One suggested next action.'),
                    nullable: false
                ),
            ],
            requiredFields: [
                'intent',
                'acknowledgement',
                'message',
                'suggested_next_actions',
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
     * Narrative refinement schema for scheduling: blocks and proposals are fixed in PHP;
     * the model may add strategy points, next steps, and assumptions (summary lines are anchored in code).
     */
    public static function scheduleNarrativeRefinementSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_narrative_refinement',
            description: 'Refine narrative fields for a previously proposed schedule (single- or multi-day).',
            properties: [
                new StringSchema(
                    name: 'summary',
                    description: 'Short narrative summary of the schedule.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'assistant_note',
                    description: 'Optional friendly note from the assistant.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'reasoning',
                    description: 'Why this schedule structure fits the user for the requested window.',
                    nullable: true
                ),
                new ArraySchema(
                    name: 'strategy_points',
                    description: '2-4 concise points describing prioritization and sequencing strategy.',
                    items: new StringSchema(name: 'point', description: 'Strategy point.'),
                    nullable: true
                ),
                new ArraySchema(
                    name: 'suggested_next_steps',
                    description: '2-4 practical steps for the user to execute this schedule.',
                    items: new StringSchema(name: 'step', description: 'Execution step.'),
                    nullable: true
                ),
                new ArraySchema(
                    name: 'assumptions',
                    description: 'Optional assumptions made while planning.',
                    items: new StringSchema(name: 'assumption', description: 'Assumption item.'),
                    nullable: true
                ),
            ],
            requiredFields: []
        );
    }
}
