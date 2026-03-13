<?php

namespace App\Services\Llm;

use App\Actions\Llm\RetryRepairAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\LlmResponseDto;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\Enums\LlmIntent;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Exceptions\Llm\UnknownEntityException;
use Illuminate\Support\Facades\Log;

class PostProcessorService
{
    public function __construct(
        private readonly string $schemaVersion,
        private readonly float $confidenceLow,
        private readonly RetryRepairAction $repairAction,
    ) {}

    public function process(LlmRawResponseDto $raw, ContextDto $context): LlmResponseDto
    {
        $parsed = $this->parseJson($raw->rawText);

        if ($parsed === null) {
            $repaired = ($this->repairAction)(
                $raw->rawText,
                'canonical LLM envelope with intent, data, tool_call, message, meta',
            );

            $parsed = $repaired ? $this->parseJson($repaired) : null;

            if ($parsed === null) {
                Log::channel(config('llm.log.channel'))->warning('llm.parse_failed', [
                    'raw_snippet' => substr($raw->rawText, 0, 200),
                ]);

                throw new LlmValidationException(
                    'Model output could not be parsed as JSON after repair attempt.',
                    'PARSE_ERROR',
                    $raw->rawText,
                );
            }
        }

        $parsed = $this->normalizePayload($parsed);

        $receivedVersion = $parsed['schema_version'] ?? '';
        if ($receivedVersion !== $this->schemaVersion) {
            throw new LlmSchemaVersionException($receivedVersion, $this->schemaVersion);
        }

        $intentValue = $parsed['intent'] ?? '';
        $intent = LlmIntent::tryFrom($intentValue);
        if ($intent === null) {
            throw new LlmValidationException(
                "Invalid intent value: [{$intentValue}]",
                'VALIDATION_ERROR',
                $raw->rawText,
            );
        }

        $message = $this->normalizeMessage($parsed['message'] ?? null, $raw->rawText);
        $confidence = $this->normalizeConfidence($parsed['meta']['confidence'] ?? 0.0, $raw->rawText);
        $this->validateConfidenceRange($confidence, $raw->rawText);

        $toolCall = null;
        if (! empty($parsed['tool_call']) && $intent->canTriggerToolCall()) {
            $toolCall = $this->validateToolCall($parsed['tool_call'], $context, $raw->rawText);
        }

        $this->validateEntityReferences($parsed['data'] ?? [], $context);

        $response = new LlmResponseDto(
            intent: $intent,
            data: $parsed['data'] ?? [],
            toolCall: $toolCall,
            isError: $intent === LlmIntent::Error,
            message: $message,
            confidence: $confidence,
            schemaVersion: $receivedVersion,
            raw: null,
        );

        return $this->applyDomainGuardrails($response, $context);
    }

    private function parseJson(string $text): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $clean = preg_replace('/\s*```$/', '', $clean);

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function normalizePayload(array $parsed): array
    {
        if (! isset($parsed['data']) || ! is_array($parsed['data'])) {
            $parsed['data'] = [];
        }

        if (! array_key_exists('tool_call', $parsed) || $parsed['tool_call'] === 'null' || $parsed['tool_call'] === '') {
            $parsed['tool_call'] = null;
        }

        if ($parsed['tool_call'] !== null && ! is_array($parsed['tool_call'])) {
            $parsed['tool_call'] = null;
        }

        if (! isset($parsed['meta']) || ! is_array($parsed['meta'])) {
            $parsed['meta'] = ['confidence' => 0.0];
        }

        return $parsed;
    }

