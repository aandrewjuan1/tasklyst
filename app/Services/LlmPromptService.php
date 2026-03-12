<?php

namespace App\Services;

use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
use App\Llm\Contracts\LlmPromptTemplate;
use App\Llm\PromptTemplates\AdjustEventTimePrompt;
use App\Llm\PromptTemplates\AdjustProjectTimelinePrompt;
use App\Llm\PromptTemplates\AdjustTaskDeadlinePrompt;
use App\Llm\PromptTemplates\CreateEventPrompt;
use App\Llm\PromptTemplates\CreateProjectPrompt;
use App\Llm\PromptTemplates\CreateTaskPrompt;
use App\Llm\PromptTemplates\GeneralQueryPrompt;
use App\Llm\PromptTemplates\PrioritizeAllPrompt;
use App\Llm\PromptTemplates\PrioritizeEventsAndProjectsPrompt;
use App\Llm\PromptTemplates\PrioritizeEventsPrompt;
use App\Llm\PromptTemplates\PrioritizeProjectsPrompt;
use App\Llm\PromptTemplates\PrioritizeTasksAndEventsPrompt;
use App\Llm\PromptTemplates\PrioritizeTasksAndProjectsPrompt;
use App\Llm\PromptTemplates\PrioritizeTasksPrompt;
use App\Llm\PromptTemplates\ResolveDependencyPrompt;
use App\Llm\PromptTemplates\ScheduleAllPrompt;
use App\Llm\PromptTemplates\ScheduleEventPrompt;
use App\Llm\PromptTemplates\ScheduleEventsAndProjectsPrompt;
use App\Llm\PromptTemplates\ScheduleProjectPrompt;
use App\Llm\PromptTemplates\ScheduleTaskPrompt;
use App\Llm\PromptTemplates\ScheduleTasksAndEventsPrompt;
use App\Llm\PromptTemplates\ScheduleTasksAndProjectsPrompt;
use App\Llm\PromptTemplates\ScheduleTasksPrompt;
use App\Llm\PromptTemplates\UpdateEventPropertiesPrompt;
use App\Llm\PromptTemplates\UpdateProjectPropertiesPrompt;
use App\Llm\PromptTemplates\UpdateTaskPropertiesPrompt;
use App\Services\Llm\LlmIntentAliasResolver;

class LlmPromptService
{
    public function __construct(
        private LlmIntentAliasResolver $intentAliasResolver,
    ) {}

    /**
     * @var array<string, class-string<LlmPromptTemplate>>
     */
    private const INTENT_TEMPLATES = [
        // Backward-compat alias: use the canonical multi-task scheduler prompt.
        LlmIntent::PlanTimeBlock->value => ScheduleTasksPrompt::class,
        LlmIntent::ScheduleTask->value => ScheduleTaskPrompt::class,
        LlmIntent::ScheduleTasks->value => ScheduleTasksPrompt::class,
        LlmIntent::ScheduleEvent->value => ScheduleEventPrompt::class,
        LlmIntent::ScheduleProject->value => ScheduleProjectPrompt::class,
        LlmIntent::ScheduleTasksAndEvents->value => ScheduleTasksAndEventsPrompt::class,
        LlmIntent::ScheduleTasksAndProjects->value => ScheduleTasksAndProjectsPrompt::class,
        LlmIntent::ScheduleEventsAndProjects->value => ScheduleEventsAndProjectsPrompt::class,
        LlmIntent::ScheduleAll->value => ScheduleAllPrompt::class,
        LlmIntent::PrioritizeTasks->value => PrioritizeTasksPrompt::class,
        LlmIntent::PrioritizeEvents->value => PrioritizeEventsPrompt::class,
        LlmIntent::PrioritizeProjects->value => PrioritizeProjectsPrompt::class,
        LlmIntent::PrioritizeTasksAndEvents->value => PrioritizeTasksAndEventsPrompt::class,
        LlmIntent::PrioritizeTasksAndProjects->value => PrioritizeTasksAndProjectsPrompt::class,
        LlmIntent::PrioritizeEventsAndProjects->value => PrioritizeEventsAndProjectsPrompt::class,
        LlmIntent::PrioritizeAll->value => PrioritizeAllPrompt::class,
        LlmIntent::ResolveDependency->value => ResolveDependencyPrompt::class,
        LlmIntent::AdjustTaskDeadline->value => AdjustTaskDeadlinePrompt::class,
        LlmIntent::AdjustEventTime->value => AdjustEventTimePrompt::class,
        LlmIntent::AdjustProjectTimeline->value => AdjustProjectTimelinePrompt::class,
        LlmIntent::UpdateTaskProperties->value => UpdateTaskPropertiesPrompt::class,
        LlmIntent::UpdateEventProperties->value => UpdateEventPropertiesPrompt::class,
        LlmIntent::UpdateProjectProperties->value => UpdateProjectPropertiesPrompt::class,
        LlmIntent::CreateTask->value => CreateTaskPrompt::class,
        LlmIntent::CreateEvent->value => CreateEventPrompt::class,
        LlmIntent::CreateProject->value => CreateProjectPrompt::class,
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

    /**
     * @param  array<int, LlmEntityType>  $entityTargets
     */
    public function getSystemPromptForModeAndScope(LlmOperationMode $mode, LlmEntityType $scope, array $entityTargets = []): LlmSystemPromptResult
    {
        $intent = $this->intentAliasResolver->resolve($mode, $scope, $entityTargets);

        return $this->getSystemPromptForIntent($intent);
    }
}
