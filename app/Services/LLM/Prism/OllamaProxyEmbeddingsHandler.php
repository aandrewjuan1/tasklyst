<?php

declare(strict_types=1);

namespace App\Services\LLM\Prism;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

final class OllamaProxyEmbeddingsHandler
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): EmbeddingsResponse
    {
        $response = $this->sendRequest($request);
        $data = $response->json();

        if (! $data || data_get($data, 'error')) {
            throw PrismException::providerResponseError(sprintf(
                'Ollama Error: %s',
                data_get($data, 'error', 'unknown'),
            ));
        }

        return new EmbeddingsResponse(
            embeddings: array_map(Embedding::fromArray(...), data_get($data, 'embeddings', [])),
            usage: new EmbeddingsUsage(data_get($data, 'prompt_eval_count')),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client->post('', Arr::whereNotNull([
            'model' => $request->model(),
            'input' => $request->inputs(),
            'keep_alive' => $request->providerOptions('keep_alive'),
            'options' => $request->providerOptions() ?: null,
        ]));

        return $response;
    }
}
