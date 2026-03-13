<?php

namespace App\Http\Controllers;

use App\Enums\ChatMessageRole;
use App\Http\Requests\Chat\CreateChatThreadRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Jobs\ProcessLlmRequestJob;
use App\Models\ChatThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ChatThreadController extends Controller
{
    public function store(CreateChatThreadRequest $request): JsonResponse
    {
        $thread = ChatThread::query()->create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'schema_version' => config('llm.schema_version'),
        ]);

        return response()->json([
            'thread_id' => $thread->id,
        ], 201);
    }

    public function sendMessage(StoreChatMessageRequest $request, ChatThread $thread): JsonResponse
    {
        $traceId = (string) Str::uuid();

        $thread->messages()->create([
            'role' => ChatMessageRole::User,
            'author_id' => $request->user()->id,
            'content_text' => $request->input('message'),
            'client_request_id' => $request->input('client_request_id'),
            'meta' => [
                'trace_id' => $traceId,
            ],
        ]);

        ProcessLlmRequestJob::dispatch(
            user: $request->user(),
            thread: $thread,
            message: $request->input('message'),
            clientRequestId: $request->input('client_request_id'),
            traceId: $traceId,
        );

        return response()->json([
            'status' => 'queued',
            'trace_id' => $traceId,
        ], 202);
    }

    public function messages(ChatThread $thread): JsonResponse
    {
        $this->authorize('view', $thread);

        $messages = $thread->messages()
            ->select([
                'id',
                'role',
                'content_text',
                'meta',
                'created_at',
            ])
            ->get();

        return response()->json($messages);
    }

    public function update(StoreChatMessageRequest $request, ChatThread $thread): JsonResponse
    {
        $this->authorize('update', $thread);

        $thread->update([
            'title' => $request->input('title'),
        ]);

        return response()->json([
            'updated' => true,
        ]);
    }

    public function destroy(ChatThread $thread): JsonResponse
    {
        $this->authorize('delete', $thread);

        $thread->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }
}
