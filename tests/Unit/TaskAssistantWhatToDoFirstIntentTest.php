<?php

use App\Support\LLM\TaskAssistantWhatToDoFirstIntent;

test('matches what task should i do first and classic prioritize first phrases', function (): void {
    expect(TaskAssistantWhatToDoFirstIntent::matches('yo what task should i do first right now'))->toBeTrue();
    expect(TaskAssistantWhatToDoFirstIntent::matches('What should I do first?'))->toBeTrue();
    expect(TaskAssistantWhatToDoFirstIntent::matches('where should I start'))->toBeTrue();
});

test('matches plural top tasks prioritize asks for short circuit', function (): void {
    expect(TaskAssistantWhatToDoFirstIntent::matches('what top tasks should i do first'))->toBeTrue();
    expect(TaskAssistantWhatToDoFirstIntent::matches('what are the top tasks that i should do first'))->toBeTrue();
    expect(TaskAssistantWhatToDoFirstIntent::matches('which tasks should i do first'))->toBeTrue();
});

test('implies multiple prioritized rows without bare do first substring', function (): void {
    expect(TaskAssistantWhatToDoFirstIntent::impliesMultiplePrioritizedRows('what top tasks should i do first'))->toBeTrue();
    expect(TaskAssistantWhatToDoFirstIntent::impliesMultiplePrioritizedRows('in my tasks what should i do first'))->toBeFalse();
});

test('does not match list or inventory style prompts', function (): void {
    expect(TaskAssistantWhatToDoFirstIntent::matches('what tasks do I have'))->toBeFalse();
    expect(TaskAssistantWhatToDoFirstIntent::matches('list my tasks'))->toBeFalse();
});

test('bare do first alone is not enough to match prioritize first family', function (): void {
    expect(TaskAssistantWhatToDoFirstIntent::matches('do first thing on my mind'))->toBeFalse();
});
