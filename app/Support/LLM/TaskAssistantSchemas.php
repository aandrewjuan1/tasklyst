<?php

namespace App\Support\LLM;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class TaskAssistantSchemas
{
    /**
     * Daily schedule schema with simple blocks.
     */
    public static function dailyScheduleSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'daily_schedule',
            description: 'Ordered list of time blocks for the day with optional references.',
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
                    description: 'Optional overview of the proposed day.',
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
                    items: new StringSchema(name: 'assumption', description: 'One planning assumption.'),
                    nullable: true
                ),
            ],
            requiredFields: [
                'blocks',
            ]
        );
    }

    /**
     * Narrative fields for prioritize output: tasks are fixed by the backend; the model only polishes wording.
     */
    public static function prioritizeNarrativeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'prioritize_narrative',
            description: 'Assistant voice: read-only task list. Never mention snapshot, JSON, ITEMS_JSON, FILTER_CONTEXT, backend, or database in any field—the student must not see technical terms.',
            properties: [
                new StringSchema(
                    name: 'reasoning',
                    description: 'Required: 1-3 short sentences. Task assistant to the student (you/your or neutral). No student first-person (no I/my). Why this list matches their request; use only task titles, dates, and fields from the list. If you mention how many tasks there are, the number MUST exactly match the provided LISTED_TASK_COUNT—never miscount. Never say "snapshot" or "data".',
                    nullable: false
                ),
                new StringSchema(
                    name: 'suggested_guidance',
                    description: 'Required: one short paragraph (2-5 sentences). Start with "I suggest" or "I recommend". Warm, practical advice (e.g. avoiding overwhelm, managing time). No bullet characters. No mention of snapshot/JSON/backend. No invented durations. When you mention priority, each task must match its priority field in the list—do not call a task high-priority if it is medium or low; you may highlight only the high-priority rows or speak about the mix without mislabeling.',
                    nullable: false
                ),
            ],
            requiredFields: [
                'reasoning',
                'suggested_guidance',
            ]
        );
    }

    /**
     * General guidance for vague/help-seeking/overwhelmed prompts.
     *
     * The LLM should generate an empathetic message and exactly one question to
     * narrow the next action. The actual routing decision happens in a second
     * structured step.
     */
    public static function generalGuidanceSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'general_guidance',
            description: 'General help for vague or overwhelmed requests. Do not mention snapshot, JSON, backend, database, or other internal terms. Output only the guidance fields.',
            properties: [
                new StringSchema(
                    name: 'message',
                    description: 'Short empathetic acknowledgement (1-2 sentences). Assistant voice is preferred (e.g. “I can help…” is allowed). If the user message is gibberish/unclear/unintelligible, say you didn’t understand and ask them to rephrase in ONE short sentence (still within message). For off-topic requests, it may also include a brief refusal like “I can’t help with that—I’m a task assistant.” Message must not include the redirect question or any question marks. Avoid second-order questions in `message` like “Could you…/Would you…/Let me know…”.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'clarifying_question',
                    description: 'Exactly one short question that ends with a question mark. This is the only place the redirect question should appear (message must not contain it). Must include both ideas: `prioritize` and `schedule time blocks` (or equivalent wording). Prefer slightly varied wording across turns while preserving meaning. Do not use bullet characters.',
                    nullable: false
                ),
                new StringSchema(
                    name: 'redirect_target',
                    description: 'Where this guidance should lead: either prioritize, schedule, or either/unknown.',
                    nullable: false
                ),
                new ArraySchema(
                    name: 'suggested_replies',
                    description: '2-3 short suggested user replies that would answer the question.',
                    items: new StringSchema(name: 'reply', description: 'One suggested reply.'),
                    nullable: true
                ),
            ],
            requiredFields: [
                'message',
                'clarifying_question',
                'redirect_target',
            ]
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
     * Schedule-only narrative.
     */
    public static function hybridNarrativeSchema(): ObjectSchema
    {
        return self::dailyScheduleRefinementSchema();
    }

    /**
     * Refinement schema: used when blocks are generated deterministically
     * and the LLM only needs to write the narrative summary.
     */
    public static function dailyScheduleRefinementSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'daily_schedule_refinement',
            description: 'Refine the narrative summary for a previously proposed daily schedule.',
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
                    description: 'Why this schedule structure is appropriate for the user today.',
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
