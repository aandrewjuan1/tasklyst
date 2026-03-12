<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmOperationMode;
use App\Models\AssistantThread;
use App\Services\Llm\LlmIntentAliasResolver;
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
        private LlmIntentAliasResolver $aliasResolver,
    ) {}

    public function execute(string $userMessage, ?AssistantThread $thread = null, ?string $traceId = null): LlmIntentClassificationResult
    {
        $regexResult = $this->classificationService->classify($userMessage);

        $useLlmFallback = (bool) config('tasklyst.intent.use_llm_fallback', true);

        if (! $useLlmFallback) {
            $this->logClassificationPath('regex_used', $regexResult, $traceId);

            return $regexResult;
        }

        $threshold = (float) config('tasklyst.intent.confidence_threshold', 0.7);

        if ($regexResult->confidence >= $threshold) {
            $this->logClassificationPath('regex_used', $regexResult, $traceId);

            return $regexResult;
        }

        // Regex was uncertain — try LLM fallback for non-trivial messages
        $minLengthForFallback = 3;
        if (mb_strlen(trim($userMessage)) < $minLengthForFallback) {
            $this->logClassificationPath('regex_used', $regexResult, $traceId);

            return $regexResult;
        }

        $llmResult = $this->performLlmClassification($userMessage, $thread);

        if ($llmResult === null) {
            Log::info('LLM classification fallback returned null, using regex result', [
                'regex_intent' => $regexResult->intent->value,
                'regex_entity_type' => $regexResult->entityType->value,
                'regex_confidence' => $regexResult->confidence,
                'trace_id' => $traceId,
            ]);
            $this->logClassificationPath('regex_used', $regexResult, $traceId);

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
                'trace_id' => $traceId,
            ]);
        }

        $this->logClassificationPath('llm_fallback', $llmResult, $traceId);

        return $llmResult;
    }

    private function logClassificationPath(string $path, LlmIntentClassificationResult $result, ?string $traceId): void
    {
        Log::info('LLM intent classification', [
            'classification_path' => $path,
            'intent' => $result->intent->value,
            'entity_type' => $result->entityType->value,
            'confidence' => $result->confidence,
            'trace_id' => $traceId,
        ]);
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
            description: 'Classifies a student task management query into operation mode and entity scope',
            properties: [
                new StringSchema(
                    name: 'operation_mode',
                    description: 'One of: schedule, prioritize, list_filter_search, general, update, create, resolve_dependency',
                ),
                new StringSchema(
                    name: 'entity_scope',
                    description: 'One of: task, event, project, multiple',
                ),
                new StringSchema(
                    name: 'entity_targets',
                    description: 'Comma-separated entities for multiple scope, e.g. "task,event" or "task,event,project"',
                ),
                new StringSchema(
                    name: 'confidence',
                    description: 'Your confidence from 0.0 to 1.0 as a decimal string, e.g. "0.85"',
                ),
            ],
            requiredFields: ['operation_mode', 'entity_scope', 'confidence'],
        );

        $entityTypes = implode(', ', array_column(LlmEntityType::cases(), 'value'));

        $systemPrompt = <<<PROMPT
You are a query classifier for TaskLyst, a student task management assistant.

Your job is to classify a single user message into one operation_mode and one entity_scope.

Valid entity_scope values (use the exact value):
{$entityTypes}

Classification rules:
- Use operation_mode "general" when the message asks which task, event, or project to delete or remove (e.g. "what task should I delete?", "which one can I drop?"). Do not use prioritize for these; the answer is a single recommendation, not a priority order.
- Use operation_mode "general" when the message is clearly NOT about tasks, events, or projects. For off-topic or non-planning questions, default entity_scope to "task".
- operation_mode meanings:
  - schedule: asks for timing/date planning or moving deadlines/times
  - prioritize: asks what to focus on first/rank
  - list_filter_search: asks to list/show/filter/search items, optionally constrained by tags, entity type, or time window
  - update: asks to change properties on existing item
  - create: asks to create/add new item
  - resolve_dependency: asks about blockers/dependencies
  - general: off-topic/meta complaints and delete/remove recommendation questions
