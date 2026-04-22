<?php

namespace App\Providers;

use App\Models\DatabaseNotification;
use App\Observers\DatabaseNotificationObserver;
use App\Services\LLM\Prism\OllamaProxyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Prism\Prism\PrismManager;
use WorkOS\WorkOS as WorkOSSDK;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->afterResolving(PrismManager::class, function (PrismManager $manager): void {
            $manager->extend('ollama', function ($app, array $config): OllamaProxyProvider {
                return new OllamaProxyProvider(
                    apiKey: $config['api_key'] ?? '',
                    url: $config['url'] ?? '',
                );
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWorkOS();

        DatabaseNotification::observe(DatabaseNotificationObserver::class);
    }

    protected function configureWorkOS(): void
    {
        $apiKey = config('services.workos.secret');
        $clientId = config('services.workos.client_id');

        if ($apiKey) {
            WorkOSSDK::setApiKey($apiKey);
        }
        if ($clientId) {
            WorkOSSDK::setClientId($clientId);
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
