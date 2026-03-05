<?php

namespace App\Services;

use App\DataTransferObjects\Llm\AllPrioritizationDto;
use App\DataTransferObjects\Llm\EventPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\EventsAndProjectsPrioritizationDto;
use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\DataTransferObjects\Llm\ProjectPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\ProjectScheduleRecommendationDto;
use App\DataTransferObjects\Llm\ResolveDependencyRecommendationDto;
use App\DataTransferObjects\Llm\ScheduleAllDto;
use App\DataTransferObjects\Llm\ScheduleEventsAndProjectsDto;
use App\DataTransferObjects\Llm\ScheduleTasksAndEventsDto;
use App\DataTransferObjects\Llm\ScheduleTasksAndProjectsDto;
use App\DataTransferObjects\Llm\TaskPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\TasksAndEventsPrioritizationDto;
use App\DataTransferObjects\Llm\TasksAndProjectsPrioritizationDto;
use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\Llm\LlmSchemaFactory;
use App\Services\Llm\RuleBasedPrioritizationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class LlmInferenceService
{
    public function __construct(
        private LlmSchemaFactory $schemaFactory,
        private RuleBasedPrioritizationService $ruleBasedPrioritization,
    ) {}

    /**
     * Return a fallback result without calling the LLM (e.g. when Ollama is unreachable).
     */
    public function fallbackOnly(
        LlmIntent $intent,
        string $promptVersion,
        ?User $user = null,
        ?string $fallbackReason = null
    ): LlmInferenceResult {
        return $this->fallbackResult($intent, $promptVersion, $user, $fallbackReason);
    }

    /**
     * Run LLM inference with the given system prompt, user prompt (with context), and intent.
     * Returns structured result or fallback when Prism fails.
     */
    public function infer(
        string $systemPrompt,
        string $userPrompt,
        LlmIntent $intent,
        LlmSystemPromptResult $promptResult,
        ?User $user = null,
    ): LlmInferenceResult {
        $model = config('tasklyst.llm.model', 'hermes3:3b');
        $maxAttempts = max(1, (int) config('tasklyst.llm.max_attempts', 1));

        $schema = $this->schemaFactory->schemaForIntent($intent);
        $timeout = (int) config('tasklyst.llm.timeout', 60);
        $temperature = (float) config('tasklyst.llm.temperature', 0.3);
        $numCtx = (int) config('tasklyst.llm.num_ctx', 4096);
        $maxTokens = (int) config('tasklyst.llm.max_tokens', 700);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = Prism::structured()
                    ->using(Provider::Ollama, $model)
                    ->withSchema($schema)
                    ->withSystemPrompt($systemPrompt)
                    ->withPrompt($userPrompt)
                    ->withClientOptions(['timeout' => $timeout])
                    ->withProviderOptions([
                        'temperature' => $temperature,
                        'num_ctx' => $numCtx,
                    ])
                    ->withMaxTokens($maxTokens)
                    ->asStructured();

                $structured = $response->structured;

                if (! is_array($structured)) {
                    Log::warning('Prism structured response had no structured data, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                $structured = $this->trimStructuredKeys($structured);
                $structured = $this->normalizeStructuredForIntent($intent, $structured);

                if (! $this->isValidStructured($intent, $structured)
                    || ! $this->passesIntentSpecificValidation($intent, $structured)
                ) {
                    Log::warning('LLM returned invalid structured payload, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                        'structured_raw' => $structured,
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                return new LlmInferenceResult(
                    structured: $structured,
                    promptVersion: $promptResult->version,
                    promptTokens: $response->usage->promptTokens,
                    completionTokens: $response->usage->completionTokens,
                    usedFallback: false,
                    fallbackReason: null,
                );
            } catch (\Throwable $e) {
                $fallbackReason = $e instanceof ConnectionException ? 'connection_exception' : 'http_exception';

                Log::warning('LLM inference failed, will '.($attempt === 0 ? 'retry' : 'use fallback'), [
                    'intent' => $intent->value,
                    'prompt_version' => $promptResult->version,
                    'model' => $model,
                    'user_id' => $user?->id,
                    'attempt' => $attempt + 1,
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts - 1) {
                    $delaySeconds = (int) config('tasklyst.llm.retry_delay_seconds', 2);
                    if ($delaySeconds > 0) {
                        sleep($delaySeconds);
                    }

                    continue;
                }

                return $this->fallbackResult($intent, $promptResult->version, $user, $fallbackReason);
            }
        }

        return $this->fallbackResult($intent, $promptResult->version, $user, 'unknown_error');
    }

    /**
     * Recursively trim whitespace from array keys. Some LLMs (e.g. Ollama) return
     * JSON with leading/trailing spaces in key names, which breaks validation.
     *
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function trimStructuredKeys(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? trim($k) : $k;
            $out[$key] = is_array($v)
                ? (array_is_list($v)
                    ? array_map(fn ($item) => is_array($item) ? $this->trimStructuredKeys($item) : $item, $v)
                    : $this->trimStructuredKeys($v))
                : $v;
        }

        return $out;
    }

    /**
     * Normalise provider-specific quirks in structured payloads so that validation
     * remains stable even if the model returns minor variants like "tasks" instead
     * of "task" for entity_type.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function normalizeStructuredForIntent(LlmIntent $intent, array $structured): array
    {
        $rawValue = $structured['entity_type'] ?? null;
        $raw = is_string($rawValue) ? trim(mb_strtolower($rawValue)) : '';

        $normalized = match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::PrioritizeTasks => in_array($raw, ['task', 'tasks'], true) || $raw === ''
                ? 'task'
                : $raw,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::PrioritizeEvents => in_array($raw, ['event', 'events'], true) || $raw === ''
                ? 'event'
                : $raw,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PrioritizeProjects => in_array($raw, ['project', 'projects'], true) || $raw === ''
                ? 'project'
                : $raw,
            LlmIntent::PrioritizeTasksAndEvents => in_array($raw, ['task,event', 'multiple', 'tasks and events'], true) || $raw === ''
                ? 'task,event'
                : $raw,
            LlmIntent::PrioritizeTasksAndProjects => in_array($raw, ['task,project', 'multiple', 'tasks and projects'], true) || $raw === ''
                ? 'task,project'
                : $raw,
            LlmIntent::PrioritizeEventsAndProjects => in_array($raw, ['event,project', 'multiple', 'events and projects'], true) || $raw === ''
                ? 'event,project'
                : $raw,
            LlmIntent::PrioritizeAll => in_array($raw, ['all', 'multiple', 'task,event,project'], true) || $raw === ''
                ? 'all'
                : $raw,
            LlmIntent::ScheduleTasksAndEvents => in_array($raw, ['task,event', 'multiple', 'tasks and events'], true) || $raw === ''
                ? 'task,event'
                : $raw,
            LlmIntent::ScheduleTasksAndProjects => in_array($raw, ['task,project', 'multiple', 'tasks and projects'], true) || $raw === ''
                ? 'task,project'
                : $raw,
            LlmIntent::ScheduleEventsAndProjects => in_array($raw, ['event,project', 'multiple', 'events and projects'], true) || $raw === ''
                ? 'event,project'
                : $raw,
            LlmIntent::ScheduleAll => in_array($raw, ['all', 'multiple', 'task,event,project'], true) || $raw === ''
                ? 'all'
                : $raw,
            default => $raw !== '' ? $raw : 'task',
        };

        $structured['entity_type'] = $normalized;

        return $structured;
    }

    private function isValidStructured(LlmIntent $intent, array $structured): bool
    {
        if ($intent === LlmIntent::PrioritizeTasksAndEvents) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['ranked_tasks']) && is_array($structured['ranked_tasks']);
            $hasEvents = isset($structured['ranked_events']) && is_array($structured['ranked_events']);
            if (! $hasTasks && ! $hasEvents) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::PrioritizeTasksAndProjects) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['ranked_tasks']) && is_array($structured['ranked_tasks']);
            $hasProjects = isset($structured['ranked_projects']) && is_array($structured['ranked_projects']);
            if (! $hasTasks && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::PrioritizeEventsAndProjects) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasEvents = isset($structured['ranked_events']) && is_array($structured['ranked_events']);
            $hasProjects = isset($structured['ranked_projects']) && is_array($structured['ranked_projects']);
            if (! $hasEvents && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::PrioritizeAll) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['ranked_tasks']) && is_array($structured['ranked_tasks']);
            $hasEvents = isset($structured['ranked_events']) && is_array($structured['ranked_events']);
            $hasProjects = isset($structured['ranked_projects']) && is_array($structured['ranked_projects']);
            if (! $hasTasks && ! $hasEvents && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::ScheduleTasksAndEvents) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['scheduled_tasks']) && is_array($structured['scheduled_tasks']);
            $hasEvents = isset($structured['scheduled_events']) && is_array($structured['scheduled_events']);
            if (! $hasTasks && ! $hasEvents) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::ScheduleTasksAndProjects) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['scheduled_tasks']) && is_array($structured['scheduled_tasks']);
            $hasProjects = isset($structured['scheduled_projects']) && is_array($structured['scheduled_projects']);
            if (! $hasTasks && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::ScheduleEventsAndProjects) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasEvents = isset($structured['scheduled_events']) && is_array($structured['scheduled_events']);
            $hasProjects = isset($structured['scheduled_projects']) && is_array($structured['scheduled_projects']);
            if (! $hasEvents && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if ($intent === LlmIntent::ScheduleAll) {
            if (! isset($structured['recommended_action'], $structured['reasoning'])) {
                return false;
            }
            if (! is_string($structured['recommended_action']) || ! is_string($structured['reasoning'])) {
                return false;
            }
            if (trim($structured['recommended_action']) === '' || trim($structured['reasoning']) === '') {
                return false;
            }
            $hasTasks = isset($structured['scheduled_tasks']) && is_array($structured['scheduled_tasks']);
            $hasEvents = isset($structured['scheduled_events']) && is_array($structured['scheduled_events']);
            $hasProjects = isset($structured['scheduled_projects']) && is_array($structured['scheduled_projects']);
            if (! $hasTasks && ! $hasEvents && ! $hasProjects) {
                return false;
            }
            if (isset($structured['confidence'])
                && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
            ) {
                return false;
            }

            return true;
        }

        if (! isset(
            $structured['entity_type'],
            $structured['recommended_action'],
            $structured['reasoning']
        )) {
            return false;
        }

        if (! is_string($structured['entity_type'])
            || ! is_string($structured['recommended_action'])
            || ! is_string($structured['reasoning'])
        ) {
            return false;
        }

        if (isset($structured['confidence'])
            && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
        ) {
            return false;
        }

        $expectedEntityType = match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::PrioritizeTasks => 'task',
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::PrioritizeEvents => 'event',
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PrioritizeProjects => 'project',
            default => null,
        };

        if ($expectedEntityType !== null && $structured['entity_type'] !== $expectedEntityType) {
            return false;
        }

        return true;
    }

    /**
     * Run additional per-intent validation using DTOs where available.
     *
     * @param  array<string, mixed>  $structured
     */
    private function passesIntentSpecificValidation(LlmIntent $intent, array $structured): bool
    {
        $hasCoreText = isset($structured['recommended_action'], $structured['reasoning'])
            && is_string($structured['recommended_action'])
            && trim($structured['recommended_action']) !== ''
            && is_string($structured['reasoning'])
            && trim($structured['reasoning']) !== '';

        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => TaskScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => EventScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => ProjectScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeTasks => TaskPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeEvents => EventPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeProjects => ProjectPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ResolveDependency => ResolveDependencyRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeTasksAndEvents => TasksAndEventsPrioritizationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeTasksAndProjects => TasksAndProjectsPrioritizationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeEventsAndProjects => EventsAndProjectsPrioritizationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeAll => AllPrioritizationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleTasksAndEvents => ScheduleTasksAndEventsDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleTasksAndProjects => ScheduleTasksAndProjectsDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleEventsAndProjects => ScheduleEventsAndProjectsDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleAll => ScheduleAllDto::fromStructured($structured) !== null
                || $hasCoreText,
            default => true,
        };
    }

    private function fallbackResult(
        LlmIntent $intent,
        string $promptVersion,
        ?User $user,
        ?string $fallbackReason
    ): LlmInferenceResult {
        $structured = $this->buildFallbackStructured($intent, $user);

        return new LlmInferenceResult(
            structured: $structured,
            promptVersion: $promptVersion,
            promptTokens: 0,
            completionTokens: 0,
            usedFallback: true,
            fallbackReason: $fallbackReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFallbackStructured(LlmIntent $intent, ?User $user): array
    {
        if ($intent === LlmIntent::PrioritizeTasks && $user !== null) {
            $tasks = $this->ruleBasedPrioritization->prioritizeTasks($user, 10);
            $ranked = $tasks->map(fn ($t, $i) => [
                'rank' => $i + 1,
                'task_id' => $t->id,
                'title' => $t->title,
                'end_datetime' => $t->end_datetime?->toIso8601String(),
            ])->values()->all();

            return [
                'entity_type' => 'task',
                'recommended_action' => 'Prioritized by due date and priority (rule-based fallback).',
                'reasoning' => 'AI was unavailable. Tasks are ordered by: overdue first, then soonest due, then priority.',
                'confidence' => 0.7,
                'ranked_tasks' => $ranked,
            ];
        }

        if ($intent === LlmIntent::PrioritizeTasksAndEvents && $user !== null) {
            $tasks = $this->ruleBasedPrioritization->prioritizeTasks($user, 6);
            $rankedTasks = $tasks->map(fn ($t, $i) => [
                'rank' => $i + 1,
                'title' => $t->title,
                'end_datetime' => $t->end_datetime?->toIso8601String(),
            ])->values()->all();

            return [
                'entity_type' => 'task,event',
                'recommended_action' => 'Prioritized tasks by due date and priority (rule-based fallback). Events could not be ranked while the assistant is unavailable.',
                'reasoning' => 'AI was unavailable. Tasks are ordered by: overdue first, then soonest due, then priority. Events list is empty in fallback.',
                'confidence' => 0.5,
                'ranked_tasks' => $rankedTasks,
                'ranked_events' => [],
            ];
        }

        if ($intent === LlmIntent::PrioritizeTasksAndProjects && $user !== null) {
            $tasks = $this->ruleBasedPrioritization->prioritizeTasks($user, 6);
            $rankedTasks = $tasks->map(fn ($t, $i) => [
                'rank' => $i + 1,
                'title' => $t->title,
                'end_datetime' => $t->end_datetime?->toIso8601String(),
            ])->values()->all();

            return [
                'entity_type' => 'task,project',
                'recommended_action' => 'Prioritized tasks by due date and priority (rule-based fallback). Projects could not be ranked while the assistant is unavailable.',
                'reasoning' => 'AI was unavailable. Tasks are ordered by: overdue first, then soonest due, then priority. Projects list is empty in fallback.',
                'confidence' => 0.5,
                'ranked_tasks' => $rankedTasks,
                'ranked_projects' => [],
            ];
        }

        if ($intent === LlmIntent::PrioritizeEventsAndProjects && $user !== null) {
            return [
                'entity_type' => 'event,project',
                'recommended_action' => 'Events and projects could not be ranked while the assistant is unavailable. Please try again later.',
                'reasoning' => 'AI was unavailable. Fallback does not support event or project ranking.',
                'confidence' => 0.3,
                'ranked_events' => [],
                'ranked_projects' => [],
            ];
        }

        if ($intent === LlmIntent::PrioritizeAll && $user !== null) {
            $tasks = $this->ruleBasedPrioritization->prioritizeTasks($user, 6);
            $rankedTasks = $tasks->map(fn ($t, $i) => [
                'rank' => $i + 1,
                'title' => $t->title,
                'end_datetime' => $t->end_datetime?->toIso8601String(),
            ])->values()->all();

            return [
                'entity_type' => 'all',
                'recommended_action' => 'Prioritized tasks by due date and priority (rule-based fallback). Full "prioritize all" (tasks + events + projects) is unavailable while the assistant is unavailable; events and projects are empty.',
                'reasoning' => 'AI was unavailable. Tasks are ordered by: overdue first, then soonest due, then priority. Events and projects could not be ranked in fallback.',
                'confidence' => 0.5,
                'ranked_tasks' => $rankedTasks,
                'ranked_events' => [],
                'ranked_projects' => [],
            ];
        }

        if ($intent === LlmIntent::ScheduleTasksAndEvents) {
            return [
                'entity_type' => 'task,event',
                'recommended_action' => 'Scheduling for tasks and events is unavailable right now. Please try again later.',
                'reasoning' => 'The assistant is temporarily unavailable. Schedule suggestions could not be generated.',
                'confidence' => 0.3,
                'scheduled_tasks' => [],
                'scheduled_events' => [],
            ];
        }

        if ($intent === LlmIntent::ScheduleTasksAndProjects) {
            return [
                'entity_type' => 'task,project',
                'recommended_action' => 'Scheduling for tasks and projects is unavailable right now. Please try again later.',
                'reasoning' => 'The assistant is temporarily unavailable. Schedule suggestions could not be generated.',
                'confidence' => 0.3,
                'scheduled_tasks' => [],
                'scheduled_projects' => [],
            ];
        }

        if ($intent === LlmIntent::ScheduleEventsAndProjects) {
            return [
                'entity_type' => 'event,project',
                'recommended_action' => 'Scheduling for events and projects is unavailable right now. Please try again later.',
                'reasoning' => 'The assistant is temporarily unavailable. Schedule suggestions could not be generated.',
                'confidence' => 0.3,
                'scheduled_events' => [],
                'scheduled_projects' => [],
            ];
        }

        if ($intent === LlmIntent::ScheduleAll) {
            return [
                'entity_type' => 'all',
                'recommended_action' => 'Scheduling for all items is unavailable right now. Please try again later.',
                'reasoning' => 'The assistant is temporarily unavailable. Schedule suggestions could not be generated.',
                'confidence' => 0.3,
                'scheduled_tasks' => [],
                'scheduled_events' => [],
                'scheduled_projects' => [],
            ];
        }

        return [
            'entity_type' => 'task',
            'recommended_action' => 'Unable to get AI recommendation. Please try again or rephrase.',
            'reasoning' => 'The assistant is temporarily unavailable or could not produce a valid response.',
            'confidence' => 0.0,
        ];
    }
}
