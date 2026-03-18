<?php

namespace App\Support;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class TaskAssistantSchemas
{
    /**
     * Schema for general advisory responses (Phase 1+).
     *
     * This intentionally stays small and predictable so we can stream it as JSON consistently.
     */
    public static function advisorySchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'advisory',
            description: 'Structured advisory response with actionable bullet points.',
            properties: [
                new StringSchema(
                    name: 'summary',
                    description: '1–2 sentence summary of the answer.'
                ),
                new ArraySchema(
                    name: 'bullets',
                    description: 'Short, actionable bullet points.',
                    items: new StringSchema(
                        name: 'bullet',
                        description: 'A short actionable bullet.'
                    )
                ),
                new ArraySchema(
                    name: 'follow_ups',
                    description: 'Optional follow-up questions to clarify the user goal.',
                    items: new StringSchema(
                        name: 'question',
                        description: 'A concise follow-up question.'
                    ),
                    nullable: true
                ),
            ],
            requiredFields: [
                'summary',
                'bullets',
            ]
        );
    }

    /**
     * Schema for the "choose next task and break into steps" flow.
     */
    public static function taskChoiceSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_choice',
            description: 'Structured response for choosing the next task and breaking it into steps.',
            properties: [
                new NumberSchema(
                    name: 'chosen_task_id',
                    description: 'ID of the chosen task from snapshot.tasks, or null if no task is selected.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'chosen_task_title',
                    description: 'Title of the chosen task, matching the snapshot title for chosen_task_id when provided.',
                    nullable: true
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'One or two sentence summary of what the user should focus on.'
                ),
                new StringSchema(
                    name: 'reason',
                    description: 'Short rationale for why this task or plan was chosen.'
                ),
                new ArraySchema(
                    name: 'suggested_next_steps',
                    description: 'Ordered list of short, concrete next steps.',
                    items: new StringSchema(
                        name: 'step',
                        description: 'A short, concrete next step.'
                    )
                ),
            ],
            requiredFields: [
                'summary',
                'reason',
                'suggested_next_steps',
            ]
        );
    }

    /**
     * Schema for PHP-driven mutating tool suggestions.
     *
     * The model suggests a simple JSON object like:
     * { "action": "create_task", "args": { ... } }
     */
    public static function mutatingSuggestionSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'tool_suggestion',
            description: 'Small JSON suggestion describing which action to run and its arguments.',
            properties: [
                new StringSchema(
                    name: 'action',
                    description: 'Logical action name matching a configured tool key such as create_task, update_task, list_tasks.',
                ),
                new ObjectSchema(
                    name: 'args',
                    description: 'Arguments to pass to the suggested action. Keys should match the tool parameters.',
                    properties: [],
                    requiredFields: [],
                ),
            ],
            requiredFields: [
                'action',
            ]
        );
    }

    /**
     * Schema for a daily schedule proposal (Phase 4).
     *
     * The model proposes a small set of ordered time blocks for the day.
     */
    public static function dailyScheduleSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'daily_schedule',
            description: 'Proposed daily schedule with ordered time blocks referencing snapshot tasks or events.',
            properties: [
                new ArraySchema(
                    name: 'blocks',
                    description: 'Ordered list of time blocks for the day.',
                    items: new ObjectSchema(
                        name: 'block',
                        description: 'Single time block in the day.',
                        properties: [
                            new StringSchema(
                                name: 'start_time',
                                description: 'Local start time in ISO8601 (or HH:MM) format.',
                            ),
                            new StringSchema(
                                name: 'end_time',
                                description: 'Local end time in ISO8601 (or HH:MM) format.',
                            ),
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Optional ID of a task from snapshot.tasks scheduled in this block.',
                                nullable: true
                            ),
                            new NumberSchema(
                                name: 'event_id',
                                description: 'Optional ID of an event from snapshot.events scheduled in this block.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'label',
                                description: 'Short label for the block when no specific task/event is referenced.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'reason',
                                description: 'One sentence reason for choosing this block contents.',
                            ),
                        ],
                        requiredFields: [
                            'start_time',
                            'end_time',
                            'reason',
                        ]
                    )
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'One or two sentence overview of the proposed day.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'blocks',
            ]
        );
    }

    /**
     * Schema for a study / revision plan (Phase 4).
     */
    public static function studyPlanSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'study_plan',
            description: 'Structured study or revision plan referencing snapshot tasks where possible.',
            properties: [
                new ArraySchema(
                    name: 'items',
                    description: 'Ordered list of study or revision items.',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'Single study or revision item.',
                        properties: [
                            new StringSchema(
                                name: 'label',
                                description: 'Short label for what to study or revise.',
                            ),
                            new NumberSchema(
                                name: 'task_id',
                                description: 'Optional task ID from snapshot.tasks associated with this item.',
                                nullable: true
                            ),
                            new NumberSchema(
                                name: 'estimated_minutes',
                                description: 'Estimated minutes to spend on this item.',
                                nullable: true
                            ),
                            new StringSchema(
                                name: 'reason',
                                description: 'Short rationale for why this item is included.',
                                nullable: true
                            ),
                        ],
                        requiredFields: [
                            'label',
                        ]
                    )
                ),
                new StringSchema(
                    name: 'summary',
                    description: 'One or two sentence summary of the overall plan.',
                    nullable: true
                ),
            ],
            requiredFields: [
                'items',
            ]
        );
    }

    /**
     * Schema for a task review summary (Phase 4).
     */
    public static function reviewSummarySchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'review_summary',
            description: 'Summary of completed and remaining work with suggested next steps.',
            properties: [
                new ArraySchema(
                    name: 'completed',
                    description: 'List of recently completed tasks by ID and title.',
                    items: new ObjectSchema(
                        name: 'completed_item',
                        description: 'Completed task summary.',
                        properties: [
                            new NumberSchema(
                                name: 'task_id',
                                description: 'ID of a completed task from snapshot.tasks.',
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Title of the completed task.',
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
                    description: 'List of remaining tasks by ID and title.',
                    items: new ObjectSchema(
                        name: 'remaining_item',
                        description: 'Remaining task summary.',
                        properties: [
                            new NumberSchema(
                                name: 'task_id',
                                description: 'ID of a remaining task from snapshot.tasks.',
                            ),
                            new StringSchema(
                                name: 'title',
                                description: 'Title of the remaining task.',
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
                    description: 'Short narrative summary of what was completed and what remains.',
                ),
                new ArraySchema(
                    name: 'next_steps',
                    description: 'Short list of suggested next steps after this review.',
                    items: new StringSchema(
                        name: 'step',
                        description: 'Single suggested next step.',
                    )
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
