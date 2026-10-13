<?php

declare(strict_types=1);

use App\Support\PayloadHash;

it('canonicalizes associative keys recursively', function (): void {
    $first = ['b' => ['z' => 1, 'a' => 2], 'a' => [['y' => 3, 'x' => 4]]];
    $second = ['a' => [['x' => 4, 'y' => 3]], 'b' => ['a' => 2, 'z' => 1]];
    expect(PayloadHash::make($first))->toBe(PayloadHash::make($second))->toMatch('/^[a-f0-9]{64}$/');
});

it('preserves list order, types and exact string content', function (array $first, array $second): void {
    expect(PayloadHash::make($first))->not->toBe(PayloadHash::make($second));
})->with([
    'list order' => [[1, 2], [2, 1]],
    'scalar type' => [[1], ['1']],
    'whitespace' => [['text' => ' hello '], ['text' => 'hello']],
    'empty versus null' => [['value' => ''], ['value' => null]],
    'nested list order' => [['list' => ['a', 'b']], ['list' => ['b', 'a']]],
]);

it('rejects values which cannot be encoded as JSON', function (): void {
    expect(fn (): string => PayloadHash::make(['text' => "\xB1\x31"]))->toThrow(JsonException::class);
});
