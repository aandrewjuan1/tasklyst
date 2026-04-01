<?php

use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;

it('returns low confidence ambiguity for pronoun-only refinements', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('move it to 8 pm', [
        ['proposal_id' => 'a', 'proposal_uuid' => 'uuid-a', 'title' => 'Write report'],
        ['proposal_id' => 'b', 'proposal_uuid' => 'uuid-b', 'title' => 'Review backlog'],
    ]);

    expect($result['ambiguous'])->toBeTrue();
    expect($result['confidence'])->toBe('low');
    expect($result['candidate_titles'])->toHaveCount(2);
});
