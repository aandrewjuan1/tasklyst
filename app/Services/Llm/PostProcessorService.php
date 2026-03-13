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

        $confidence = (float) ($parsed['meta']['confidence'] ?? 0.0);

        $toolCall = null;
        if (! empty($parsed['tool_call']) && $intent->canTriggerToolCall()) {
            $toolCall = $this->validateToolCall($parsed['tool_call'], $context, $raw->rawText);
        }

        $this->validateEntityReferences($parsed['data'] ?? [], $context);

        return new LlmResponseDto(
            intent: $intent,
            data: $parsed['data'] ?? [],
            toolCall: $toolCall,
            isError: $intent === LlmIntent::Error,
            message: $parsed['message'] ?? '',
            confidence: $confidence,
            schemaVersion: $receivedVersion,
            raw: null,
        );
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
}
