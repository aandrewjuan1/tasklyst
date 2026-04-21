<?php

declare(strict_types=1);

namespace App\Services\LLM\Prism;

use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Ollama\Ollama;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

final class OllamaProxyProvider extends Ollama
{
    public function text(TextRequest $request): TextResponse
    {
        $handler = new OllamaProxyTextHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new OllamaProxyStructuredHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $handler = new OllamaProxyEmbeddingsHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }
}
