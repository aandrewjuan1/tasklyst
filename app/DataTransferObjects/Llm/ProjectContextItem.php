<?php

namespace App\DataTransferObjects\Llm;

final class ProjectContextItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int $activeTaskCount,
    ) {}
}
