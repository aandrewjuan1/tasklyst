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
        $isFollowupReferencingPrevious = $previousListContext !== null
            && $this->isFollowupReferencingPreviousList((string) $userMessage, $operationMode);
        if ($isFollowupReferencingPrevious) {
            $payload = $this->restrictToPreviousListItems($payload, $entityType, $previousListContext);

            // For multi-task scheduling followups like “schedule those”, default to
            // scheduling all items from the previous list unless the overlay sets
            // a more specific requested_schedule_n.
            if ($operationMode === LlmOperationMode::Schedule
                && $entityType === LlmEntityType::Multiple
                && ! isset($payload['requested_schedule_n'])
                && isset($payload['tasks'])
                && is_array($payload['tasks'])
            ) {
                $payload['requested_schedule_n'] = count($payload['tasks']);
            }
        }
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

    private function isFollowupReferencingPreviousList(string $userMessage, LlmOperationMode $operationMode): bool
    {
        if ($operationMode !== LlmOperationMode::Schedule) {
            return false;
        }

        $normalized = mb_strtolower(trim($userMessage));
        if ($normalized === '') {
            return false;
        }

        $hasUsingPhrase = str_contains($normalized, 'using those')
            || str_contains($normalized, 'using them')
            || str_contains($normalized, 'using these');

        $hasPronoun = str_contains($normalized, 'those')
            || str_contains($normalized, 'them')
            || str_contains($normalized, 'these');

        $hasScheduleLike = str_contains($normalized, 'schedule')
            || str_contains($normalized, 'plan')
            || str_contains($normalized, 'spread')
            || str_contains($normalized, 'across tonight')
            || str_contains($normalized, 'across tomorrow');

        if ($hasUsingPhrase && $hasScheduleLike) {
            return true;
        }

        return $hasPronoun && $hasScheduleLike;
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $previousListContext
     * @return array<string, mixed>
     */
    private function restrictToPreviousListItems(array $payload, LlmEntityType $entityType, array $previousListContext): array
    {
        $itemsInOrder = $previousListContext['items_in_order'] ?? null;
        if (! is_array($itemsInOrder) || $itemsInOrder === []) {
            return $payload;
        }

        $allowedTaskTitles = [];
        $allowedEventTitles = [];
        $allowedProjectNames = [];

        foreach ($itemsInOrder as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
            $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';

            if ($title !== '') {
                $key = mb_strtolower($title);
                $allowedTaskTitles[$key] = true;
                $allowedEventTitles[$key] = true;
            }

            if ($name !== '') {
                $key = mb_strtolower($name);
                $allowedProjectNames[$key] = true;
            }
        }

        if ($allowedTaskTitles === [] && $allowedEventTitles === [] && $allowedProjectNames === []) {
            return $payload;
        }

        $previousEntityType = (string) ($previousListContext['entity_type'] ?? '');

        $shouldRestrictTasks = ($entityType === LlmEntityType::Task && $previousEntityType === LlmEntityType::Task->value)
            || ($entityType === LlmEntityType::Multiple
                && in_array($previousEntityType, [LlmEntityType::Task->value, LlmEntityType::Multiple->value], true));

        $shouldRestrictEvents = ($entityType === LlmEntityType::Event && $previousEntityType === LlmEntityType::Event->value)
            || ($entityType === LlmEntityType::Multiple
                && in_array($previousEntityType, [LlmEntityType::Event->value, LlmEntityType::Multiple->value], true));

        $shouldRestrictProjects = ($entityType === LlmEntityType::Project && $previousEntityType === LlmEntityType::Project->value)
            || ($entityType === LlmEntityType::Multiple
                && in_array($previousEntityType, [LlmEntityType::Project->value, LlmEntityType::Multiple->value], true));

        if ($shouldRestrictTasks && isset($payload['tasks']) && is_array($payload['tasks'])) {
            $payload['tasks'] = array_values(array_filter(
                $payload['tasks'],
                static function ($task) use ($allowedTaskTitles): bool {
                    if (! is_array($task)) {
                        return false;
                    }

                    $title = isset($task['title']) && is_string($task['title']) ? trim($task['title']) : '';
                    if ($title === '') {
                        return false;
                    }

                    return isset($allowedTaskTitles[mb_strtolower($title)]);
                }
            ));
        }

        if ($shouldRestrictEvents && isset($payload['events']) && is_array($payload['events'])) {
            $payload['events'] = array_values(array_filter(
                $payload['events'],
                static function ($event) use ($allowedEventTitles): bool {
                    if (! is_array($event)) {
                        return false;
                    }

                    $title = isset($event['title']) && is_string($event['title']) ? trim($event['title']) : '';
                    if ($title === '') {
                        return false;
                    }

                    return isset($allowedEventTitles[mb_strtolower($title)]);
                }
            ));
        }

        if ($shouldRestrictProjects && isset($payload['projects']) && is_array($payload['projects'])) {
            $payload['projects'] = array_values(array_filter(
                $payload['projects'],
                static function ($project) use ($allowedProjectNames): bool {
                    if (! is_array($project)) {
                        return false;
                    }

                    $name = isset($project['name']) && is_string($project['name']) ? trim($project['name']) : '';
                    if ($name === '') {
                        return false;
                    }

                    return isset($allowedProjectNames[mb_strtolower($name)]);
                }
            ));
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
