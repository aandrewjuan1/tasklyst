<?php

namespace App\Services\Llm;

use App\Enums\LlmIntent;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Builds Prism ObjectSchema per intent for structured LLM output.
 * Entity ID is never in the schema; resolve server-side from context.
 */
class LlmSchemaFactory
{
    public function schemaForIntent(LlmIntent $intent): Schema
    {
        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => $this->taskRecommendationSchema(),
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => $this->eventRecommendationSchema(),
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => $this->projectRecommendationSchema(),
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects => $this->prioritizationSchemaForIntent($intent),
            LlmIntent::PrioritizeTasksAndEvents => $this->tasksAndEventsPrioritizationSchema(),
            LlmIntent::PrioritizeTasksAndProjects => $this->tasksAndProjectsPrioritizationSchema(),
            LlmIntent::PrioritizeEventsAndProjects => $this->eventsAndProjectsPrioritizationSchema(),
            LlmIntent::PrioritizeAll => $this->allPrioritizationSchema(),
            LlmIntent::ScheduleTasksAndEvents => $this->scheduleTasksAndEventsSchema(),
            LlmIntent::ScheduleTasksAndProjects => $this->scheduleTasksAndProjectsSchema(),
            LlmIntent::ScheduleEventsAndProjects => $this->scheduleEventsAndProjectsSchema(),
            LlmIntent::ScheduleAll => $this->scheduleAllSchema(),
            LlmIntent::ResolveDependency => $this->resolveDependencySchema(),
            LlmIntent::GeneralQuery => $this->genericRecommendationSchema(),
        };
    }

    private function taskRecommendationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_recommendation',
            description: 'Structured recommendation for a task (schedule or adjust)',
            properties: [
                new StringSchema('entity_type', 'Always "task"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of why (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new StringSchema('start_datetime', 'ISO 8601 datetime'),
                new StringSchema('end_datetime', 'ISO 8601 datetime'),
                new NumberSchema('duration', 'Duration in minutes'),
                new StringSchema('priority', 'low|medium|high|urgent'),
                new ArraySchema(
                    name: 'blockers',
                    description: 'List of blocker descriptions',
                    items: new StringSchema('item', 'Blocker description')
                ),
                new ArraySchema(
                    name: 'sessions',
                    description: 'Optional: multiple work sessions instead of a single block',
                    items: new ObjectSchema(
                        name: 'session',
                        description: 'Single proposed work session',
                        properties: [
                            new StringSchema('start_datetime', 'ISO 8601 session start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 session end datetime'),
                            new NumberSchema('duration', 'Optional: duration in minutes'),
                        ],
                        requiredFields: ['start_datetime', 'end_datetime']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function eventRecommendationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'event_recommendation',
            description: 'Structured recommendation for an event',
            properties: [
                new StringSchema('entity_type', 'Always "event"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of why (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new StringSchema('start_datetime', 'ISO 8601 datetime'),
                new StringSchema('end_datetime', 'ISO 8601 datetime'),
                new StringSchema('timezone', 'Timezone identifier'),
                new StringSchema('location', 'Location if applicable'),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function projectRecommendationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'project_recommendation',
            description: 'Structured recommendation for a project timeline',
            properties: [
                new StringSchema('entity_type', 'Always "project"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of why (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new StringSchema('start_datetime', 'ISO 8601 datetime'),
                new StringSchema('end_datetime', 'ISO 8601 datetime'),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function genericRecommendationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'recommendation',
            description: 'Structured recommendation (prioritization or general). Use listed_items when the user asks for a list or filter.',
            properties: [
                new StringSchema('entity_type', 'task|event|project'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'listed_items',
                    description: 'Optional: when user asks for a list or filter (e.g. tasks with low priority, no due date), list matching items from context',
                    items: new ObjectSchema(
                        name: 'listed_item',
                        description: 'One matching item; use exact title from context',
                        properties: [
                            new StringSchema('title', 'Item title'),
                            new StringSchema('priority', 'Optional: low|medium|high|urgent'),
                            new StringSchema('end_datetime', 'Optional: ISO 8601'),
                        ],
                        requiredFields: ['title']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function prioritizationSchemaForIntent(LlmIntent $intent): ObjectSchema
    {
        return match ($intent) {
            LlmIntent::PrioritizeTasks => $this->taskPrioritizationSchema(),
            LlmIntent::PrioritizeEvents => $this->eventPrioritizationSchema(),
            LlmIntent::PrioritizeProjects => $this->projectPrioritizationSchema(),
            default => $this->genericRecommendationSchema(),
        };
    }

    private function taskPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_prioritization',
            description: 'Structured prioritization for tasks',
            properties: [
                new StringSchema('entity_type', 'Always "task"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_tasks',
                    description: 'Ranked list of tasks',
                    items: new ObjectSchema(
                        name: 'ranked_task',
                        description: 'Single ranked task item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Task title'),
                            new StringSchema('end_datetime', 'ISO 8601 due datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function eventPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'event_prioritization',
            description: 'Structured prioritization for events',
            properties: [
                new StringSchema('entity_type', 'Always "event"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_events',
                    description: 'Ranked list of events',
                    items: new ObjectSchema(
                        name: 'ranked_event',
                        description: 'Single ranked event item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Event title'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function projectPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'project_prioritization',
            description: 'Structured prioritization for projects',
            properties: [
                new StringSchema('entity_type', 'Always "project"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_projects',
                    description: 'Ranked list of projects',
                    items: new ObjectSchema(
                        name: 'ranked_project',
                        description: 'Single ranked project item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('name', 'Project name'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function tasksAndEventsPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'tasks_and_events_prioritization',
            description: 'Structured prioritization for both tasks and events in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "task,event" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation covering tasks and events (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering for both (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_tasks',
                    description: 'Ranked list of tasks (can be empty if no tasks in context)',
                    items: new ObjectSchema(
                        name: 'ranked_task',
                        description: 'Single ranked task item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Task title'),
                            new StringSchema('end_datetime', 'ISO 8601 due datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
                new ArraySchema(
                    name: 'ranked_events',
                    description: 'Ranked list of events (can be empty if no events in context)',
                    items: new ObjectSchema(
                        name: 'ranked_event',
                        description: 'Single ranked event item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Event title'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function tasksAndProjectsPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'tasks_and_projects_prioritization',
            description: 'Structured prioritization for both tasks and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "task,project" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation covering tasks and projects (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering for both (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_tasks',
                    description: 'Ranked list of tasks (can be empty if no tasks in context)',
                    items: new ObjectSchema(
                        name: 'ranked_task',
                        description: 'Single ranked task item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Task title'),
                            new StringSchema('end_datetime', 'ISO 8601 due datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
                new ArraySchema(
                    name: 'ranked_projects',
                    description: 'Ranked list of projects (can be empty if no projects in context)',
                    items: new ObjectSchema(
                        name: 'ranked_project',
                        description: 'Single ranked project item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('name', 'Project name'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function eventsAndProjectsPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'events_and_projects_prioritization',
            description: 'Structured prioritization for both events and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "event,project" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation covering events and projects (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering for both (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_events',
                    description: 'Ranked list of events (can be empty if no events in context)',
                    items: new ObjectSchema(
                        name: 'ranked_event',
                        description: 'Single ranked event item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Event title'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
                new ArraySchema(
                    name: 'ranked_projects',
                    description: 'Ranked list of projects (can be empty if no projects in context)',
                    items: new ObjectSchema(
                        name: 'ranked_project',
                        description: 'Single ranked project item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('name', 'Project name'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function allPrioritizationSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'all_prioritization',
            description: 'Structured prioritization for tasks, events, and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "all" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation covering tasks, events, and projects (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of ordering for all (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'ranked_tasks',
                    description: 'Ranked list of tasks (can be empty if no tasks in context)',
                    items: new ObjectSchema(
                        name: 'ranked_task',
                        description: 'Single ranked task item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Task title'),
                            new StringSchema('end_datetime', 'ISO 8601 due datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
                new ArraySchema(
                    name: 'ranked_events',
                    description: 'Ranked list of events (can be empty if no events in context)',
                    items: new ObjectSchema(
                        name: 'ranked_event',
                        description: 'Single ranked event item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('title', 'Event title'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'title']
                    )
                ),
                new ArraySchema(
                    name: 'ranked_projects',
                    description: 'Ranked list of projects (can be empty if no projects in context)',
                    items: new ObjectSchema(
                        name: 'ranked_project',
                        description: 'Single ranked project item',
                        properties: [
                            new NumberSchema('rank', '1-based ranking'),
                            new StringSchema('name', 'Project name'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['rank', 'name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function scheduleTasksAndEventsSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_tasks_and_events',
            description: 'Structured schedule for both tasks and events in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "task,event" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'scheduled_tasks',
                    description: 'Scheduled task items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_task',
                        description: 'Single scheduled task',
                        properties: [
                            new StringSchema('title', 'Task title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                            new ArraySchema(
                                name: 'sessions',
                                description: 'Optional: multiple work sessions',
                                items: new ObjectSchema(
                                    name: 'session',
                                    description: 'Single work session with start and end',
                                    properties: [
                                        new StringSchema('start_datetime', 'ISO 8601'),
                                        new StringSchema('end_datetime', 'ISO 8601'),
                                    ],
                                    requiredFields: ['start_datetime', 'end_datetime']
                                )
                            ),
                        ],
                        requiredFields: ['title']
                    )
                ),
                new ArraySchema(
                    name: 'scheduled_events',
                    description: 'Scheduled event items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_event',
                        description: 'Single scheduled event',
                        properties: [
                            new StringSchema('title', 'Event title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['title']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function scheduleTasksAndProjectsSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_tasks_and_projects',
            description: 'Structured schedule for both tasks and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "task,project" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'scheduled_tasks',
                    description: 'Scheduled task items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_task',
                        description: 'Single scheduled task',
                        properties: [
                            new StringSchema('title', 'Task title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['title']
                    )
                ),
                new ArraySchema(
                    name: 'scheduled_projects',
                    description: 'Scheduled project items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_project',
                        description: 'Single scheduled project',
                        properties: [
                            new StringSchema('name', 'Project name from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function scheduleEventsAndProjectsSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_events_and_projects',
            description: 'Structured schedule for both events and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "event,project" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'scheduled_events',
                    description: 'Scheduled event items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_event',
                        description: 'Single scheduled event',
                        properties: [
                            new StringSchema('title', 'Event title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['title']
                    )
                ),
                new ArraySchema(
                    name: 'scheduled_projects',
                    description: 'Scheduled project items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_project',
                        description: 'Single scheduled project',
                        properties: [
                            new StringSchema('name', 'Project name from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function scheduleAllSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'schedule_all',
            description: 'Structured schedule for tasks, events, and projects in one response',
            properties: [
                new StringSchema('entity_type', 'Optional: "all" or "multiple"'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation (2-4 sentences in natural language)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'scheduled_tasks',
                    description: 'Scheduled task items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_task',
                        description: 'Single scheduled task',
                        properties: [
                            new StringSchema('title', 'Task title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['title']
                    )
                ),
                new ArraySchema(
                    name: 'scheduled_events',
                    description: 'Scheduled event items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_event',
                        description: 'Single scheduled event',
                        properties: [
                            new StringSchema('title', 'Event title from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['title']
                    )
                ),
                new ArraySchema(
                    name: 'scheduled_projects',
                    description: 'Scheduled project items (can be empty)',
                    items: new ObjectSchema(
                        name: 'scheduled_project',
                        description: 'Single scheduled project',
                        properties: [
                            new StringSchema('name', 'Project name from context'),
                            new StringSchema('start_datetime', 'ISO 8601 start datetime, optional'),
                            new StringSchema('end_datetime', 'ISO 8601 end datetime, optional'),
                        ],
                        requiredFields: ['name']
                    )
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }

    private function resolveDependencySchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'dependency_resolution',
            description: 'Structured plan to resolve blockers across tasks, events, and projects',
            properties: [
                new StringSchema('entity_type', 'task|event|project'),
                new StringSchema('recommended_action', 'Short, student-facing recommendation (1-3 sentences)'),
                new StringSchema('reasoning', 'Brief explanation of why this order will unblock progress (2-4 sentences)'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
                new ArraySchema(
                    name: 'next_steps',
                    description: 'Ordered list of actionable next steps (2-6 items)',
                    items: new StringSchema('item', 'A single next step')
                ),
                new ArraySchema(
                    name: 'blockers',
                    description: 'Optional list of blocker descriptions',
                    items: new StringSchema('item', 'Blocker description')
                ),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }
}
