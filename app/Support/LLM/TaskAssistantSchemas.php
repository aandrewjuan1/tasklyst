<?php

namespace App\Support\LLM;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class TaskAssistantSchemas
{
    /**
     * Advisory schema for small LLMs.
     * Keeps structure simple so the model can freely produce rich text.
     */
    public static function advisorySchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'advisory',
            description: 'Human-friendly advice with actionable points and an assistant voice.',
            properties: [
                new StringSchema(
                    name: 'summary',
                    description: 'A human-friendly summary of the recommendation or answer.'
                ),
                new ArraySchema(
                    name: 'points',
                    description: 'Ordered actionable points (each is a standalone instruction).',
                    items: new StringSchema(name: 'point', description: 'An actionable instruction.')
                ),
                new StringSchema(
                    name: 'assistant_line',
                    description: 'A friendly line from the assistant reflecting companion tone.',
                    nullable: true
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Optional confidence value (0.0 - 1.0).',
                    nullable: true
                ),
                new ArraySchema(
                    name: 'follow_ups',
                    description: 'Optional clarifying questions or prompts to refine the goal.',
                    items: new StringSchema(name: 'question', description: 'A clarifying question.'),
                    nullable: true
                ),
                new ObjectSchema(
                    name: 'meta',
                    description: 'Optional machine-friendly metadata.',
                    properties: [
                        new ArraySchema(
                            name: 'tags',
                            description: 'Optional tags for classification.',
                            items: new StringSchema(name: 'tag', description: 'Tag label.'),
                            nullable: true
                        ),
                        new NumberSchema(
                            name: 'estimated_minutes',
                            description: 'Optional estimated minutes for suggested work.',
                            nullable: true
                        ),
                    ],
                    requiredFields: []
                ),
            ],
            requiredFields: [
                'summary',
                'points',
            ]
        );
    }

    /**
     * Task choice schema: simple and predictable for small models.
     */
    public static function taskChoiceSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_choice',
            description: 'Chosen focus recommendation (task, event, or project) with rationale and ordered steps.',
            properties: [
                new StringSchema(
                    name: 'chosen_type',
                    description: 'Chosen focus type: task, event, or project.',
                    nullable: true
                ),
                new NumberSchema(
                    name: 'chosen_id',
                    description: 'ID of the chosen focus item from snapshot.(tasks|events|projects).',
                    nullable: true
                ),
                new StringSchema(
                    name: 'chosen_title',
                    description: 'Title/name of the chosen focus item.',
                    nullable: true
                ),
                new NumberSchema(
                    name: 'chosen_task_id',
                    description: 'Task ID from snapshot.tasks, or null if none.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'chosen_task_title',
                    description: 'Title of the chosen task when available.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'suggestion',
                    description: 'Natural-language suggestion describing what to focus on next.'
                ),
                new StringSchema(
                    name: 'reason',
                    description: 'Rationale explaining why this choice was made.'
                ),
                new ArraySchema(
                    name: 'steps',
                    description: 'Ordered next steps. Each step is a simple instruction string.',
                    items: new StringSchema(name: 'step', description: 'A concrete next step.')
                ),
                new NumberSchema(
                    name: 'estimated_minutes',
                    description: 'Optional estimated minutes to complete the next work.',
                    nullable: true
                ),
                new NumberSchema(
                    name: 'priority',
                    description: 'Optional priority score (0-100).',
                    nullable: true
                ),
                new ObjectSchema(
                    name: 'preselected_task',
                    description: 'Pre-selected task by deterministic engine.',
                    nullable: true,
                    properties: [
                        new NumberSchema(
                            name: 'id',
                            description: 'Task ID from snapshot.',
                            nullable: true
                        ),
                        new StringSchema(
                            name: 'title',
                            description: 'Task title.',
                            nullable: true
                        ),
                        new StringSchema(
                            name: 'reasoning',
                            description: 'Why this task was selected.',
                            nullable: true
                        ),
                    ],
                    requiredFields: []
                ),
                new ArraySchema(
                    name: 'tags',
                    description: 'Optional simple tags.',
                    items: new StringSchema(name: 'tag', description: 'Tag label.'),
                    nullable: true
                ),
            ],
            requiredFields: [
                'suggestion',
                'reason',
                'steps',
            ]
        );
    }

    /**
     * Mutating suggestion schema for tool actions.
     * Minimal and explicit so the client can act on it safely.
     */
    public static function mutatingSuggestionSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'tool_suggestion',
            description: 'Suggested action with args and execution hints.',
            properties: [
                new StringSchema(
                    name: 'action',
                    description: 'Action key such as create_task, update_task, delete_task.'
                ),
                new ObjectSchema(
                    name: 'args',
                    description: 'Flat object of arguments for the action.',
                    properties: [],
                    requiredFields: []
                ),
                new BooleanSchema(
                    name: 'dry_run',
                    description: 'If true, the suggestion is a preview and should not execute.',
                    nullable: true
                ),
                new BooleanSchema(
                    name: 'require_confirmation',
                    description: 'If true, client should confirm with the user before executing.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'label',
                    description: 'Human-readable description of the suggested action.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'action',
            ]
        );
    }

    /**
     * Task list schema (final user-visible response).
     */
    public static function taskListSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_list',
            description: 'Ordered list of the most important tasks for the user.',
            properties: [
                new ArraySchema(
                    name: 'items',
                    description: 'Ordered list of tasks as ranked by urgency.',
                    items: new ObjectSchema(
                        name: 'task_list_item',
                        description: 'Single task entry.',
                        properties: [
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Task id.'
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Task title.'
                            ),
                            new StringSchema(
                                name: 'reason',
                                description: 'Why this task was selected.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'due_date',
                                description: 'Due date (ISO string or null).',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'priority',
                                description: 'Priority level (urgent/high/medium/low) if available.',
                                nullable: true
                            ),
                            new ArraySchema(
                                name: 'next_steps',
                                description: 'Ordered per-task next steps (2-3 actions).',
                                items: new StringSchema(
                                    name: 'step',
                                    description: 'A concrete next step.'
                                )
                            ),
                        ],
                        requiredFields: [
                            'task_id',
                            'title',
                            'reason',
                            'next_steps',
                        ]
                    )
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'Optional short summary of what the list represents.',
                    nullable: true
                ),
                new NumberSchema(
                    name: 'limit_used',
                    description: 'Number of tasks returned in items.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'items',
            ]
        );
    }

    /**
     * Minimal schema for a “tool-only” Prism call used to populate toolResults.
     * We don't rely on the model's structured output; we only need the tool results.
     */
    public static function taskListToolCallSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_list_tool_call',
            description: 'Tool-only structured response for task listing.',
            properties: [
                new NumberSchema(
                    name: 'limit_requested',
                    description: 'Optional requested limit for the tool call.',
                    nullable: true
                ),
            ],
            requiredFields: []
        );
    }

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
            ],
            requiredFields: [
                'blocks',
            ]
        );
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
            ],
            requiredFields: []
        );
    }

    /**
     * Study plan schema with simple items.
     */
    public static function studyPlanSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'study_plan',
            description: 'Ordered study items with optional time estimates.',
            properties: [
                new ArraySchema(
                    name: 'items',
                    description: 'Ordered study items.',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'Single study item.',
                        properties: [
                            new StringSchema(
                                name: 'label',
                                description: 'What to study.'
                            ),
                            new NumberSchema(
                                name: 'minutes',
                                description: 'Optional minutes estimate.',
                                nullable: true
                            ),
                        ],
                        requiredFields: [
                            'label',
                        ]
                    )
                ),
                new NumberSchema(
                    name: 'total_minutes',
                    description: 'Optional total estimated minutes for the plan.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'Optional overview of the plan.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'items',
            ]
        );
    }

    /**
     * Review summary schema: completed vs remaining and next steps.
     */
    public static function reviewSummarySchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'review_summary',
            description: 'Completed and remaining tasks with next steps and optional metadata.',
            properties: [
                new ArraySchema(
                    name: 'completed',
                    description: 'List of completed tasks.',
                    items: new ObjectSchema(
                        name: 'completed_item',
                        description: 'Completed task entry.',
                        properties: [
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Task id.'
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Task title.'
                            ),
                        ],
                        requiredFields: [
                            'task_id',
                            'title',
                        ]
                    )
                ),
                new ArraySchema(
                    name: 'remaining',
                    description: 'List of remaining tasks.',
                    items: new ObjectSchema(
                        name: 'remaining_item',
                        description: 'Remaining task entry.',
                        properties: [
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Task id.'
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Task title.'
                            ),
                        ],
                        requiredFields: [
                            'task_id',
                            'title',
                        ]
                    )
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'Narrative summary of progress and status.'
                ),
                new ArraySchema(
                    name: 'next_steps',
                    description: 'Ordered list of next steps as simple instruction strings.',
                    items: new StringSchema(name: 'step', description: 'A step instruction.')
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Optional confidence value (0.0 - 1.0).',
                    nullable: true
                ),
                new StringSchema(
                    name: 'assistant_line',
                    description: 'Optional friendly closing from the assistant.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'completed',
                'remaining',
                'summary',
                'next_steps',
            ]
        );
    }
}
