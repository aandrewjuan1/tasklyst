<?php

namespace App\DataTransferObjects\Ui;

final class RecommendationDisplayDto
{
    /**
     * @param  array<int, mixed>  $cards
     * @param  array<int, mixed>  $actions
     */
    public function __construct(
        public readonly string $primaryMessage,
        public readonly array $cards = [],
        public readonly array $actions = [],
        public readonly bool $isError = false,
        public readonly ?string $traceId = null,
    ) {}
}
