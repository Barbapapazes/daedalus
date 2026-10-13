<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareArtifacts
{
    public function request(string $namespace, string $method, string $path, array $data = [], bool $allowMissing = false): ?array
    {
        $account = config('services.cloudflare.account_id');
        $token = config('services.cloudflare.api_token');
        if (! is_string($account) || ! preg_match('/^[a-f0-9]{32}$/', $account) || ! is_string($token) || $token === '') {
            throw new RuntimeException('Configure CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN for Artifacts provisioning.');
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/'.$account.'/artifacts/namespaces/'.rawurlencode($namespace).'/'.$path;
        try {
            $response = Http::withToken($token)->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            // Never include upstream bodies, URLs with tokens, or previous exceptions.
            throw new RuntimeException('Cloudflare Artifacts connection failed.');
        }

        if ($allowMissing && $response->status() === 404) {
            return null;
        }

        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('result'))) {
            throw new RuntimeException('Cloudflare Artifacts '.$method.' request failed (HTTP '.$response->status().').');
        }

        return $response->json('result');
    }
}
