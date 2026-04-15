<?php

use App\Models\User;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;

test('authenticated users can run the llm prompt test route', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'reply' => 'Prism reached Ollama successfully.',
            ]),
    ]);

    /** @var User&\Illuminate\Contracts\Auth\Authenticatable $user */
    $user = User::factory()->createOne();

    $response = $this->actingAs($user)->get('/llm/prompt-test?prompt=Say%20hello');

    $response->assertSuccessful();
    $response->assertJson([
        'ok' => true,
        'provider' => 'ollama',
        'prompt' => 'Say hello',
        'reply' => 'Prism reached Ollama successfully.',
    ]);
});

test('llm prompt test route validates prompt query parameter', function (): void {
    /** @var User&\Illuminate\Contracts\Auth\Authenticatable $user */
    $user = User::factory()->createOne();

    $response = $this->actingAs($user)->get('/llm/prompt-test');

    $response->assertUnprocessable();
    $response->assertJson([
        'ok' => false,
        'error' => 'prompt_required',
    ]);
});
