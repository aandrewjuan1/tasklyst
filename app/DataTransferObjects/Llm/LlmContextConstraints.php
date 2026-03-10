<?php

namespace App\DataTransferObjects\Llm;

use Carbon\CarbonInterface;

class LlmContextConstraints
{
    /**
     * @param  array<int, string>  $subjectNames
     * @param  array<int, string>  $requiredTagNames
     * @param  array<int, string>  $excludedTagNames
     */
    public function __construct(
        public array $subjectNames = [],
        public array $requiredTagNames = [],
        public array $excludedTagNames = [],
        public ?CarbonInterface $windowStart = null,
        public ?CarbonInterface $windowEnd = null,
        public bool $schoolOnly = false,
        public bool $healthOrHouseholdOnly = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function hasTimeWindow(): bool
    {
        return $this->windowStart !== null && $this->windowEnd !== null;
    }
}
