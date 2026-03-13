<?php

namespace App\DataTransferObjects\Llm;

final class ConversationTurn
{
    public function __construct(
        public readonly string $role,
        public readonly string $text,
        public readonly ?array $structured,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
