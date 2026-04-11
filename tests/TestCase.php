<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Config cache (e.g. from local `config:cache`) can bake `app.env` => `local` and `broadcasting.default`
        // => `reverb`. That makes `Application::runningUnitTests()` false and forces middleware such as
        // `ValidateWorkOSSession` to hit WorkOS / redirect. Force a consistent testing environment.
        $this->app->instance('env', 'testing');

        config([
            'app.env' => 'testing',
            'broadcasting.default' => 'null',
        ]);
    }
}
