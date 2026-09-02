<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\AldiAdapter;

beforeEach(function (): void {
    $this->adapter = new AldiAdapter();
    $this->url = 'https://www.aldi.nl/product/granola-91244024.html';
});

test('skips a host that is not aldi.nl', function (): void {
    expect($this->adapter->extract('https://other.com/product/granola-91244024.html', aldiPage())->isSkip())->toBeTrue();
});

test('reads price, title, pack size, stock and the primary image from the Next.js payload', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.49')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('JORDANS Granola')
        ->and($result->snapshot?->packSize)->toBe('500 g')
        ->and($result->snapshot?->packSizeAuthoritative)->toBeTrue()
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://s7g10.scene7.com/is/image/aldinord/91244024_week37');
});

test('an unavailable product is reported out of stock', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage(available: false));

    expect($result->snapshot?->inStock)->toBeFalse();
});

test('an expired price window is refused rather than reported as current', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage(validFrom: '-14 days', validUntil: '-7 days'));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('aldi_no_current_price');
});

test('a price without a window is taken as shown', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage(validFrom: null, validUntil: null));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('2.49');
});

test('a payload describing a different product than the URL is refused', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage(slug: 'ander-product-91200000'));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('aldi_no_product');
});

test('a sibling product in the same payload cannot supply the price', function (): void {
    $html = aldiPage(products: [
        [
            'name' => 'Ander product',
            'productSlug' => 'ander-product-91200000',
            'brandName' => 'ALDI',
            'salesUnit' => '1 kg',
            'isAvailable' => true,
            'currentPrice' => ['priceValue' => 99.99],
        ],
        [
            'name' => 'Granola',
            'productSlug' => 'granola-91244024',
            'brandName' => 'JORDANS',
            'salesUnit' => '500 g',
            'isAvailable' => true,
            'currentPrice' => ['priceValue' => 2.49],
        ],
    ]);

    $result = $this->adapter->extract($this->url, $html);

    expect($result->snapshot?->price)->toBe('2.49')
        ->and($result->snapshot?->title)->toBe('JORDANS Granola');
});

test('a URL naming no article fails instead of pricing whatever the page listed', function (): void {
    $result = $this->adapter->extract('https://www.aldi.nl/', aldiPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('aldi_no_product_slug');
});

test('a page without the Next.js payload fails with an Aldi-specific reason', function (): void {
    $result = $this->adapter->extract($this->url, '<html><body>x</body></html>');

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('aldi_no_payload');
});

test('a payload that omits the availability flag is not read as sold out', function (): void {
    $html = aldiPage(products: [[
        'name' => 'Granola',
        'productSlug' => 'granola-91244024',
        'brandName' => 'JORDANS',
        'salesUnit' => '500 g',
        'currentPrice' => ['priceValue' => 2.49],
    ]]);

    expect($this->adapter->extract($this->url, $html)->snapshot?->inStock)->toBeTrue();
});

test('a window with only an end date still expires the price', function (): void {
    $html = aldiPage(products: [[
        'name' => 'Granola',
        'productSlug' => 'granola-91244024',
        'brandName' => 'JORDANS',
        'isAvailable' => true,
        'currentPrice' => ['priceValue' => 2.49, 'validUntil' => now()->modify('-1 day')->getTimestamp()],
    ]]);

    expect($this->adapter->extract($this->url, $html)->failureReason)->toBe('aldi_no_current_price');
});

test('a window with only a start date refuses a price that is not live yet', function (): void {
    $html = aldiPage(products: [[
        'name' => 'Granola',
        'productSlug' => 'granola-91244024',
        'brandName' => 'JORDANS',
        'isAvailable' => true,
        'currentPrice' => ['priceValue' => 2.49, 'validFrom' => now()->modify('+2 days')->getTimestamp()],
    ]]);

    expect($this->adapter->extract($this->url, $html)->failureReason)->toBe('aldi_no_current_price');
});

test('a validity bound this adapter cannot read refuses the price', function (): void {
    $html = aldiPage(products: [[
        'name' => 'Granola',
        'productSlug' => 'granola-91244024',
        'brandName' => 'JORDANS',
        'isAvailable' => true,
        'currentPrice' => ['priceValue' => 2.49, 'validUntil' => '2026-09-06T21:59:59Z'],
    ]]);

    expect($this->adapter->extract($this->url, $html)->failureReason)->toBe('aldi_no_current_price');
});

test('reports the campaign period the price belongs to', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage());

    expect($result->snapshot?->promotionWindow?->isRunning())->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a price with no stated window reports none', function (): void {
    $result = $this->adapter->extract($this->url, aldiPage(validFrom: null, validUntil: null));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->promotionWindow)->toBeNull()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});
