<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $promptManagerService = 'App\\Services\\Llm\\PromptManagerService';
        $postProcessorService = 'App\\Services\\Llm\\PostProcessorService';
        $llmChatService = 'App\\Services\\Llm\\LlmChatService';

        $retryRepairAction = 'App\\Actions\\Llm\\RetryRepairAction';
        $buildContextAction = 'App\\Actions\\Llm\\BuildContextAction';
        $callLlmAction = 'App\\Actions\\Llm\\CallLlmAction';
        $toolExecutorService = 'App\\Services\\Llm\\ToolExecutorService';

        $this->app->singleton($promptManagerService, fn ($app) => new ($promptManagerService)(
            schemaVersion: config('llm.schema_version'),
            timezone: config('llm.timezone'),
            allowedTools: config('llm.allowed_tools'),
        ));

        $this->app->singleton($postProcessorService, fn ($app) => new ($postProcessorService)(
            schemaVersion: config('llm.schema_version'),
            confidenceLow: config('llm.confidence.low_threshold'),
            repairAction: $app->make($retryRepairAction),
        ));

        $this->app->bind($llmChatService, fn ($app) => new ($llmChatService)(
            contextBuilder: $app->make($buildContextAction),
            promptManager: $app->make($promptManagerService),
            callLlm: $app->make($callLlmAction),
            postProcessor: $app->make($postProcessorService),
            toolExecutor: $app->make($toolExecutorService),
        ));
    }
}
