<?php

use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('authenticated user sees task assistant flyout trigger and can open chat', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $response = $this->get(route('workspace'));
    $response->assertSuccessful();
    $response->assertSee('Assistant', false);
});

test('chat flyout component dispatches job on submit', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('newMessage', '')
        ->assertSet('isStreaming', false)
        ->set('newMessage', 'Hello')
        ->call('submitMessage')
        ->assertSet('newMessage', '')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class, function (BroadcastTaskAssistantStreamJob $job) use ($user) {
        return $job->userId === $user->id;
    });
});

test('chat flyout routes to structured task_choice without dispatching job', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'Structured task choice summary.',
                'reason' => 'Because it is sensible.',
                'suggested_next_steps' => ['Step 1'],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Help me choose what to work on next')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout routes to mutating flow without dispatching job', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'action' => 'list_tasks',
                'args' => ['limit' => 5],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'List my tasks')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
});