- Use entity_scope "multiple" when user asks about two or more entities together.
- For entity_scope "multiple", also provide entity_targets with comma-separated values from: task,event,project.

Examples:
"What should I work on today?" → operation_mode: prioritize, entity_scope: task, confidence: 0.92
"If I can delete 1 task, what task should I delete?" → operation_mode: general, entity_scope: task, confidence: 0.9
"Move my project deadline to Friday" → operation_mode: schedule, entity_scope: project, confidence: 0.95
"Help me plan my study sessions for exams" → operation_mode: schedule, entity_scope: task, confidence: 0.88
"Prioritize both my tasks and events" → operation_mode: prioritize, entity_scope: multiple, entity_targets: task,event, confidence: 0.9
"Schedule all my items" → operation_mode: schedule, entity_scope: multiple, entity_targets: task,event,project, confidence: 0.9
"Change the duration of this task to 45 minutes" → operation_mode: update, entity_scope: task, confidence: 0.9
"Show only my exam-related tasks and events for this week." → operation_mode: list_filter_search, entity_scope: multiple, entity_targets: task,event, confidence: 0.92
"Filter to events only and show what’s coming up in the next 7 days." → operation_mode: list_filter_search, entity_scope: event, confidence: 0.92

Respond ONLY with the JSON object. Do not explain.
PROMPT;

        $prompt = $this->buildClassificationPrompt($userMessage, $thread);

        $classificationTimeout = (int) config('tasklyst.llm.classification_timeout', 10);

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, config('tasklyst.llm.model', 'hermes3:3b'))
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($prompt)
                ->withClientOptions([
                    'timeout' => $classificationTimeout,
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

            $operationMode = LlmOperationMode::tryFrom((string) ($structured['operation_mode'] ?? ''));
            $entityType = LlmEntityType::tryFrom((string) ($structured['entity_scope'] ?? ''));

            if ($operationMode === null || $entityType === null) {
                Log::warning('LLM returned unrecognized mode or entity_scope', [
                    'raw_operation_mode' => $structured['operation_mode'] ?? null,
                    'raw_entity_scope' => $structured['entity_scope'] ?? null,
                ]);

                return null;
            }

            $entityTargets = $this->parseEntityTargets((string) ($structured['entity_targets'] ?? ''), $entityType);
            $intent = $this->aliasResolver->resolve($operationMode, $entityType, $entityTargets);

            // Parse confidence from LLM rather than hardcoding 0.9
            $confidence = min(1.0, max(0.0, (float) ($structured['confidence'] ?? 0.75)));

            return new LlmIntentClassificationResult(
                intent: $intent,
                entityType: $entityType,
                confidence: $confidence,
                operationMode: $operationMode,
                entityTargets: $entityTargets,
            );
        } catch (Throwable $e) {
            $reason = str_contains(mb_strtolower($e->getMessage()), 'timeout') ? 'timeout' : 'exception';
            Log::warning('LLM intent classification failed, using regex result', [
                'reason' => $reason,
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

    /**
     * @return array<int, LlmEntityType>
     */
    private function parseEntityTargets(string $rawTargets, LlmEntityType $entityScope): array
    {
        if ($entityScope !== LlmEntityType::Multiple) {
            return [$entityScope];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', mb_strtolower($rawTargets)))));
        $targets = [];
        foreach ($parts as $part) {
            $type = LlmEntityType::tryFrom($part);
            if (! $type instanceof LlmEntityType || $type === LlmEntityType::Multiple) {
                continue;
            }
            if (! in_array($type, $targets, true)) {
                $targets[] = $type;
            }
        }

        return $targets;
    }
}
