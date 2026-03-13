<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\LlmRequestDto;
use Illuminate\Support\Facades\Log;

class CallLlmAction
{
    public function __invoke(LlmRequestDto $request): LlmRawResponseDto
    {
        $start = microtime(true);

        $rawText = '{"schema_version":"'.config('llm.schema_version').'","intent":"general","data":{},"tool_call":null,"message":"PrismPHP not wired yet.","meta":{"confidence":1.0}}';
        $tokens = null;

        $latency = (microtime(true) - $start) * 1000;

        Log::channel(config('llm.log.channel'))->info('llm.call', [
            'trace_id' => $request->traceId,
            'model' => config('llm.model'),
            'latency_ms' => $latency,
            'tokens' => $tokens,
        ]);

        return new LlmRawResponseDto(
            rawText: $rawText,
            latencyMs: $latency,
            tokensUsed: $tokens,
            modelName: config('llm.model'),
        );
    }
}
