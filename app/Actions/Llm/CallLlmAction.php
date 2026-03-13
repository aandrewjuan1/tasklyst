<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmEnvelopeSchema;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\LlmRequestDto;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class CallLlmAction
{
    public function __invoke(LlmRequestDto $request): LlmRawResponseDto
    {
        $start = microtime(true);

        $modelName = config('llm.model');
        $tokens = null;
        $rawText = '';

        try {
            $timeout = (int) config('prism.request_timeout', 60);

            $providerOptions = array_merge([
                'top_p' => config('llm.top_p'),
                'num_ctx' => 4096,
                'keep_alive' => '10m',
            ], $request->options);

            $schema = LlmEnvelopeSchema::make(
                schemaVersion: config('llm.schema_version'),
                allowedTools: config('llm.allowed_tools', []),
            );

            $response = Prism::structured()
                ->using(Provider::Ollama, $modelName)
                ->withSchema($schema)
                ->withSystemPrompt($request->systemPrompt)
                ->withPrompt($request->userPayloadJson)
                ->usingTemperature($request->temperature)
                ->withMaxTokens($request->maxTokens)
                ->withClientOptions(['timeout' => $timeout])
                ->withProviderOptions($providerOptions)
                ->asStructured();

            $structured = $response->structured;

            if (is_array($structured)) {
                $rawText = json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $rawText = $response->text ?? (string) $response;
            }

            $tokens = $response->usage->totalTokens ?? null;
        } catch (\Throwable $e) {
            Log::channel(config('llm.log.channel'))->error('llm.call.failed', [
                'trace_id' => $request->traceId,
                'model' => $modelName,
                'error' => $e->getMessage(),
            ]);

            $rawText = '{"schema_version":"'.config('llm.schema_version').'","intent":"general","data":{},"tool_call":null,"message":"Something went wrong calling the LLM backend.","meta":{"confidence":0.0}}';
        }

        $latency = (microtime(true) - $start) * 1000;

        Log::channel(config('llm.log.channel'))->info('llm.call', [
            'trace_id' => $request->traceId,
            'model' => $modelName,
            'latency_ms' => $latency,
            'tokens' => $tokens,
        ]);

        return new LlmRawResponseDto(
            rawText: $rawText,
            latencyMs: $latency,
            tokensUsed: $tokens,
            modelName: $modelName,
        );
    }
}
