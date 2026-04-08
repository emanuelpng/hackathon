<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiCallLog;
use App\Models\OnflyToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Tenta buscar a reserva real; se não encontrada, retorna um mock realista
     * construído a partir dos dados fornecidos pelo chamador.
     * Retorna também um flag 'mocked' para rastreabilidade.
     */
    public function getBookingOrMock(string $id, string $type, array $data): array
    {
        $result = $this->getBooking($id);

        if (empty($result['error'])) {
            return array_merge($result, ['mocked' => false]);
        }

        Log::info("OnflyService: reserva {$id} não encontrada, usando mock", [
            'api_error' => $result['message'] ?? null,
        ]);

        return array_merge(
            $this->generateMockBooking($id, $type, $data),
            ['mocked' => true]
        );
    }

    // ── Mock booking generator ───────────────────────────────────

    private function generateMockBooking(string $id, string $type, array $data): array
    {
        return match ($type) {
            'flight' => $this->mockFlight($id, $data),
            'hotel'  => $this->mockHotel($id, $data),
            'car'    => $this->mockCar($id, $data),
            'bus'    => $this->mockBus($id, $data),
            default  => array_merge(['id' => $id, 'type' => $type, 'source' => 'mock'], $data),
        };
    }

    private function mockFlight(string $id, array $d): array
    {
        $airlineMap = [
            'LATAM'            => ['code' => 'LA', 'name' => 'LATAM Airlines'],
            'Gol'              => ['code' => 'G3', 'name' => 'Gol Linhas Aéreas'],
            'Azul'             => ['code' => 'AD', 'name' => 'Azul Linhas Aéreas'],
            'American Airlines'=> ['code' => 'AA', 'name' => 'American Airlines'],
            'Copa Airlines'    => ['code' => 'CM', 'name' => 'Copa Airlines'],
        ];
        $airportMap = [
            'GRU' => ['code' => 'GRU', 'city' => 'São Paulo',      'name' => 'Guarulhos Internacional'],
            'GIG' => ['code' => 'GIG', 'city' => 'Rio de Janeiro', 'name' => 'Galeão Internacional'],
            'CGH' => ['code' => 'CGH', 'city' => 'São Paulo',      'name' => 'Congonhas'],
            'SDU' => ['code' => 'SDU', 'city' => 'Rio de Janeiro', 'name' => 'Santos Dumont'],
            'BSB' => ['code' => 'BSB', 'city' => 'Brasília',       'name' => 'Presidente JK'],
            'SSA' => ['code' => 'SSA', 'city' => 'Salvador',       'name' => 'Deputado Luís Eduardo Magalhães'],
            'FOR' => ['code' => 'FOR', 'city' => 'Fortaleza',      'name' => 'Pinto Martins'],
            'REC' => ['code' => 'REC', 'city' => 'Recife',         'name' => 'Guararapes - Gilberto Freyre'],
            'POA' => ['code' => 'POA', 'city' => 'Porto Alegre',   'name' => 'Salgado Filho'],
            'CWB' => ['code' => 'CWB', 'city' => 'Curitiba',       'name' => 'Afonso Pena'],
        ];

        $airlineName = $d['airline'] ?? 'LATAM';
        $airline     = $airlineMap[$airlineName] ?? ['code' => strtoupper(substr($airlineName, 0, 2)), 'name' => $airlineName];
        $fromCode    = strtoupper($d['origin'] ?? $d['from'] ?? 'GRU');
        $toCode      = strtoupper($d['destination'] ?? $d['to'] ?? 'GIG');
        $from        = $airportMap[$fromCode] ?? ['code' => $fromCode, 'city' => $fromCode, 'name' => $fromCode];
        $to          = $airportMap[$toCode]   ?? ['code' => $toCode,   'city' => $toCode,   'name' => $toCode];
        $date        = $d['date'] ?? $d['departure'] ?? now()->addDays(7)->format('Y-m-d');
        $price       = (float) ($d['price'] ?? 0);

        return [
            'id'            => $id,
            'type'          => 'flight',
            'status'        => 'confirmed',
            'source'        => 'mock',
            'price'         => $price,
            'airline'       => $airline,
            'from'          => $from,
            'to'            => $to,
            'departure'     => $date . 'T' . ($d['departure_time'] ?? '08:00:00'),
            'arrival'       => $date . 'T' . ($d['arrival_time']   ?? '09:30:00'),
            'flightNumber'  => $airline['code'] . rand(1000, 9999),
            'cabinClass'    => $d['cabinClass'] ?? $d['cabin_class'] ?? 'economy',
            'stops'         => (int) ($d['stops'] ?? 0),
            'passenger'     => ['id' => config('agent.onfly.traveler_id', '572178'), 'name' => 'Viajante Corporativo'],
            'priceBreakdown'=> ['fare' => round($price * 0.85, 2), 'taxes' => round($price * 0.15, 2), 'total' => $price],
        ];
    }

    private function mockHotel(string $id, array $d): array
    {
        $price      = (float) ($d['price'] ?? 0);
        $checkin    = $d['checkin']  ?? $d['checkIn']  ?? now()->addDays(7)->format('Y-m-d');
        $checkout   = $d['checkout'] ?? $d['checkOut'] ?? now()->addDays(9)->format('Y-m-d');
        $nights     = (int) ($d['nights'] ?? max(1, (int) ((strtotime($checkout) - strtotime($checkin)) / 86400)));

        return [
            'id'          => $id,
            'type'        => 'hotel',
            'status'      => 'confirmed',
            'source'      => 'mock',
            'price'       => $price,
            'pricePerNight' => $nights > 0 ? round($price / $nights, 2) : $price,
            'nights'      => $nights,
            'hotel'       => [
                'name'     => $d['hotel'] ?? $d['name'] ?? 'Hotel Corporativo',
                'category' => $d['category'] ?? '4 estrelas',
                'address'  => $d['address'] ?? null,
            ],
            'destination' => $d['destination'] ?? $d['city'] ?? null,
            'checkIn'     => $checkin,
            'checkOut'    => $checkout,
            'roomType'    => $d['room_type'] ?? $d['roomType'] ?? 'Standard',
            'guest'       => ['id' => config('agent.onfly.traveler_id', '572178'), 'name' => 'Viajante Corporativo'],
        ];
    }

    private function mockCar(string $id, array $d): array
    {
        $price    = (float) ($d['price'] ?? 0);
        $days     = (int) ($d['days'] ?? 1);

        return [
            'id'          => $id,
            'type'        => 'car',
            'status'      => 'confirmed',
            'source'      => 'mock',
            'price'       => $price,
            'pricePerDay' => $days > 0 ? round($price / $days, 2) : $price,
            'days'        => $days,
            'rental'      => [
                'company'  => $d['supplier'] ?? $d['company'] ?? 'Localiza',
                'category' => $d['category'] ?? 'Intermediário',
            ],
            'pickUp'      => ['location' => $d['origin'] ?? $d['location'] ?? null, 'date' => $d['date'] ?? null],
            'dropOff'     => ['location' => $d['destination'] ?? $d['location'] ?? null, 'date' => $d['return_date'] ?? null],
            'driver'      => ['id' => config('agent.onfly.traveler_id', '572178'), 'name' => 'Viajante Corporativo'],
        ];
    }

    private function mockBus(string $id, array $d): array
    {
        $price = (float) ($d['price'] ?? 0);

        return [
            'id'         => $id,
            'type'       => 'bus',
            'status'     => 'confirmed',
            'source'     => 'mock',
            'price'      => $price,
            'company'    => $d['company'] ?? $d['supplier'] ?? 'Comfortbus',
            'from'       => $d['origin']      ?? $d['from']        ?? null,
            'to'         => $d['destination'] ?? $d['to']          ?? null,
            'departure'  => ($d['date'] ?? now()->addDays(7)->format('Y-m-d')) . 'T' . ($d['departure_time'] ?? '08:00:00'),
            'arrival'    => ($d['date'] ?? now()->addDays(7)->format('Y-m-d')) . 'T' . ($d['arrival_time']   ?? '14:00:00'),
            'seatClass'  => $d['seat_class'] ?? 'executivo',
            'passenger'  => ['id' => config('agent.onfly.traveler_id', '572178'), 'name' => 'Viajante Corporativo'],
        ];
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
            $travelerId = Cache::get('onfly_traveler_id', config('agent.onfly.traveler_id', '572178'));
            $birthday   = Cache::get('onfly_traveler_birthday', config('agent.onfly.traveler_birthday', '1990-01-01'));

            $payload = [
                'flights' => [[
                    'from'       => $searchParams['origin'] ?? $searchParams['from'] ?? null,
                    'to'         => $searchParams['destination'] ?? $searchParams['to'] ?? null,
                    'departure'  => $searchParams['date'] ?? $searchParams['departure'] ?? null,
                    'cabinClass' => $searchParams['cabinClass'] ?? 'economy',
                    'travelers'  => [['id' => $travelerId, 'birthday' => $birthday]],
                ]],
            ];
        } elseif ($type === 'hotel' && !isset($payload['hotels'])) {
            $travelerId = Cache::get('onfly_traveler_id', config('agent.onfly.traveler_id', '572178'));
            $birthday   = Cache::get('onfly_traveler_birthday', config('agent.onfly.traveler_birthday', '1990-01-01'));

            $payload = [
                'hotels' => [[
                    'destination' => $searchParams['destination'] ?? $searchParams['city'] ?? null,
                    'checkIn'     => $searchParams['checkin'] ?? $searchParams['checkIn'] ?? null,
                    'checkOut'    => $searchParams['checkout'] ?? $searchParams['checkOut'] ?? null,
                    'travelers'   => [['id' => $travelerId, 'birthday' => $birthday]],
                ]],
            ];
        }

        $data = $this->gateway('POST', '/bff/quote/create', $payload);

        $items = [];
        $apiFallback = false;

        if (empty($data['error'])) {
            // Real BFF response: [{id, item, response: {data: [flights...]}}]
            if (is_array($data) && isset($data[0]['response']['data'])) {
                $flightList = $data[0]['response']['data'] ?? [];
                foreach ($flightList as $flight) {
                    $rawPrice = $flight['cheapestTotalPrice'] ?? $flight['cheapestPrice'] ?? null;
                    if ($rawPrice === null) continue;
                    $price = round((float) $rawPrice / 100, 2); // API returns cents
                    $items[] = [
                        'price'       => $price,
                        'supplier'    => (string) ($flight['ciaManaging']['name'] ?? $flight['ciaManaging']['code'] ?? 'Unknown'),
                        'description' => sprintf(
                            'Voo %s → %s | %s %s | %s parada(s)',
                            (string) ($flight['from']['city']['name']   ?? ($flight['from']['code'] ?? 'GRU')),
                            (string) ($flight['to']['city']['name']     ?? ($flight['to']['code'] ?? 'GIG')),
                            (string) ($flight['ciaManaging']['code']    ?? ''),
                            (string) ($flight['flightNumber']           ?? ''),
                            (int)    ($flight['stops']                  ?? 0)
                        ),
                        'departure'   => $flight['departure']  ?? null,
                        'arrival'     => $flight['arrival']    ?? null,
                        'source'      => 'production',
                    ];
                }
            } else {
                $items = $data['items'] ?? $data['results'] ?? $data['data'] ?? [];
            }
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

        // Read refresh token: cache → DB (survives cache:clear) → env
        $refreshToken = Cache::get('onfly_refresh_token')
            ?? $this->dbTokenGet('refresh_token')
            ?? config('agent.onfly.refresh_token', '');

        if (empty($refreshToken)) {
            Log::error('Onfly: nenhum refresh token disponível');
            $this->markAuthBroken('no_refresh_token', 'Nenhum refresh token encontrado. Execute get_tokens.js para autenticar.');
            return config('agent.onfly.api_token', '');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withOptions($this->proxyOptions())
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
            $body = $response->json();
            $msg  = $body['message'] ?? $response->body();
            $this->markAuthBroken('refresh_token_rejected', "Refresh token rejeitado: {$msg}");
            // Fallback: use stored access token directly
            return config('agent.onfly.api_token', '');
        }

        $data  = $response->json();
        $token = $data['access_token'];

        // Cache access token for 14 min — forces re-exchange with /auth/token/internal
        // which only accepts recently-issued tokens (~15 min window)
        Cache::put('onfly_api_token', $token, 840);

        // Rotating refresh tokens: persist in both cache AND DB so cache:clear doesn't break auth
        if (!empty($data['refresh_token'])) {
            Cache::put('onfly_refresh_token', $data['refresh_token'], 86400 * 30);
            $this->dbTokenPut('refresh_token', $data['refresh_token']);
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
            ->withOptions($this->proxyOptions())
            ->get(config('agent.onfly.api_url') . '/auth/token/internal');

        if (!$response->successful()) {
            Log::error('Onfly: falha ao obter gateway token', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $body = $response->json();
            $msg  = $body['message'] ?? $response->body();
            $this->markAuthBroken('gateway_auth_failed', "Gateway rejeitou autenticação: {$msg}");
            throw new \RuntimeException('Não foi possível autenticar no gateway da Onfly.');
        }

        $data  = $response->json();
        $token = $data['token'];

        // Auth is healthy — clear any previous broken status
        $this->markAuthHealthy();

        Cache::put('onfly_gateway_token', $token, 840); // 14 min

        if (!empty($data['refreshToken'])) {
            Cache::put('onfly_gateway_refresh_token', $data['refreshToken'], 86400 * 30);
        }

        // Extract traveler id from gateway token scopes for quote searches
        try {
            $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
            $userId  = $payload['user_id'] ?? $payload['scopes']['User']['id'] ?? null;
            if ($userId) {
                Cache::put('onfly_traveler_id', (string) $userId, 86400 * 30);
            }
        } catch (\Throwable) {}

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
        $start = microtime(true);
        try {
            $token    = $this->getGatewayToken();
            $response = $this->gatewayRequest($method, $path, $body, $token);

            // Token expirado — tenta refresh e repete
            if ($response->status() === 401) {
                $token    = $this->refreshGatewayToken();
                $response = $this->gatewayRequest($method, $path, $body, $token);
            }

            $duration = (int) ((microtime(true) - $start) * 1000);
            $json     = $response->json();

            $this->logApiCall($method, $path, $body, $response->status(), $json, $response->body(), $response->successful(), $duration);

            if ($response->successful()) {
                return $json ?? [];
            }

            Log::warning("Onfly gateway {$method} {$path} falhou ({$response->status()})", [
                'body' => $response->body(),
            ]);

            return ['error' => true, 'message' => "Gateway retornou {$response->status()}"];
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            Log::error("Onfly gateway erro: {$e->getMessage()}");
            $this->logApiCall($method, $path, $body, null, null, $e->getMessage(), false, $duration);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    private function logApiCall(string $method, string $endpoint, array $payload, ?int $status, ?array $jsonResponse, ?string $rawResponse, bool $success, int $durationMs): void
    {
        try {
            ApiCallLog::create([
                'service'          => 'onfly',
                'method'           => strtoupper($method),
                'endpoint'         => $endpoint,
                'status_code'      => $status,
                'request_payload'  => empty($payload) ? null : $payload,
                'response_body'    => $jsonResponse,
                'response_raw'     => $jsonResponse ? null : $rawResponse,
                'success'          => $success,
                'duration_ms'      => $durationMs,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ApiCallLog: falha ao salvar: ' . $e->getMessage());
        }
    }

    private function gatewayRequest(string $method, string $path, array $body, string $token)
    {
        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(config('agent.onfly.gateway_url'))
            ->withOptions($this->proxyOptions());

        return match(strtoupper($method)) {
            'POST'  => $http->post($path, $body),
            'PUT'   => $http->put($path, $body),
            'PATCH' => $http->patch($path, $body),
            default => $http->get($path),
        };
    }

    // ── Auth status ─────────────────────────────────────────────

    /**
     * Returns the current auth health status for display in the dashboard.
     */
    public function getAuthStatus(): array
    {
        $status = Cache::get('onfly_auth_status', [
            'healthy'    => true,
            'error'      => null,
            'reason'     => null,
            'broken_at'  => null,
        ]);

        // Also check if we have any token at all
        $hasRefreshToken = Cache::get('onfly_refresh_token')
            || $this->dbTokenGet('refresh_token')
            || !empty(config('agent.onfly.refresh_token'));

        if (!$hasRefreshToken && $status['healthy']) {
            return [
                'healthy'   => false,
                'error'     => 'no_refresh_token',
                'reason'    => 'Nenhum refresh token encontrado. Execute get_tokens.js para autenticar.',
                'broken_at' => null,
            ];
        }

        return $status;
    }

    private function markAuthBroken(string $error, string $reason): void
    {
        Cache::put('onfly_auth_status', [
            'healthy'   => false,
            'error'     => $error,
            'reason'    => $reason,
            'broken_at' => now()->toDateTimeString(),
        ], 86400);
    }

    private function markAuthHealthy(): void
    {
        Cache::put('onfly_auth_status', [
            'healthy'   => true,
            'error'     => null,
            'reason'    => null,
            'broken_at' => null,
        ], 86400);
    }

    // ── OAuth login flow ─────────────────────────────────────────

    /**
     * Returns the Onfly OAuth authorization URL.
     * The user should be redirected here to start the login flow.
     */
    public function oauthAuthorizeUrl(string $redirectUri, string $state): string
    {
        $appUrl = 'https://app.onfly.com';
        return $appUrl . '/v2#/auth/oauth/authorize'
            . '?client_id=' . urlencode(config('agent.onfly.client_id'))
            . '&redirect_uri=' . urlencode($redirectUri)
            . '&response_type=code'
            . '&state=' . urlencode($state);
    }

    /**
     * Exchanges an OAuth authorization code for tokens and persists them.
     * Called from the OAuth callback route.
     */
    public function exchangeOAuthCode(string $code, string $redirectUri): void
    {
        // 1. Exchange authorization code for V3 access + refresh tokens
        $tokenResp = Http::asJson()
            ->acceptJson()
            ->post(config('agent.onfly.api_url') . '/oauth/token', [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => config('agent.onfly.client_id'),
                'client_secret' => config('agent.onfly.client_secret'),
                'redirect_uri'  => $redirectUri,
            ]);

        if (!$tokenResp->successful()) {
            $msg = $tokenResp->json()['message'] ?? $tokenResp->body();
            throw new \RuntimeException("Falha ao trocar código OAuth: {$msg}");
        }

        $tokenData    = $tokenResp->json();
        $accessToken  = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;

        // 2. Exchange V3 access token for gateway token
        $gwResp = Http::withToken($accessToken)
            ->accept('application/prs.onfly.v1+json')
            ->get(config('agent.onfly.api_url') . '/auth/token/internal');

        if (!$gwResp->successful()) {
            $msg = $gwResp->json()['message'] ?? $gwResp->body();
            throw new \RuntimeException("Falha ao obter gateway token: {$msg}");
        }

        $gwData               = $gwResp->json();
        $gatewayRefreshToken  = $gwData['refreshToken'] ?? null;

        // 3. Persist all tokens in DB (survive cache:clear and deploys)
        $this->dbTokenPut('api_token', $accessToken);
        if ($refreshToken) {
            $this->dbTokenPut('refresh_token', $refreshToken);
            Cache::put('onfly_refresh_token', $refreshToken, 86400 * 30);
        }
        if ($gatewayRefreshToken) {
            $this->dbTokenPut('gateway_refresh_token', $gatewayRefreshToken);
        }

        // 4. Clear stale cached tokens so next request gets fresh ones
        Cache::forget('onfly_api_token');
        Cache::forget('onfly_gateway_token');
        Cache::forget('onfly_gateway_refresh_token');

        // 5. Mark auth as healthy
        $this->markAuthHealthy();

        Log::info('Onfly OAuth: autenticação concluída com sucesso via browser.');
    }

    // ── DB-backed token persistence ──────────────────────────────

    /**
     * Reads a token value from the onfly_tokens table.
     * Returns null if the key doesn't exist or has expired.
     */
    private function dbTokenGet(string $key): ?string
    {
        try {
            $record = OnflyToken::find($key);
            if (!$record) {
                return null;
            }
            if ($record->expires_at && $record->expires_at->isPast()) {
                $record->delete();
                return null;
            }
            return $record->value;
        } catch (\Throwable $e) {
            Log::warning("OnflyToken: falha ao ler '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Writes a token value to the onfly_tokens table (upsert).
     * $ttl is in seconds; null means no expiry.
     */
    private function dbTokenPut(string $key, string $value, ?int $ttl = null): void
    {
        try {
            OnflyToken::updateOrCreate(
                ['key' => $key],
                [
                    'value'      => $value,
                    'expires_at' => $ttl ? now()->addSeconds($ttl) : null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("OnflyToken: falha ao salvar '{$key}': " . $e->getMessage());
        }
    }

    /**
     * Builds Guzzle proxy options from environment variables.
     * Guzzle does not auto-read HTTPS_PROXY in PHP-FPM/artisan-serve contexts.
     */
    private function proxyOptions(): array
    {
        $proxy = env('HTTPS_PROXY') ?: env('HTTP_PROXY');
        if (!$proxy) {
            return [];
        }

        $options = ['proxy' => ['https' => $proxy, 'http' => $proxy]];

        // Use system CA bundle (includes HTTP Toolkit cert injected at startup)
        $caBundle = env('CURL_CA_BUNDLE');
        if ($caBundle && file_exists($caBundle)) {
            $options['verify'] = $caBundle;
        }

        return $options;
    }
}
