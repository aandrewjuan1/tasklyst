<?php

namespace App\DataTransferObjects\Llm;

final class TaskContextItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $dueDate,
        public readonly ?string $priority,
        public readonly ?int $estimateMinutes,
    ) {}
}
