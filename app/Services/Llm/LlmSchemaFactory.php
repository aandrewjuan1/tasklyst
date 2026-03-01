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
