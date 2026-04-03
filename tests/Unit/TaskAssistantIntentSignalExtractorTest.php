<?php

use App\Services\LLM\Intent\TaskAssistantIntentSignalExtractor;

test('student colloquial prioritize phrasing yields strong prioritization signal', function (): void {
    $extractor = new TaskAssistantIntentSignalExtractor;
    $signals = $extractor->extract('idk what to do first i have so much homework due');

    expect($signals['prioritization'])->toBeGreaterThan(0.7);
});

test('informal scheduling phrasing yields strong scheduling signal', function (): void {
    $extractor = new TaskAssistantIntentSignalExtractor;
    $signals = $extractor->extract('can u help me fit my stuff in after school tmrw afternoon');

    expect($signals['scheduling'])->toBeGreaterThan(0.7);
});

test('rank and urgency cues boost prioritization without requiring the word task', function (): void {
    $extractor = new TaskAssistantIntentSignalExtractor;
    $signals = $extractor->extract('rank these by deadline im drowning');

    expect($signals['prioritization'])->toBeGreaterThan(0.65);
});

test('time-finding phrasing boosts scheduling signal', function (): void {
    $extractor = new TaskAssistantIntentSignalExtractor;
    $signals = $extractor->extract('when can i block time for this between classes tmrw');

    expect($signals['scheduling'])->toBeGreaterThan(0.65);
});
