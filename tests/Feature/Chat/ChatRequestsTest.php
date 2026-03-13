<?php

use App\Http\Requests\Chat\CreateChatThreadRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\ChatThread;
use App\Models\User;

test('create chat thread request authorizes when user can create threads', function (): void {
    $user = User::factory()->create();

    $request = CreateChatThreadRequest::create('/chat/threads', 'POST');
    $request->setUserResolver(fn () => $user);

    expect($request->authorize())->toBeTrue();
});

test('store chat message request authorizes only for thread owner and not soft deleted', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $thread = ChatThread::query()->create([
        'user_id' => $owner->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $request = StoreChatMessageRequest::create("/chat/threads/{$thread->id}/messages", 'POST');
    $request->setRouteResolver(function () use ($request, $thread) {
        $route = new \Illuminate\Routing\Route('POST', '/chat/threads/{thread}/messages', []);
        $route->bind($request);
        $route->setParameter('thread', $thread);

        return $route;
    });

    $request->setUserResolver(fn () => $owner);
    expect($request->authorize())->toBeTrue();

    $request->setUserResolver(fn () => $otherUser);
    expect($request->authorize())->toBeFalse();

    $thread->delete();
    $request->setUserResolver(fn () => $owner);
    expect($request->authorize())->toBeFalse();
});
