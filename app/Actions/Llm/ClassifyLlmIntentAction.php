<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\LlmIntentClassificationService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ClassifyLlmIntentAction
{
    public function __construct(
        private LlmIntentClassificationService $classificationService
    ) {}

    public function execute(string $userMessage): LlmIntentClassificationResult
    {
        $result = $this->classificationService->classify($userMessage);

        $threshold = config('tasklyst.intent.confidence_threshold', 0.7);
        $useLlmFallback = config('tasklyst.intent.use_llm_fallback', true);

        if ($useLlmFallback && $result->confidence < $threshold) {
            return $this->classifyWithLlmFallback($userMessage, $result);
        }

        return $result;
    }

    private function classifyWithLlmFallback(string $userMessage, LlmIntentClassificationResult $regexResult): LlmIntentClassificationResult
    {
        $fallback = $this->performLlmClassification($userMessage);

        if ($fallback === null) {
            return $regexResult;
        }

        return $fallback;
    }

    /**
     * Perform a small Prism structured call to classify intent/entity when regex confidence is low.
     */
    protected function performLlmClassification(string $userMessage): ?LlmIntentClassificationResult
    {
        $schema = new ObjectSchema(
            name: 'intent_classification',
            description: 'LLM intent classification fallback for TaskLyst assistant',
            properties: [
                new StringSchema('intent', 'Intent name, e.g. schedule_task, prioritize_tasks'),
                new StringSchema('entity_type', 'Entity type: task, event, or project'),
            ],
            requiredFields: ['intent', 'entity_type']
        );

        $systemPrompt = 'You classify a single user message into an intent and entity_type for a task management assistant. '
            .'Valid intents: schedule_task, schedule_event, schedule_project, prioritize_tasks, prioritize_events, prioritize_projects, '
            .'resolve_dependency, adjust_task_deadline, adjust_event_time, adjust_project_timeline, general_query. '
            .'Valid entity_type values: task, event, project. '
            .'Respond with JSON only, matching the schema.';

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, config('tasklyst.llm.model', 'hermes3:3b'))
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userMessage)
                ->withClientOptions([
                    'timeout' => (int) config('tasklyst.llm.timeout', 15),
                ])
                ->withProviderOptions([
                    'temperature' => 0.1,
                    'num_ctx' => 2048,
                ])
                ->withMaxTokens(64)
                ->asStructured();

            $structured = $response->structured;

            if (! is_array($structured)) {
                return null;
            }

            $intentValue = (string) ($structured['intent'] ?? '');
            $entityTypeValue = (string) ($structured['entity_type'] ?? '');

            $intent = LlmIntent::tryFrom($intentValue);
            $entityType = LlmEntityType::tryFrom($entityTypeValue);

            if ($intent === null || $entityType === null) {
                return null;
            }

            // Fallback classification is used only when regex is uncertain; treat as high confidence.
            return new LlmIntentClassificationResult(
                intent: $intent,
                entityType: $entityType,
                confidence: 0.9
            );
        } catch (PrismException $e) {
            Log::warning('LLM fallback intent classification failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
