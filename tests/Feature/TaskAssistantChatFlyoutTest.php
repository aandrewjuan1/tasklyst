<?php

use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

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
