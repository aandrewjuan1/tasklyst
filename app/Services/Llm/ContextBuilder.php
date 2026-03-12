<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmContextConstraints;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
use App\Models\AssistantThread;
use App\Models\User;

class ContextBuilder
{
    public function __construct(
        private LlmContextConstraintService $constraintService,
        private CanonicalEntityContextFetcher $entityFetcher,
        private ConversationContextBuilder $conversationBuilder,
        private ContextOverlayComposer $overlayComposer,
        private TokenBudgetReducer $tokenBudgetReducer,
    ) {}

    public function build(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId,
        ?AssistantThread $thread = null,
        ?string $userMessage = null
    ): array {
        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');
        $operationMode = $this->operationModeFromIntent($intent);

        $constraints = $this->constraintService->parse(
            $userMessage ?? '',
            $intent,
            $entityType,
            \Carbon\CarbonImmutable::instance($now)
        );
        $effectiveConstraints = $constraints;
        if ($operationMode === LlmOperationMode::Schedule) {
            $effectiveConstraints = new LlmContextConstraints(
                subjectNames: $constraints->subjectNames,
                requiredTagNames: $constraints->requiredTagNames,
                excludedTagNames: $constraints->excludedTagNames,
                taskStatuses: $constraints->taskStatuses,
                taskPriorities: $constraints->taskPriorities,
                taskComplexities: $constraints->taskComplexities,
                taskRecurring: $constraints->taskRecurring,
                taskHasDueDate: $constraints->taskHasDueDate,
                taskHasStartDate: $constraints->taskHasStartDate,
                schoolOnly: $constraints->schoolOnly,
                healthOrHouseholdOnly: $constraints->healthOrHouseholdOnly,
                includeOverdueInWindow: $constraints->includeOverdueInWindow,
                examRelatedOnly: $constraints->examRelatedOnly,
            );
        }

        $payload = [
            'current_time' => $now->toIso8601String(),
            'current_date' => $now->toDateString(),
            'timezone' => $timezone,
            'current_time_human' => $now->format('Y-m-d H:i').' '.$timezone.' ('.$now->format('g:i A').')',
            'user_current_request' => trim((string) $userMessage),
        ];
        $previousListContext = $this->conversationBuilder->buildPreviousListContext($thread, $entityType, $userMessage);

        $targets = $this->entityTargetsFromIntent($intent, $entityType);
        $entityPayload = $this->entityFetcher->fetch(
            user: $user,
            entityScope: $entityType,
            targets: $targets,
            entityId: $entityId,
            constraints: $effectiveConstraints,
        );

        $payload = array_merge($payload, $entityPayload);
        $payload = $this->applyPreviousListOrdering($payload, $entityType, $previousListContext);
        $payload = $this->overlayComposer->apply((string) $userMessage, $operationMode, $payload);
        $payload['response_style'] = $this->responseStylePayload((string) $userMessage);

        $filteringSummary = $this->buildFilteringSummary($effectiveConstraints, $payload);
        if ($filteringSummary !== null) {
            $payload['filtering_summary'] = $filteringSummary;
        }

        $payload['conversation_history'] = $this->conversationBuilder->buildConversationHistory($thread);
        if ($previousListContext !== null) {
            $payload['previous_list_context'] = $previousListContext;
        }

        return $this->tokenBudgetReducer->reduce($payload);
    }

