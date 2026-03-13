<?php

namespace App\Services\Llm;

use App\Actions\Llm\BuildContextAction;
use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRequestDto;
use App\DataTransferObjects\Ui\RecommendationDisplayDto;
use App\Enums\ChatMessageRole;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LlmChatService
{
    public function __construct(
        private readonly BuildContextAction $contextBuilder,
        private readonly PromptManagerService $promptManager,
        private readonly CallLlmAction $callLlm,
        private readonly PostProcessorService $postProcessor,
        private readonly ToolExecutorService $toolExecutor,
    ) {}

    public function handle(User $user, string $threadId, string $message, ?string $traceId = null): RecommendationDisplayDto
    {
        $traceId ??= (string) Str::uuid();

        try {
            $context = ($this->contextBuilder)($user, $threadId, $message);

            $request = $this->promptManager->buildRequest($message, $context);
            $intentHint = $request->userPayload['intent_hint'] ?? null;
            $chatmlEnabled = (bool) config('llm.prompt.chatml.enabled', false);

            Log::channel(config('llm.log.channel'))->info('llm.request_built', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'intent_hint' => $intentHint,
                'chatml_enabled' => $chatmlEnabled,
            ]);

            $request = new LlmRequestDto(
                systemPrompt: $request->systemPrompt,
                userPayloadJson: $request->userPayloadJson,
                temperature: $request->temperature,
                maxTokens: $request->maxTokens,
                userPayload: $request->userPayload,
                options: $request->options,
                traceId: $traceId,
            );

            $rawResponse = ($this->callLlm)($request);

            $llmResponse = $this->postProcessor->process($rawResponse, $context);

            $toolResult = null;
            if ($llmResponse->hasToolCall()) {
                $toolResult = $this->toolExecutor->execute($llmResponse->toolCall, $user);
            }

            $finalMessage = $llmResponse->message;
            if ($toolResult?->success) {
                try {
                    $followUp = $this->promptManager->buildToolFollowUpRequest($llmResponse->toolCall, $toolResult, $context);
                    $followRaw = ($this->callLlm)($followUp);
                    $followParsed = json_decode($followRaw->rawText, true);
                    $finalMessage = $followParsed['message'] ?? $finalMessage;
                } catch (\Throwable) {
                }
            }

            ChatMessage::create([
                'thread_id' => $threadId,
                'role' => ChatMessageRole::Assistant,
                'content_text' => $finalMessage,
                'content_json' => [
                    'intent' => $llmResponse->intent->value,
                    'data' => $llmResponse->data,
                    'tool_call' => $llmResponse->toolCall ? [
                        'tool' => $llmResponse->toolCall->tool,
                        'args' => $llmResponse->toolCall->args,
                    ] : null,
                    'tool_result' => $toolResult?->toArray(),
                ],
                'meta' => [
                    'confidence' => $llmResponse->confidence,
                    'trace_id' => $traceId,
                    'latency_ms' => $rawResponse->latencyMs,
                    'tokens' => $rawResponse->tokensUsed,
                    'intent_hint' => $intentHint,
                    'chatml_enabled' => $chatmlEnabled,
                ],
            ]);

            return new RecommendationDisplayDto(
                primaryMessage: $finalMessage,
                cards: $this->buildCards($llmResponse->data, $llmResponse->intent),
                actions: $this->buildActions($llmResponse, $toolResult),
                isError: false,
                traceId: $traceId,
            );
        } catch (LlmValidationException|LlmSchemaVersionException $e) {
            Log::channel(config('llm.log.channel'))->warning('llm.validation_error', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->persistErrorMessage($threadId, $traceId);

            return new RecommendationDisplayDto(
                primaryMessage: "I couldn't understand that. Please try rephrasing.",
                isError: true,
                traceId: $traceId,
            );
        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->error('llm.unexpected_error', [
                'trace_id' => $traceId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->persistErrorMessage($threadId, $traceId);

            return new RecommendationDisplayDto(
                primaryMessage: 'Something went wrong. Please try again.',
                isError: true,
                traceId: $traceId,
            );
        }
    }

    private function persistErrorMessage(string $threadId, string $traceId): void
    {
        ChatMessage::create([
            'thread_id' => $threadId,
            'role' => ChatMessageRole::Assistant,
            'content_text' => "Sorry, I couldn't process that request.",
            'meta' => ['error' => true, 'trace_id' => $traceId],
        ]);
    }

    private function buildCards(array $data, \App\Enums\LlmIntent $intent): array
    {
        return [];
    }

    private function buildActions(\App\DataTransferObjects\Llm\LlmResponseDto $response, ?\App\DataTransferObjects\Llm\ToolResultDto $toolResult): array
    {
        return [];
    }
}
