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
     * Narrative fields for browse/listing: tasks are fixed by the backend; the model only polishes wording.
     */
    public static function browseNarrativeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'browse_listing_narrative',
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
     * Shared narrative schema for hybrid flows (schedule refinement and prioritize explanation).
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
