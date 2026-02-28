<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Services\LlmIntentClassificationService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

class ClassifyLlmIntentAction
{
    public function __construct(
        private LlmIntentClassificationService $classificationService,
    ) {}

    public function execute(string $userMessage, ?AssistantThread $thread = null): LlmIntentClassificationResult
    {
        $regexResult = $this->classificationService->classify($userMessage);

        $useLlmFallback = (bool) config('tasklyst.intent.use_llm_fallback', true);

        if (! $useLlmFallback) {
            return $regexResult;
        }

        $threshold = (float) config('tasklyst.intent.confidence_threshold', 0.7);

        if ($regexResult->confidence >= $threshold) {
            return $regexResult;
        }

        // Regex was uncertain — try LLM fallback for non-trivial messages
        $minLengthForFallback = 3;
        if (mb_strlen(trim($userMessage)) < $minLengthForFallback) {
            return $regexResult;
        }

        $llmResult = $this->performLlmClassification($userMessage, $thread);

        if ($llmResult === null) {
            Log::info('LLM classification fallback returned null, using regex result', [
                'regex_intent' => $regexResult->intent->value,
                'regex_entity_type' => $regexResult->entityType->value,
                'regex_confidence' => $regexResult->confidence,
            ]);

            return $regexResult;
        }

        // Log divergence between regex and LLM for evaluation/tuning
        if ($llmResult->intent !== $regexResult->intent || $llmResult->entityType !== $regexResult->entityType) {
            Log::info('LLM classification overrides regex result', [
                'regex_intent' => $regexResult->intent->value,
                'regex_entity_type' => $regexResult->entityType->value,
                'regex_confidence' => $regexResult->confidence,
                'llm_intent' => $llmResult->intent->value,
                'llm_entity_type' => $llmResult->entityType->value,
            ]);
        }

        return $llmResult;
    }

    /**
     * Perform a small Prism structured call to classify intent/entity when regex confidence is low.
     * When thread is provided, includes conversation history so follow-ups (e.g. "how about in events?")
     * can be correctly inferred from context.
     */
    protected function performLlmClassification(string $userMessage, ?AssistantThread $thread = null): ?LlmIntentClassificationResult
    {
        $schema = new ObjectSchema(
            name: 'intent_classification',
            description: 'Classifies a student task management query into intent and entity type',
            properties: [
                new StringSchema(
                    name: 'intent',
                    description: 'One of the exact intent values listed in the system prompt',
                ),
                new StringSchema(
                    name: 'entity_type',
                    description: 'One of: task, event, project',
                ),
                new StringSchema(
                    name: 'confidence',
                    description: 'Your confidence from 0.0 to 1.0 as a decimal string, e.g. "0.85"',
                ),
            ],
            requiredFields: ['intent', 'entity_type', 'confidence'],
        );

        $intents = implode(', ', array_column(LlmIntent::cases(), 'value'));
        $entityTypes = implode(', ', array_column(LlmEntityType::cases(), 'value'));

        $systemPrompt = <<<PROMPT
You are a query classifier for TaskLyst, a student task management assistant.

Your job is to classify a single user message into one intent and one entity_type.

Valid intents (use the exact value):
{$intents}

Valid entity_type values (use the exact value):
{$entityTypes}

Classification rules:
- Use "general_query" when the message asks which task, event, or project to delete or remove (e.g. "what task should I delete?", "which one can I drop?"). Do not use prioritize_* for these; the answer is a single recommendation, not a priority order.
- Use "general_query" when the message is clearly NOT about tasks, events, or projects.
- Prefer specificity: "schedule_task" over "general_query" when uncertain (except for delete/remove questions above).
- Match entity_type to the dominant subject of the message.
- If no clear entity is mentioned, default entity_type to "task".
- If the message clearly asks about times, dates, or scheduling, prefer a schedule_* intent over general_query.
- If the message is very vague but you must choose, still pick the single best intent and entity_type, but set confidence below 0.4.
- When conversation history is provided: treat follow-ups like "how about in events?" or "same for projects?" as carrying over the previous intent. E.g. if the user asked "what to do first?" (prioritize_tasks) and then "how about in events?", classify intent as prioritize_events.

Examples:
"What should I work on today?" → intent: prioritize_tasks, entity_type: task, confidence: 0.92
"If I can delete 1 task, what task should I delete?" → intent: general_query, entity_type: task, confidence: 0.9
"Move my project deadline to Friday" → intent: adjust_project_timeline, entity_type: project, confidence: 0.95
"Help me plan my study sessions for exams" → intent: schedule_task, entity_type: task, confidence: 0.88
"What is the capital of France?" → intent: general_query, entity_type: task, confidence: 0.98

Respond ONLY with the JSON object. Do not explain.
PROMPT;

        $prompt = $this->buildClassificationPrompt($userMessage, $thread);

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, config('tasklyst.llm.model', 'hermes3:3b'))
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($prompt)
                ->withClientOptions([
                    'timeout' => (int) config('tasklyst.llm.classification_timeout', 10),
                ])
                ->withProviderOptions([
                    'temperature' => 0.0, // deterministic for classification
                    'num_ctx' => 1024, // allow conversation history for follow-up disambiguation
                ])
                ->withMaxTokens(48) // intent + entity_type + confidence fits in ~20 tokens
                ->asStructured();

            $structured = $response->structured;

            if (! is_array($structured)) {
                return null;
            }

            $intent = LlmIntent::tryFrom((string) ($structured['intent'] ?? ''));
            $entityType = LlmEntityType::tryFrom((string) ($structured['entity_type'] ?? ''));

            if ($intent === null || $entityType === null) {
                Log::warning('LLM returned unrecognized intent or entity_type', [
                    'raw_intent' => $structured['intent'] ?? null,
                    'raw_entity_type' => $structured['entity_type'] ?? null,
                ]);

                return null;
            }

            // Parse confidence from LLM rather than hardcoding 0.9
            $confidence = min(1.0, max(0.0, (float) ($structured['confidence'] ?? 0.75)));

            return new LlmIntentClassificationResult(
                intent: $intent,
                entityType: $entityType,
                confidence: $confidence,
            );
        } catch (Throwable $e) {
            Log::warning('LLM intent classification failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
    }

    private function buildClassificationPrompt(string $userMessage, ?AssistantThread $thread): string
    {
        if ($thread === null) {
            return $userMessage;
        }

        $limit = (int) config('tasklyst.context.conversation_history_limit', 5);
        $messages = $thread->lastMessages($limit);

        if ($messages->isEmpty()) {
            return $userMessage;
        }

        $lines = [];
        foreach ($messages as $m) {
            $role = $m->role === 'user' ? 'user' : 'assistant';
            $content = mb_substr($m->content, 0, 200);
            if (mb_strlen($m->content) > 200) {
                $content .= '...';
            }
            $lines[] = "- {$role}: {$content}";
        }

        $history = implode("\n", $lines);

        return "Conversation so far:\n{$history}\n\nCurrent message to classify: {$userMessage}";
    }
}
