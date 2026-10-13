<?php

use App\Jobs\TriggerIntegration;
use App\Models\ImplementationRequest;
use App\Services\QueueIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ImplementationPayload;

beforeEach(function (): void {
    $implementation = ImplementationRequest::factory()->create();
    $this->integration = app(QueueIntegration::class)->handle($implementation, ImplementationPayload::event($implementation, 'completed')['payload']);
    config()->set('services.talos', [
        'url' => 'https://talos.example.com/',
        'access_client_id' => 'test-id',
        'access_client_secret' => 'test-secret',
    ]);
});

it('dispatches the exact immutable integration assignment to its own agent', function (): void {
    Http::fake(['*' => Http::response(['requestId' => $this->integration->id, 'accepted' => true])]);
    (new TriggerIntegration($this->integration->id))->handle();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://talos.example.com/internal/integrations'
        && $request->hasHeader('CF-Access-Client-ID', 'test-id')
        && $request->hasHeader('CF-Access-Client-Secret', 'test-secret')
        && $request->data() === $this->integration->assignment);
    expect($this->integration->fresh()->dispatched_at)->not->toBeNull();
    (new TriggerIntegration($this->integration->id))->handle();
    Http::assertSentCount(1);
});

it('retries an ambiguous dispatch without changing its ID or assignment', function (): void {
    Http::fakeSequence()->pushFailedConnection()->push(['requestId' => $this->integration->id, 'accepted' => true]);
    $job = new TriggerIntegration($this->integration->id);
    expect(fn () => $job->handle())->toThrow(ConnectionException::class);
    $job->handle();
    $payloads = Http::recorded()->map(fn (array $pair) => $pair[0]->data());
    expect($payloads[0])->toBe($payloads[1]);
});

it('requires a positive matching acknowledgement', function (array $ack): void {
    Http::fake(['*' => Http::response($ack)]);
    expect(fn () => (new TriggerIntegration($this->integration->id))->handle())->toThrow(RuntimeException::class);
    expect($this->integration->fresh()->dispatched_at)->toBeNull();
})->with([[[]], [['requestId' => 'wrong-id', 'accepted' => true]]]);

it('does not dispatch a terminal integration', function (string $status): void {
    $this->integration->update(['status' => $status]);
    (new TriggerIntegration($this->integration->id))->handle();
    Http::assertNothingSent();
})->with(['integrated', 'blocked', 'failed']);

it('fails closed without production credentials and retains a safe retryable dispatch error', function (): void {
    config()->set('services.talos.access_client_secret', null);
    $job = new TriggerIntegration($this->integration->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('secret token'));
    expect($this->integration->fresh()->dispatch_error)->toContain('Retry the failed queue job')->not->toContain('secret token')
        ->and($this->integration->fresh()->status)->toBe('queued');
    Http::assertNothingSent();
});
