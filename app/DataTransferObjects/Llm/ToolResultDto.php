<?php

namespace App\DataTransferObjects\Llm;

final class ToolResultDto
{
    /**
     * @param  array<int|string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tool,
        public readonly bool $success,
        public readonly array $payload,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @return array{tool: string, success: bool, payload: array<int|string, mixed>, errorMessage: string|null}
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'success' => $this->success,
            'payload' => $this->payload,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * @param  array{tool: string, success: bool, payload: array<int|string, mixed>}  $payload
     */
    public static function fromStoredPayload(array $payload): self
    {
        return new self(
            tool: $payload['tool'],
            success: $payload['success'],
            payload: $payload['payload'],
        );
    }
}