    private function operationModeFromIntent(LlmIntent $intent): LlmOperationMode
    {
        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::ScheduleTasks,
            LlmIntent::ScheduleEvent,
            LlmIntent::ScheduleProject,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::AdjustEventTime,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PlanTimeBlock => LlmOperationMode::Schedule,
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects,
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll => LlmOperationMode::Prioritize,
            LlmIntent::CreateTask,
            LlmIntent::CreateEvent,
            LlmIntent::CreateProject => LlmOperationMode::Create,
            LlmIntent::UpdateTaskProperties,
            LlmIntent::UpdateEventProperties,
            LlmIntent::UpdateProjectProperties => LlmOperationMode::Update,
            LlmIntent::ListFilterSearch => LlmOperationMode::ListFilterSearch,
            LlmIntent::ResolveDependency => LlmOperationMode::ResolveDependency,
            default => LlmOperationMode::General,
        };
    }

    /**
     * @return array<int, LlmEntityType>
     */
    private function entityTargetsFromIntent(LlmIntent $intent, LlmEntityType $entityType): array
    {
        if ($entityType !== LlmEntityType::Multiple) {
            return [$entityType];
        }

        return match ($intent) {
            LlmIntent::ScheduleTasksAndEvents, LlmIntent::PrioritizeTasksAndEvents => [LlmEntityType::Task, LlmEntityType::Event],
            LlmIntent::ScheduleTasksAndProjects, LlmIntent::PrioritizeTasksAndProjects => [LlmEntityType::Task, LlmEntityType::Project],
            LlmIntent::ScheduleEventsAndProjects, LlmIntent::PrioritizeEventsAndProjects => [LlmEntityType::Event, LlmEntityType::Project],
            LlmIntent::ScheduleTasks => [LlmEntityType::Task],
            default => [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $previousListContext
     * @return array<string, mixed>
     */
    private function applyPreviousListOrdering(array $payload, LlmEntityType $entityType, ?array $previousListContext): array
    {
        if (! is_array($previousListContext)) {
            return $payload;
        }

        $previousEntityType = (string) ($previousListContext['entity_type'] ?? '');
        $itemsInOrder = $previousListContext['items_in_order'] ?? null;
        if (! is_array($itemsInOrder) || $itemsInOrder === []) {
            return $payload;
        }

        $titles = [];
        foreach ($itemsInOrder as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        if ($titles === []) {
            return $payload;
        }

        if ($entityType === LlmEntityType::Task && $previousEntityType === LlmEntityType::Task->value) {
            $payload['tasks'] = $this->reorderEntityItemsByTitles($payload['tasks'] ?? [], $titles, 'title');
        }
        if ($entityType === LlmEntityType::Event && $previousEntityType === LlmEntityType::Event->value) {
            $payload['events'] = $this->reorderEntityItemsByTitles($payload['events'] ?? [], $titles, 'title');
        }
        if ($entityType === LlmEntityType::Project && $previousEntityType === LlmEntityType::Project->value) {
            $payload['projects'] = $this->reorderEntityItemsByTitles($payload['projects'] ?? [], $titles, 'name');
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $titles
     * @return array<int, array<string, mixed>>
     */
    private function reorderEntityItemsByTitles(mixed $items, array $titles, string $labelKey): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $normalizedOrder = [];
        foreach ($titles as $position => $title) {
            $key = mb_strtolower(trim($title));
            if ($key !== '' && ! isset($normalizedOrder[$key])) {
                $normalizedOrder[$key] = $position;
            }
        }

        $decorated = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = isset($item[$labelKey]) && is_string($item[$labelKey]) ? trim($item[$labelKey]) : '';
            $key = mb_strtolower($label);
            $decorated[] = [
                'rank' => $normalizedOrder[$key] ?? PHP_INT_MAX,
                'index' => $index,
                'item' => $item,
            ];
        }

        usort($decorated, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return $a['index'] <=> $b['index'];
        });

        return array_values(array_map(static fn (array $entry): array => $entry['item'], $decorated));
    }

    /**
     * @return array<string, string>
     */
    private function responseStylePayload(string $userMessage): array
    {
        $normalized = mb_strtolower(trim($userMessage));
        $style = 'neutral';

        if ($normalized !== '') {
            if (str_contains($normalized, 'pls')
                || str_contains($normalized, 'hmm')
                || str_contains($normalized, 'how about')
                || str_contains($normalized, 'hey')
            ) {
                $style = 'casual';
            } elseif (str_contains($normalized, 'please')
                || str_contains($normalized, 'kindly')
                || str_contains($normalized, 'would you')
            ) {
                $style = 'formal';
            }
        }

        return [
            'assistant_tone' => 'warm_coach',
            'mirror_user_style' => $style,
            'verbosity' => 'concise',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function buildFilteringSummary(LlmContextConstraints $constraints, array $payload): ?array
    {
        $dimensions = [];

        if ($constraints->subjectNames !== []) {
            $dimensions[] = 'subject';
        }
        if ($constraints->requiredTagNames !== []) {
            $dimensions[] = 'required_tag';
        }
        if ($constraints->excludedTagNames !== []) {
            $dimensions[] = 'excluded_tag';
        }
        if ($constraints->taskStatuses !== []) {
            $dimensions[] = 'task_status';
        }
        if ($constraints->examRelatedOnly) {
            $dimensions[] = 'exam_related';
        }
        if ($constraints->taskPriorities !== []) {
            $dimensions[] = 'task_priority';
        }
        if ($constraints->taskComplexities !== []) {
            $dimensions[] = 'task_complexity';
        }
        if ($constraints->taskRecurring !== null) {
            $dimensions[] = 'task_recurring';
        }
        if ($constraints->taskHasDueDate !== null) {
            $dimensions[] = 'task_due_date_presence';
        }
        if ($constraints->taskHasStartDate !== null) {
            $dimensions[] = 'task_start_date_presence';
        }
        if ($constraints->schoolOnly) {
            $dimensions[] = 'school_only';
        }
        if ($constraints->healthOrHouseholdOnly) {
            $dimensions[] = 'health_or_household_only';
        }
        if ($constraints->hasTimeWindow()) {
            $dimensions[] = 'time_window';
        }

        $applied = $dimensions !== [];
        if (! $applied) {
            return null;
        }

        $tasks = $payload['tasks'] ?? [];
        $events = $payload['events'] ?? [];
        $projects = $payload['projects'] ?? [];

        return [
            'applied' => true,
            'dimensions' => $dimensions,
            'counts' => [
                'tasks' => is_array($tasks) ? count($tasks) : 0,
                'events' => is_array($events) ? count($events) : 0,
                'projects' => is_array($projects) ? count($projects) : 0,
            ],
        ];
    }
}
