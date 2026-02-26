<?php

namespace App\Services;

use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmIntent;
use App\Llm\Contracts\LlmPromptTemplate;
use App\Llm\PromptTemplates\AdjustEventTimePrompt;
use App\Llm\PromptTemplates\AdjustProjectTimelinePrompt;
use App\Llm\PromptTemplates\AdjustTaskDeadlinePrompt;
use App\Llm\PromptTemplates\GeneralQueryPrompt;
use App\Llm\PromptTemplates\PrioritizeEventsPrompt;
use App\Llm\PromptTemplates\PrioritizeProjectsPrompt;
use App\Llm\PromptTemplates\PrioritizeTasksPrompt;
use App\Llm\PromptTemplates\ResolveDependencyPrompt;
use App\Llm\PromptTemplates\ScheduleEventPrompt;
use App\Llm\PromptTemplates\ScheduleProjectPrompt;
use App\Llm\PromptTemplates\ScheduleTaskPrompt;

class LlmPromptService
{
    /**
     * @var array<string, class-string<LlmPromptTemplate>>
     */
    private const INTENT_TEMPLATES = [
        LlmIntent::ScheduleTask->value => ScheduleTaskPrompt::class,
        LlmIntent::ScheduleEvent->value => ScheduleEventPrompt::class,
        LlmIntent::ScheduleProject->value => ScheduleProjectPrompt::class,
        LlmIntent::PrioritizeTasks->value => PrioritizeTasksPrompt::class,
        LlmIntent::PrioritizeEvents->value => PrioritizeEventsPrompt::class,
        LlmIntent::PrioritizeProjects->value => PrioritizeProjectsPrompt::class,
        LlmIntent::ResolveDependency->value => ResolveDependencyPrompt::class,
        LlmIntent::AdjustTaskDeadline->value => AdjustTaskDeadlinePrompt::class,
        LlmIntent::AdjustEventTime->value => AdjustEventTimePrompt::class,
        LlmIntent::AdjustProjectTimeline->value => AdjustProjectTimelinePrompt::class,
        LlmIntent::GeneralQuery->value => GeneralQueryPrompt::class,
    ];

    public function getSystemPromptForIntent(LlmIntent $intent): LlmSystemPromptResult
    {
        $templateClass = self::INTENT_TEMPLATES[$intent->value] ?? GeneralQueryPrompt::class;
        $template = app($templateClass);

        return new LlmSystemPromptResult(
            systemPrompt: $template->systemPrompt(),
            version: $template->version(),
        );
    }
}
