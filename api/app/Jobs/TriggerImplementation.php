<?php

namespace App\Jobs;

use App\Models\ImplementationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TriggerImplementation implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 30;

    public function __construct(public string $requestId)
    {
        // Same database as the request: enqueue and intake commit atomically.
        $this->onConnection('implementations')->onQueue('implementations');
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(): void
    {
        $implementation = ImplementationRequest::findOrFail($this->requestId);
        if ($implementation->dispatched_at || ! $implementation->assignedRepositories()) {
            return;
        }

        $base = config('services.talos.url');
        $id = config('services.talos.access_client_id');
        $secret = config('services.talos.access_client_secret');
        $host = parse_url($base ?? '', PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (! $base || ! $host || parse_url($base, PHP_URL_USER) || parse_url($base, PHP_URL_PASS) ||
            (! $local && (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! $id || ! $secret))) {
            throw new RuntimeException('Configure Talos URL and production Cloudflare Access credentials.');
        }

        $headers = $id && $secret ? ['CF-Access-Client-ID' => $id, 'CF-Access-Client-Secret' => $secret] : [];
        // A timeout can mean Talos already accepted the request. Retry exactly
        // the same immutable assignment, addressed by the same request ID.
        $response = Http::acceptJson()->withHeaders($headers)
            ->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->post(rtrim($base, '/').'/internal/implementations', [
                'requestId' => $implementation->id,
                'conversationId' => $implementation->conversation_id,
                'intent' => $implementation->intent,
                'messages' => $implementation->messages,
                ...($implementation->repositories !== null
                    ? ['repositories' => $implementation->repositories]
                    : ['repository' => $implementation->repository]),
            ]);
        $response->throw();
        if ($response->json('requestId') !== $implementation->id || $response->json('accepted') !== true) {
            throw new RuntimeException('Talos returned an invalid acknowledgement.');
        }

        $implementation->update(['dispatched_at' => now(), 'dispatch_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        // Do not mark execution failed: a lost acknowledgement may have started it.
        ImplementationRequest::whereKey($this->requestId)->whereNull('dispatched_at')->update([
            'dispatch_error' => 'Talos dispatch could not be confirmed. Retry the failed queue job; do not create a new request.',
        ]);
    }
}
