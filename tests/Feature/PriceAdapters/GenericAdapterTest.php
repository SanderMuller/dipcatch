<?php declare(strict_types=1);

use App\PriceAdapters\GenericAdapter;

beforeEach(function (): void {
    $this->adapter = new GenericAdapter();
});

test('extracts from .price class with currency symbol', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>Demo Item</h1>
  <span class="price">€ 29,99</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('29.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Demo Item');
});

test('extracts from [data-price]', function (): void {
    $html = <<<'HTML'
<title>Plugged Item</title>
<div data-price="49.00">$49.00</div>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->price)->toBe('49.00')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('skips when no price selectors match', function (): void {
    $result = $this->adapter->extract('https://x.test', '<html><body><p>no prices</p></body></html>');

    expect($result->isSkip())->toBeTrue();
});

test('skips when matched selector has no currency hint', function (): void {
    $html = '<span class="price">29.99</span>';

    $result = $this->adapter->extract('https://x.test', $html);

    // No currency symbol or ISO code anywhere → skip (low confidence).
    expect($result->isSkip())->toBeTrue();
});

test('uses og:image as imageUrl', function (): void {
    $html = <<<'HTML'
<meta property="og:image" content="https://shop.test/p.jpg" />
<span class="price">£10.00</span>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->imageUrl)->toBe('https://shop.test/p.jpg')
        ->and($result->snapshot?->currency)->toBe('GBP');
});
