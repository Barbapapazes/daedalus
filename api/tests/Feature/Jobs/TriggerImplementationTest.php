<?php

declare(strict_types=1);

use App\Jobs\TriggerImplementation;
use App\Models\ImplementationRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->implementation = ImplementationRequest::factory()->create(['dispatch_error' => 'Previous error']);
    config()->set('services.talos', [
        'url' => 'https://talos.example.com/',
        'access_client_id' => 'test-access-id',
        'access_client_secret' => 'test-access-secret',
    ]);
});

it('sends the immutable assignment with authentication and records acknowledgement', function (): void {
    Http::fake(['talos.example.com/*' => Http::response(['requestId' => $this->implementation->id, 'accepted' => true])]);
    (new TriggerImplementation($this->implementation->id))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://talos.example.com/internal/implementations'
        && $request->method() === 'POST'
        && $request->hasHeader('CF-Access-Client-ID', 'test-access-id')
        && $request->hasHeader('CF-Access-Client-Secret', 'test-access-secret')
        && $request->data() === [
            'requestId' => $this->implementation->id,
            'conversationId' => $this->implementation->conversation_id,
            'intent' => $this->implementation->intent,
            'messages' => $this->implementation->messages,
            'repository' => $this->implementation->repository,
        ]);
    Http::assertSentCount(1);
    expect($this->implementation->fresh()->dispatched_at->toISOString())->toBe(now()->toISOString())
        ->and($this->implementation->fresh()->dispatch_error)->toBeNull()
        ->and($this->implementation->fresh()->status)->toBe('queued');
});

it('does not dispatch acknowledged or historical unassigned requests', function (array $attributes): void {
    $this->implementation->forceFill($attributes)->saveQuietly();
    (new TriggerImplementation($this->implementation->id))->handle();
    Http::assertNothingSent();
})->with([
    'acknowledged' => [fn (): array => ['dispatched_at' => now()]],
    'historical' => [['repository' => null]],
]);

it('allows local development without Cloudflare Access credentials', function (string $url): void {
    config()->set('services.talos.url', $url);
    config()->set('services.talos.access_client_id');
    config()->set('services.talos.access_client_secret');
    Http::fake(['*' => Http::response(['requestId' => $this->implementation->id, 'accepted' => true])]);
    (new TriggerImplementation($this->implementation->id))->handle();
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('CF-Access-Client-ID')
        && ! $request->hasHeader('CF-Access-Client-Secret'));
})->with(['http://localhost:8787', 'http://127.0.0.1:8787', 'http://[::1]:8787']);

it('fails closed on unsafe or incomplete dispatch configuration', function (array $overrides): void {
    foreach ($overrides as $key => $value) {
        config()->set('services.talos.'.$key, $value);
    }

    expect(fn () => (new TriggerImplementation($this->implementation->id))->handle())
        ->toThrow(RuntimeException::class, 'Configure Talos URL');
    Http::assertNothingSent();
    expect($this->implementation->fresh()->dispatched_at)->toBeNull();
})->with([
    'missing URL' => [['url' => null]],
    'missing client ID' => [['access_client_id' => null]],
    'missing client secret' => [['access_client_secret' => null]],
    'insecure remote' => [['url' => 'http://talos.example.com']],
    'embedded credentials' => [['url' => 'https://user:password@talos.example.com']],
    'invalid URL' => [['url' => '/relative']],
    'lookalike localhost' => [['url' => 'http://localhost.example.com']],
]);

it('requires a matching positive acknowledgement', function (array $response): void {
    Http::fake(['*' => Http::response($response)]);
    expect(fn () => (new TriggerImplementation($this->implementation->id))->handle())
        ->toThrow(RuntimeException::class, 'invalid acknowledgement');
    expect($this->implementation->fresh()->dispatched_at)->toBeNull();
})->with([
    'missing acknowledgement' => [[]],
    'wrong request' => [['requestId' => 'another-request', 'accepted' => true]],
    'rejected' => [fn (): array => ['requestId' => $this->implementation->id, 'accepted' => false]],
    'non-boolean acceptance' => [fn (): array => ['requestId' => $this->implementation->id, 'accepted' => 'true']],
]);

it('leaves requests retryable on HTTP errors and does not follow redirects', function (int $status): void {
    Http::fake(['*' => Http::response([], $status, ['Location' => 'https://other.example.com'])]);
    $exception = $status >= 400 ? RequestException::class : RuntimeException::class;
    expect(fn () => (new TriggerImplementation($this->implementation->id))->handle())->toThrow($exception);
    Http::assertSentCount(1);
    expect($this->implementation->fresh()->dispatched_at)->toBeNull()
        ->and($this->implementation->fresh()->status)->toBe('queued');
})->with([302, 401, 429, 500]);

it('retries the exact same assignment after an ambiguous connection failure', function (): void {
    Http::fakeSequence()->pushFailedConnection()->push(['requestId' => $this->implementation->id, 'accepted' => true]);
    $job = new TriggerImplementation($this->implementation->id);
    expect(fn () => $job->handle())->toThrow(ConnectionException::class);
    expect($this->implementation->fresh()->dispatched_at)->toBeNull();

    $job->handle();
    $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data());
    expect($requests)->toHaveCount(2)
        ->and($requests[0])->toBe($requests[1]);
    $job->handle();
    Http::assertSentCount(2);
});

it('records a safe dispatch error without marking execution as failed', function (): void {
    (new TriggerImplementation($this->implementation->id))->failed(new RuntimeException('secret credentials'));
    $implementation = $this->implementation->fresh();
    expect($implementation->dispatch_error)->toContain('Retry the failed queue job')->not->toContain('secret credentials')
        ->and($implementation->status)->toBe('queued')
        ->and($implementation->dispatched_at)->toBeNull();
});

it('does not overwrite an acknowledged request when a stale job fails', function (): void {
    $this->implementation->update(['dispatched_at' => now(), 'dispatch_error' => null]);
    (new TriggerImplementation($this->implementation->id))->failed(null);
    expect($this->implementation->fresh()->dispatch_error)->toBeNull();
});

it('configures bounded retries on the dedicated queue', function (): void {
    $job = new TriggerImplementation($this->implementation->id);
    expect($job->connection)->toBe('implementations')
        ->and($job->queue)->toBe('implementations')
        ->and($job->tries)->toBe(10)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.implementations.retry_after'))
        ->and($job->backoff())->toBe([10, 30, 60, 120, 300]);
});
