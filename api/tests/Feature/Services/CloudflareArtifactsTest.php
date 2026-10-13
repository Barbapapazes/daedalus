<?php

declare(strict_types=1);

use App\Services\CloudflareArtifacts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.cloudflare', ['account_id' => str_repeat('a', 32), 'api_token' => 'test-api-token']);
});

it('uses the authenticated Cloudflare REST API and unwraps the envelope', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'repo_123']])]);
    $result = app(CloudflareArtifacts::class)->request('daedalus', 'POST', 'repos', ['name' => 'example']);
    expect($result)->toBe(['id' => 'repo_123']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/'.str_repeat('a', 32).'/artifacts/namespaces/daedalus/repos'
        && $request->hasHeader('Authorization', 'Bearer test-api-token') && $request->method() === 'POST' && $request['name'] === 'example');
});

it('fails closed on missing or invalid configuration', function (string $field, mixed $value): void {
    config()->set('services.cloudflare.'.$field, $value);
    expect(fn () => app(CloudflareArtifacts::class)->request('daedalus', 'GET', 'repos/example'))->toThrow(RuntimeException::class, 'Configure CLOUDFLARE');
    Http::assertNothingSent();
})->with([['account_id', null], ['account_id', '../unsafe'], ['api_token', null], ['api_token', '']]);

it('does not leak response bodies or follow redirects', function (int $status): void {
    Http::fake(['*' => Http::response(['success' => false, 'result' => null, 'secret' => 'private-token'], $status, ['Location' => 'https://other.example.com'])]);
    try {
        app(CloudflareArtifacts::class)->request('daedalus', 'GET', 'repos/example');
        $this->fail('Expected an API failure.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('HTTP '.$status)->not->toContain('private-token');
    }

    Http::assertSentCount(1);
})->with([302, 401, 409, 429, 500, 200]);

it('only treats explicit resource lookups as missing', function (): void {
    Http::fake(['*' => Http::response([], 404)]);
    expect(app(CloudflareArtifacts::class)->request('daedalus', 'GET', 'repos/example', allowMissing: true))->toBeNull();
    expect(fn () => app(CloudflareArtifacts::class)->request('daedalus', 'DELETE', 'tokens/token'))->toThrow(RuntimeException::class, 'HTTP 404');
});

it('sanitizes connection errors without retaining the original exception', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    try {
        app(CloudflareArtifacts::class)->request('daedalus', 'POST', 'repos', ['name' => 'example']);
        $this->fail('Expected a connection failure.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Cloudflare Artifacts connection failed.')->and($runtimeException->getPrevious())->toBeNull();
    }
});
