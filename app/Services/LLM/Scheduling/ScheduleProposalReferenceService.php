<?php

namespace App\Services\LLM\Scheduling;

use App\Support\LLM\SchedulableProposalPolicy;

final class ScheduleProposalReferenceService
{
    /**
     * @param  list<array<string, mixed>>  $proposals
     * @return list<string>
     */
    public function collectReferencedPendingSchedulableUuids(array $proposals): array
    {
        return SchedulableProposalPolicy::referencedPendingUuids($proposals);
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    public function hasPendingSchedulableProposal(array $proposals): bool
    {
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            if (SchedulableProposalPolicy::isPendingSchedulable($proposal)) {
                return true;
            }
        }

        return false;
    }
}
