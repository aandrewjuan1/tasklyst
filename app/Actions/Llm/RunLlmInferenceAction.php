<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\ExplicitUserTimeParser;
use App\Services\Llm\LlmHealthCheck;
use App\Services\Llm\LlmInteractionLogger;
use App\Services\Llm\StructuredOutputSanitizer;
use App\Services\LlmInferenceService;
use Illuminate\Support\Str;

class RunLlmInferenceAction
{
    public function __construct(
        private GetSystemPromptAction $getSystemPrompt,
        private BuildLlmContextAction $buildContext,
        private LlmInferenceService $inferenceService,
        private LlmHealthCheck $healthCheck,
        private LlmInteractionLogger $interactionLogger,
        private StructuredOutputSanitizer $sanitizer,
        private ExplicitUserTimeParser $explicitUserTimeParser,
    ) {}

    public function execute(
        User $user,
        string $userMessage,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null,
        ?string $traceId = null,
    ): LlmInferenceResult {
        $promptResult = $this->getSystemPrompt->execute($intent);

        if (! $this->healthCheck->isReachable()) {
            $result = $this->inferenceService->fallbackOnly(
                intent: $intent,
                promptVersion: $promptResult->version,
                user: $user,
                fallbackReason: 'health_unreachable',
            );

            $this->interactionLogger->logInference(
                user: $user,
                intent: $intent,
                entityType: $entityType,
                promptResult: $promptResult,
                inferenceResult: $result,
                context: [],
                durationMs: 0,
                llmReachable: false,
                traceId: $traceId,
            );

            return $result;
        }

        $context = $this->buildContext->execute($user, $intent, $entityType, $entityId, $thread, $userMessage);

        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $userPrompt = Str::limit($userMessage, 2000)."\n\nContext:\n".$contextJson;

        $startedAt = microtime(true);

        $result = $this->inferenceService->infer(
            systemPrompt: $promptResult->systemPrompt,
            userPrompt: $userPrompt,
            intent: $intent,
            promptResult: $promptResult,
            user: $user,
        );

        $rawStructured = $result->structured;

        $structured = in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            ? $this->taskScheduleStructuredFromRaw($rawStructured, $context)
            : $this->sanitizer->sanitize($rawStructured, $context, $intent, $entityType, $userMessage);

        // Backend safety net for explicit user time across all single-entity schedule/adjust intents.
        if (in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
        ], true)) {
            $structured = $this->overrideStartFromExplicitUserTime($structured, $context, $userMessage);
        }

        $result = new LlmInferenceResult(
            structured: $structured,
            promptVersion: $result->promptVersion,
            promptTokens: $result->promptTokens,
            completionTokens: $result->completionTokens,
            usedFallback: $result->usedFallback,
            fallbackReason: $result->fallbackReason,
            rawStructured: $result->usedFallback ? null : $rawStructured,
        );

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->interactionLogger->logInference(
            user: $user,
            intent: $intent,
            entityType: $entityType,
            promptResult: $promptResult,
            inferenceResult: $result,
            context: $context,
            durationMs: $durationMs,
            llmReachable: true,
            traceId: $traceId,
        );

        return $result;
    }

    /**
     * Use raw LLM output for task schedule; only override when there are no tasks in context.
     *
     * @param  array<string, mixed>  $rawStructured
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function taskScheduleStructuredFromRaw(array $rawStructured, array $context): array
    {
        $tasks = $context['tasks'] ?? [];
        if (! is_array($tasks) || $tasks === []) {
            return [
                'entity_type' => 'task',
                'recommended_action' => __('You have no tasks yet. Add tasks to your list to get scheduling suggestions.'),
                'reasoning' => __('I checked your tasks and there are none to schedule right now.'),
            ];
        }

        $structured = $rawStructured;
        unset($structured['end_datetime']);
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            unset($structured['proposed_properties']['end_datetime']);
        }

        $structured = $this->ensureSensibleStartTimeForTaskSchedule($structured);

        return $structured;
    }

    /**
     * When the user explicitly specifies a concrete date/time (e.g. "Friday at 9am"),
     * treat that as the primary source of truth for start_datetime and override any
     * conflicting start time from the model. This mirrors the RESPECT_EXPLICIT_USER_TIME
     * guardrail at the prompt layer with a backend safety net.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function overrideStartFromExplicitUserTime(array $structured, array $context, ?string $userMessage): array
    {
        $candidate = $this->explicitUserTimeParser->parseStartDatetime(
            $userMessage ?? ($context['user_scheduling_request'] ?? null),
            $context
        );

        if ($candidate === null) {
            return $structured;
        }

        $structured['start_datetime'] = $candidate->toIso8601String();
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            $structured['proposed_properties']['start_datetime'] = $candidate->toIso8601String();
        }

        return $structured;
    }

    /**
     * Ensure start_datetime is at least 30 minutes from now so the user has time to get ready.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function ensureSensibleStartTimeForTaskSchedule(array $structured): array
    {
        $startRaw = $structured['start_datetime'] ?? null;
        if (! is_string($startRaw) || trim($startRaw) === '') {
            return $structured;
        }

        $timezone = config('app.timezone', 'Asia/Manila');
        try {
            $start = \Carbon\CarbonImmutable::parse($startRaw, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return $structured;
        }

        $now = \Carbon\CarbonImmutable::now($timezone);
        $earliestSensible = $now->addMinutes(30)->setSecond(0)->setMicrosecond(0);
        if ($start->gte($earliestSensible)) {
            return $structured;
        }

        $structured['start_datetime'] = $earliestSensible->toIso8601String();
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            $structured['proposed_properties']['start_datetime'] = $earliestSensible->toIso8601String();
        }

        return $structured;
    }
}
