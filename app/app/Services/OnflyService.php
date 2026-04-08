<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OnflyService
{
    // ── Endpoints do agente ──────────────────────────────────────

    /**
     * Detalha uma reserva (GET /bff/booking/show/{id}).
     */
    public function getBooking(string $id): array
    {
        return $this->gateway('GET', "/bff/booking/show/{$id}");
    }

    /**
     * Lista reservas (POST /bff/booking/list).
     */
    public function listBookings(array $filters = []): array
    {
        return $this->gateway('POST', '/bff/booking/list', $filters);
    }

    /**
     * Busca alternativas mais baratas (POST /bff/quote/create) e processa resultados.
     * Quando a API não retorna dados válidos, gera alternativas simuladas para garantir
     * que o agente possa tomar uma decisão mesmo sem dados reais.
     */
    public function searchAlternatives(string $type, array $searchParams, float $currentPrice): array
    {
        $payload = array_merge(['type' => $type], $searchParams);

        // Build the correct nested structure required by /bff/quote/create
        if ($type === 'flight' && !isset($payload['flights'])) {
            $payload = [
                'flights' => [[
                    'origin'        => $searchParams['origin'] ?? $searchParams['originIata'] ?? null,
                    'destination'   => $searchParams['destination'] ?? $searchParams['destinationIata'] ?? null,
                    'departureDate' => $searchParams['date'] ?? $searchParams['departureDate'] ?? null,
                    'adults'        => $searchParams['passengers'] ?? 1,
                ]],
            ];
        }

        $data = $this->gateway('POST', '/bff/quote/create', $payload);

        $items = [];
        $apiFallback = false;

        if (empty($data['error'])) {
            $items = $data['items'] ?? $data['results'] ?? $data['data'] ?? [];
        }

        // Fallback: generate synthetic alternatives if API returned no results
        if (empty($items)) {
            $apiFallback = true;
            $items       = $this->generateFallbackAlternatives($type, $currentPrice, $searchParams);
        }

        usort($items, fn($a, $b) => ($a['price'] ?? PHP_FLOAT_MAX) <=> ($b['price'] ?? PHP_FLOAT_MAX));

        $cheapest = $items[0] ?? null;
        if ($cheapest && isset($cheapest['price'])) {
            $cheapest['savings']            = round($currentPrice - $cheapest['price'], 2);
            $cheapest['savings_percentage'] = round((($currentPrice - $cheapest['price']) / $currentPrice) * 100, 1);
        }

        return [
            'alternatives' => array_slice($items, 0, 5),
            'cheapest'     => $cheapest,
            'api_fallback' => $apiFallback,
        ];
    }

    /**
     * Generates realistic synthetic alternatives when the real API is unavailable.
     * Prices are randomly distributed around the current price (±5–30% cheaper).
     */
    private function generateFallbackAlternatives(string $type, float $currentPrice, array $params): array
    {
        $suppliers = match($type) {
            'flight' => ['LATAM', 'Gol', 'Azul', 'American Airlines', 'Copa Airlines'],
            'hotel'  => ['Ibis', 'Mercure', 'Novotel', 'Holiday Inn', 'Best Western'],
            'car'    => ['Localiza', 'Movida', 'Unidas', 'Hertz', 'Avis'],
            'bus'    => ['Comfortbus', 'Catarinense', 'Itapemirim', 'Buser', 'JBL'],
            default  => ['Opção A', 'Opção B', 'Opção C'],
        };

        $alternatives = [];
        $discounts    = [0.92, 0.85, 0.78, 0.88, 0.95]; // 5–22% cheaper

        foreach (array_slice($suppliers, 0, 4) as $i => $supplier) {
            $price = round($currentPrice * $discounts[$i], 2);
            $alternatives[] = [
                'supplier'    => $supplier,
                'price'       => $price,
                'description' => $this->buildAlternativeDescription($type, $supplier, $params),
                'source'      => 'simulated',
            ];
        }

        return $alternatives;
    }

    private function buildAlternativeDescription(string $type, string $supplier, array $params): string
    {
        return match($type) {
            'flight' => sprintf(
                'Voo %s → %s via %s',
                $params['origin'] ?? 'origem',
                $params['destination'] ?? 'destino',
                $supplier
            ),
            'hotel'  => sprintf('%s — %s noite(s)', $supplier, $params['nights'] ?? 1),
            'car'    => sprintf('Carro %s — %s dia(s)', $supplier, $params['days'] ?? 1),
            'bus'    => sprintf('Ônibus %s → %s via %s',
                $params['origin'] ?? 'origem',
                $params['destination'] ?? 'destino',
                $supplier
            ),
            default  => $supplier,
        };
    }

    // ── Auth: V3 API token ───────────────────────────────────────

    /**
     * Returns a valid V3 Passport access token.
     * Caches the token for 14 min; on refresh, stores the new rotating refresh token.
     */
    private function getApiToken(): string
    {
        if ($cached = Cache::get('onfly_api_token')) {
            return $cached;
        }

        // Read refresh token from cache (updated after each use) or fall back to env
        $refreshToken = Cache::get('onfly_refresh_token', config('agent.onfly.refresh_token', ''));

        if (empty($refreshToken)) {
            Log::error('Onfly: nenhum refresh token disponível');
            return config('agent.onfly.api_token', '');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->post(config('agent.onfly.api_url') . '/oauth/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => config('agent.onfly.client_id'),
                'client_secret' => config('agent.onfly.client_secret'),
            ]);

        if (!$response->successful()) {
            Log::error('Onfly: falha ao renovar API token', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            // Fallback: use stored access token directly
            return config('agent.onfly.api_token', '');
        }

        $data  = $response->json();
        $token = $data['access_token'];

        // Cache access token for 14 min — forces re-exchange with /auth/token/internal
        // which only accepts recently-issued tokens (~15 min window)
        Cache::put('onfly_api_token', $token, 840);

        // Rotating refresh tokens: persist the new one for next cycle
        if (!empty($data['refresh_token'])) {
            Cache::put('onfly_refresh_token', $data['refresh_token'], 86400 * 30);
        }

        return $token;
    }

    // ── Auth: Gateway token ──────────────────────────────────────

    /**
     * Returns a valid gateway (EdDSA) token.
     * Exchanges a fresh V3 token via GET /auth/token/internal.
     * Also persists the returned gateway refresh token for the refresh cycle.
     */
    private function getGatewayToken(): string
    {
        if ($cached = Cache::get('onfly_gateway_token')) {
            return $cached;
        }

        $apiToken = $this->getApiToken();

        $response = Http::withToken($apiToken)
            ->accept('application/prs.onfly.v1+json')
            ->get(config('agent.onfly.api_url') . '/auth/token/internal');

        if (!$response->successful()) {
            Log::error('Onfly: falha ao obter gateway token', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Não foi possível autenticar no gateway da Onfly.');
        }

        $data  = $response->json();
        $token = $data['token'];

        Cache::put('onfly_gateway_token', $token, 840); // 14 min

        // Store gateway refresh token (30-day validity) for mid-cycle refreshes
        if (!empty($data['refreshToken'])) {
            Cache::put('onfly_gateway_refresh_token', $data['refreshToken'], 86400 * 30);
        }

        return $token;
    }

    /**
     * Refreshes the gateway token.
     * Uses GET /bff/auth/refresh?refreshToken=... (requires a still-valid gateway token
     * in the Authorization header — must be called before the 15-min window closes).
     * Falls back to a full V3 → gateway exchange if no refresh token is available.
     */
    private function refreshGatewayToken(): string
    {
        $gatewayRefreshToken = Cache::get('onfly_gateway_refresh_token');
        $currentGateway      = Cache::get('onfly_gateway_token', '');

        if ($gatewayRefreshToken && $currentGateway) {
            $response = Http::withToken($currentGateway)
                ->acceptJson()
                ->get(config('agent.onfly.gateway_url') . '/bff/auth/refresh', [
                    'refreshToken' => $gatewayRefreshToken,
                ]);

            if ($response->successful()) {
                $data  = $response->json();
                $token = $data['token'];
                Cache::put('onfly_gateway_token', $token, 840);
                if (!empty($data['refreshToken'])) {
                    Cache::put('onfly_gateway_refresh_token', $data['refreshToken'], 86400 * 30);
                }
                return $token;
            }
        }

        // Refresh failed or no refresh token — do a full re-exchange via V3
        Cache::forget('onfly_gateway_token');
        Cache::forget('onfly_api_token'); // force fresh V3 token (needed for /auth/token/internal)
        return $this->getGatewayToken();
    }

    // ── Chamada ao gateway ───────────────────────────────────────

    private function gateway(string $method, string $path, array $body = []): array
    {
        try {
            $token    = $this->getGatewayToken();
            $response = $this->gatewayRequest($method, $path, $body, $token);

            // Token expirado — tenta refresh e repete
            if ($response->status() === 401) {
                $token    = $this->refreshGatewayToken();
                $response = $this->gatewayRequest($method, $path, $body, $token);
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::warning("Onfly gateway {$method} {$path} falhou ({$response->status()})", [
                'body' => $response->body(),
            ]);

            return ['error' => true, 'message' => "Gateway retornou {$response->status()}"];
        } catch (\Throwable $e) {
            Log::error("Onfly gateway erro: {$e->getMessage()}");
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function gatewayRequest(string $method, string $path, array $body, string $token)
    {
        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(config('agent.onfly.gateway_url'));

        return match(strtoupper($method)) {
            'POST'  => $http->post($path, $body),
            'PUT'   => $http->put($path, $body),
            'PATCH' => $http->patch($path, $body),
            default => $http->get($path),
        };
    }
}
