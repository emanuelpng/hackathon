<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use Illuminate\Support\ServiceProvider;

class AnthropicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $apiKey = config('agent.anthropic.api_key', '');

            if (empty($apiKey)) {
                throw new \RuntimeException('ANTHROPIC_API_KEY não configurada no .env');
            }

            return new Client(apiKey: $apiKey);
        });
    }
}
