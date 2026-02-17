<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WorkOS\UserManagement;

class WorkOSWarmJwkCommand extends Command
{
    /**
     * Cache key used by Laravel WorkOS when decoding access tokens.
     * Pre-warming this key avoids an HTTP request to WorkOS on the first user request.
     *
     * @var string
     */
    private const JWK_CACHE_KEY = 'workos:jwk';

    protected $signature = 'workos:warm-jwk
                            {--hours=24 : Number of hours to cache the JWK set}';

    protected $description = 'Pre-warm the WorkOS JWK cache to avoid HTTP calls on first request';

    public function handle(): int
    {
        $clientId = config('services.workos.client_id');

        if (empty($clientId)) {
            $this->error('WorkOS client_id is not configured. Set WORKOS_CLIENT_ID in .env.');

            return self::FAILURE;
        }

        $url = (new UserManagement)->getJwksUrl($clientId);
        $hours = (int) $this->option('hours');

        $this->info("Fetching JWK set from WorkOS (caching for {$hours} hours)...");

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                $this->error("WorkOS JWKS request failed: HTTP {$response->status()}");

                return self::FAILURE;
            }

            $jwk = $response->json();
            Cache::put(self::JWK_CACHE_KEY, $jwk, now()->addHours($hours));

            $this->info('JWK cache warmed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to warm JWK cache: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
