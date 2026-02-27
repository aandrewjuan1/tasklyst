<?php

use App\Events\AssistantMessageCreated;
use App\Models\User;
use App\Services\AssistantConversationService;
use Illuminate\Support\Facades\Event;

it('broadcasts AssistantMessageCreated when a message is appended', function (): void {
    Event::fake();

    $user = User::factory()->create();

    /** @var AssistantConversationService $service */
    $service = app(AssistantConversationService::class);

    $thread = $service->getOrCreateThread($user);

    $message = $service->appendMessage($thread, 'assistant', 'Hello from the assistant', [
        'foo' => 'bar',
    ]);

    Event::assertDispatched(AssistantMessageCreated::class, function (AssistantMessageCreated $event) use ($message, $user): bool {
        return $event->message->is($message)
            && $event->user->is($user);
    });
});