    private function normalizeMessage(mixed $message, string $rawText): string
    {
        if (is_string($message)) {
            $normalized = trim($message);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        if (is_int($message) || is_float($message) || is_bool($message)) {
            return (string) $message;
        }

        throw new LlmValidationException(
            'Response is missing a valid user-facing message.',
            'VALIDATION_ERROR',
            $rawText,
        );
    }

    private function normalizeConfidence(mixed $confidence, string $rawText): float
    {
        if (is_int($confidence) || is_float($confidence)) {
            return (float) $confidence;
        }

        if (is_string($confidence) && is_numeric($confidence)) {
            return (float) $confidence;
        }

        throw new LlmValidationException(
            'meta.confidence must be numeric.',
            'VALIDATION_ERROR',
            $rawText,
        );
    }

    private function validateConfidenceRange(float $confidence, string $rawText): void
    {
        if ($confidence < 0 || $confidence > 1) {
            throw new LlmValidationException(
                'meta.confidence must be between 0 and 1.',
                'VALIDATION_ERROR',
                $rawText,
            );
        }
    }

    private function validateToolCall(array $tc, ContextDto $context, string $rawText): ToolCallDto
    {
        $tool = $tc['tool'] ?? '';
        if (! in_array($tool, config('llm.allowed_tools'), true)) {
            throw new LlmValidationException(
                "Tool [{$tool}] is not in the allowed tools whitelist.",
                'VALIDATION_ERROR',
                $rawText,
            );
        }

        $clientRequestId = $tc['client_request_id'] ?? '';
        if ($clientRequestId === '') {
            throw new LlmValidationException(
                'Tool call is missing client_request_id.',
                'VALIDATION_ERROR',
                $rawText,
            );
        }

        $args = $tc['args'] ?? [];

        if (isset($args['start_datetime'])) {
            $start = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $args['start_datetime']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone(config('llm.timezone')));

            if ($start instanceof \DateTimeImmutable && $start < $now) {
                throw new LlmValidationException(
                    'Proposed start_datetime is in the past.',
                    'VALIDATION_ERROR',
                    $rawText,
                );
            }
        }

        return new ToolCallDto(
            tool: $tool,
            args: $args,
            clientRequestId: $clientRequestId,
            confirmationRequired: (bool) ($tc['confirmation_required'] ?? false),
        );
    }

    private function validateEntityReferences(array $data, ContextDto $context): void
    {
        $validTaskIds = $context->taskIds();

        foreach ($data['scheduled_items'] ?? [] as $item) {
            $rawId = $item['id'] ?? '';
            $numericId = (int) str_replace('task_', '', $rawId);

            if (! in_array($numericId, $validTaskIds, true)) {
                throw new UnknownEntityException('task', $rawId);
            }
        }

        if (isset($data['id'])) {
            $numericId = (int) str_replace('task_', '', $data['id']);

            if (! in_array($numericId, $validTaskIds, true)) {
                throw new UnknownEntityException('task', $data['id']);
            }
        }
    }

    private function applyDomainGuardrails(LlmResponseDto $response, ContextDto $context): LlmResponseDto
    {
        $guardrailConfig = config('llm.prompt.domain_guardrails', []);

        if (! ($guardrailConfig['enabled'] ?? false)) {
            return $response;
        }

        $lastUserMessage = $context->lastUserMessage;

        if ($lastUserMessage === null || trim($lastUserMessage) === '') {
            return $response;
        }

        $topic = $this->detectDomainTopic($lastUserMessage);

        if ($topic === 'productivity' || $topic === 'unknown') {
            return $response;
        }

        if ($topic === 'politics' && ! ($guardrailConfig['block_politics'] ?? false)) {
            return $response;
        }

        if ($topic === 'out_of_scope' && ! ($guardrailConfig['block_out_of_scope_qa'] ?? false)) {
            return $response;
        }

        if ($response->intent === LlmIntent::Clarify || $response->intent === LlmIntent::Error) {
            return $response;
        }

        Log::channel(config('llm.log.channel'))->info('llm.domain_guardrail_applied', [
            'topic' => $topic,
            'original_intent' => $response->intent->value,
            'message_snippet' => mb_substr($lastUserMessage, 0, 120),
        ]);

        $refusalMessage = 'I am a study and task-planning assistant, so I do not handle that kind of question. I can help you organize your assignments, projects, and schedule instead.';

        return new LlmResponseDto(
            intent: LlmIntent::General,
            data: [],
            toolCall: null,
            isError: false,
            message: $refusalMessage,
            confidence: min($response->confidence, 0.8),
            schemaVersion: $response->schemaVersion,
            raw: $response->raw,
        );
    }

    private function detectDomainTopic(string $message): string
    {
        $text = mb_strtolower($message);

        $productivityKeywords = [
            'task',
            'assignment',
            'homework',
            'project',
            'study',
            'schedule',
            'plan',
            'prioritize',
            'deadline',
            'due',
        ];

        foreach ($productivityKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'productivity';
            }
        }

        $politicsKeywords = [
            'president',
            'prime minister',
            'senator',
            'mayor',
            'election',
            'vote',
            'politic',
            'government',
            'democrat',
            'republican',
        ];

        foreach ($politicsKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'politics';
            }
        }

        return 'out_of_scope';
    }
}
