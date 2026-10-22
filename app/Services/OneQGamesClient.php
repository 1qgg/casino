<?php

namespace App\Services;

use App\Models\GamesKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OneQGamesClient
{
    private const BASE_URL = 'https://api.1q.gg';

    public function __construct(private readonly GamesKey $settings)
    {
    }

    public static function configured(): bool
    {
        $settings = GamesKey::query()->first();
        return $settings !== null && filled($settings->oneq_operator_key) && filled($settings->oneq_operator_secret);
    }

    public function catalog(array $filters = []): array
    {
        $results = [];
        $page = 1;
        do {
            $query = array_filter([
                'provider' => $filters['provider'] ?? null,
                'search' => $filters['search'] ?? null,
                                'page' => $page,
                'per_page' => 100,
            ], static fn ($value) => $value !== null && $value !== '');

            $response = $this->request('GET', '/api/v1/games', $query);
            $data = $response['data'] ?? null;
            if (!is_array($data)) {
                throw new RuntimeException('Resposta inválida do catálogo 1Q.');
            }
            array_push($results, ...$data);
            $lastPage = (int) ($response['pagination']['last_page'] ?? $response['meta']['pagination']['last_page'] ?? $page);
            $page++;
        } while ($page <= max(1, $lastPage));

        return $results;
    }

    public function launch(array $payload, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new RuntimeException('Idempotency-Key inválida.');
        }

        return $this->request('POST', '/api/v1/launches', [], $payload, $idempotencyKey);
    }

    private function request(string $method, string $path, array $query = [], ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $key = (string) $this->settings->oneq_operator_key;
        $secret = (string) $this->settings->oneq_operator_secret;
        if ($key === '' || $secret === '') {
            throw new RuntimeException('Configure as credenciais da 1Q no painel administrativo.');
        }

        $timestamp = (string) time();
        $nonce = Str::random(48);
        $body = $payload === null
            ? ''
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $canonical = strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        $headers = [
            'X-Operator-Key' => $key,
            'X-1Q-Timestamp' => $timestamp,
            'X-1Q-Nonce' => $nonce,
            'X-1Q-Signature' => hash_hmac('sha256', $canonical, $secret),
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $request = Http::acceptJson()->withHeaders($headers)->timeout(15);
        if ($payload !== null) {
            $request = $request->withBody($body, 'application/json');
        }

        $response = $request->send(strtoupper($method), self::BASE_URL.$path, ['query' => $query]);
        if (!$response->successful()) {
            throw new RuntimeException('A API 1Q respondeu HTTP '.$response->status().'.');
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta JSON inválida da API 1Q.');
        }

        return $decoded;
    }
}
