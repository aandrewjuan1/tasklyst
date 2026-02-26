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
            LlmIntent::PrioritizeProjects,
            LlmIntent::ResolveDependency,
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
                new StringSchema('recommended_action', 'One-line action summary'),
                new StringSchema('reasoning', 'Step-by-step reasoning'),
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
                new StringSchema('recommended_action', 'One-line action summary'),
                new StringSchema('reasoning', 'Step-by-step reasoning'),
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
                new StringSchema('recommended_action', 'One-line action summary'),
                new StringSchema('reasoning', 'Step-by-step reasoning'),
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
            description: 'Structured recommendation (prioritization or general)',
            properties: [
                new StringSchema('entity_type', 'task|event|project'),
                new StringSchema('recommended_action', 'One-line action summary'),
                new StringSchema('reasoning', 'Step-by-step reasoning'),
                new NumberSchema('confidence', 'Self-reported 0-1'),
            ],
            requiredFields: ['entity_type', 'recommended_action', 'reasoning']
        );
    }
}
