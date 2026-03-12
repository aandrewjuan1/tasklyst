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
        public array $taskStatuses = [],
        public array $taskPriorities = [],
        public array $taskComplexities = [],
        public ?bool $taskRecurring = null,
        public ?bool $taskHasDueDate = null,
        public ?bool $taskHasStartDate = null,
        public ?CarbonInterface $windowStart = null,
        public ?CarbonInterface $windowEnd = null,
        public bool $schoolOnly = false,
        public bool $healthOrHouseholdOnly = false,
        public bool $includeOverdueInWindow = false,
        public bool $examRelatedOnly = false,
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
