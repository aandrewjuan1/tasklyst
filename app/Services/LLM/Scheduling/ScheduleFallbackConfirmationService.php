<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleFallbackConfirmationService
{
    /**
     * @param  array<string, mixed>  $generationData
     * @return array{confirmation_required: bool, data: array<string, mixed>}
     */
    public function finalize(array $generationData, bool $confirmationRequired): array
    {
        return [
            'confirmation_required' => $confirmationRequired,
            'data' => $generationData,
        ];
    }
}
