<?php declare(strict_types=1);

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;

function snap(string $price = '10.00'): ShopSnapshot
{
    return new ShopSnapshot(
        title: 'demo',
        imageUrl: null,
        price: $price,
        currency: 'EUR',
        inStock: true,
    );
}

function fakeAdapter(string $key, ExtractionResult $result): ShopAdapter
{
    return new class ($key, $result) implements ShopAdapter {
        public function __construct(public string $k, public ExtractionResult $r) {}

        public function key(): string
        {
            return $this->k;
        }

        public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
        {
            return $this->r;
        }
    };
}

test('first successful adapter wins', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('a', ExtractionResult::skip()),
        fakeAdapter('b', ExtractionResult::success(snap('80.00'))),
        fakeAdapter('c', ExtractionResult::success(snap('70.00'))),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>');

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('80.00');
});

test('failed stops the chain — no fallback to later adapters', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('a', ExtractionResult::skip()),
        fakeAdapter('b', ExtractionResult::failed('jsonld_parse_error')),
        fakeAdapter('c', ExtractionResult::success(snap('70.00'))),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('jsonld_parse_error');
});

test('all skip returns no_adapter_matched failure', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('a', ExtractionResult::skip()),
        fakeAdapter('b', ExtractionResult::skip()),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('no_adapter_matched');
});

test('persisted key runs first and wins on success', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('first', ExtractionResult::success(snap('100.00'))),
        fakeAdapter('persisted', ExtractionResult::success(snap('80.00'))),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>', 'persisted');

    expect($result->snapshot?->price)->toBe('80.00');
});

test('persisted key skips, full chain resumes with persisted excluded', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('persisted', ExtractionResult::skip()),
        fakeAdapter('fallback', ExtractionResult::success(snap('99.00'))),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>', 'persisted');

    expect($result->snapshot?->price)->toBe('99.00');
});

test('persisted key failed → full chain runs (does not stop)', function (): void {
    $resolver = new AdapterResolver([
        fakeAdapter('persisted', ExtractionResult::failed('hint_failed')),
        fakeAdapter('fallback', ExtractionResult::success(snap('77.00'))),
    ]);

    $result = $resolver->resolve('https://x.test', '<html></html>', 'persisted');

    expect($result->snapshot?->price)->toBe('77.00');
});
