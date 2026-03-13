<?php

namespace App\DataTransferObjects\Llm;

final class EventContextItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $startDatetime,
        public readonly int $durationMinutes,
    ) {}
}
