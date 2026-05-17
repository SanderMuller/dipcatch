<?php declare(strict_types=1);

use App\PriceAdapters\OpenGraphAdapter;

beforeEach(function (): void {
    $this->adapter = new OpenGraphAdapter();
});

test('skips when no og:price:amount is present', function (): void {
    $html = '<meta property="og:title" content="Widget" />';
    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSkip())->toBeTrue();
});

test('extracts og:price + og:price:currency', function (): void {
    $html = <<<'HTML'
<meta property="og:title" content="Sneakers" />
<meta property="og:image" content="https://shop.test/s.jpg" />
<meta property="og:price:amount" content="89.99" />
<meta property="og:price:currency" content="EUR" />
<meta property="og:availability" content="instock" />
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('89.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Sneakers')
        ->and($result->snapshot?->imageUrl)->toBe('https://shop.test/s.jpg')
        ->and($result->snapshot?->inStock)->toBeTrue();
});

test('accepts the product:price:* facet variant', function (): void {
    $html = <<<'HTML'
<meta property="product:price:amount" content="50.00" />
<meta property="product:price:currency" content="USD" />
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->price)->toBe('50.00')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('failed when amount present but currency absent', function (): void {
    $html = '<meta property="og:price:amount" content="10.00" />';

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('og_no_currency');
});

test('out of stock availability', function (): void {
    $html = <<<'HTML'
<meta property="og:price:amount" content="10.00" />
<meta property="og:price:currency" content="EUR" />
<meta property="og:availability" content="out of stock" />
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->inStock)->toBeFalse();
});
