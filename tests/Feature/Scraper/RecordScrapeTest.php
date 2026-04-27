<?php declare(strict_types=1);

use App\Actions\Scraper\RecordScrape;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Services\Scraper\ScrapeResult;

test('Ok result writes a price_check and updates the product s last_price + status', function (): void {
    $product = Product::factory()->create([
        'last_price' => '100.00',
        'last_status' => ScrapeStatus::Ok,
        'last_error' => null,
        'needs_js' => false,
    ]);

    $result = ScrapeResult::ok(
        rawPrice: '€89,00',
        price: '89.00',
        currency: 'EUR',
        title: 'Whatever',
        imageUrl: null,
    );

    $check = (new RecordScrape())($product, $result);

    expect($check)->toBeInstanceOf(PriceCheck::class)
        ->and($check->status)->toBe(ScrapeStatus::Ok)
        ->and($check->price)->toBe('89.00')
        ->and($check->raw)->toBe('€89,00');

    $product->refresh();
    expect($product->last_price)->toBe('89.00')
        ->and($product->last_status)->toBe(ScrapeStatus::Ok)
        ->and($product->last_error)->toBeNull()
        ->and($product->last_checked_at)->not->toBeNull();
});

test('NeedsJs flips needs_js and does NOT overwrite last_price', function (): void {
    $product = Product::factory()->create([
        'last_price' => '49.99',
        'last_status' => ScrapeStatus::Ok,
        'needs_js' => false,
    ]);

    $result = ScrapeResult::failure(ScrapeStatus::NeedsJs, 'Selector returned no match.');

    (new RecordScrape())($product, $result);
    $product->refresh();

    expect($product->needs_js)->toBeTrue()
        ->and($product->last_status)->toBe(ScrapeStatus::NeedsJs)
        ->and($product->last_error)->toBe('Selector returned no match.')
        ->and($product->last_price)->toBe('49.99'); // preserved
});

test('HttpError preserves last_price but updates last_status and last_error', function (): void {
    $product = Product::factory()->create([
        'last_price' => '199.00',
        'last_status' => ScrapeStatus::Ok,
        'last_error' => null,
        'needs_js' => false,
    ]);

    (new RecordScrape())($product, ScrapeResult::failure(ScrapeStatus::HttpError, 'HTTP 503'));
    $product->refresh();

    expect($product->last_price)->toBe('199.00')
        ->and($product->last_status)->toBe(ScrapeStatus::HttpError)
        ->and($product->last_error)->toBe('HTTP 503')
        ->and($product->needs_js)->toBeFalse();
});

test('failure result still writes a price_check with null price and the status', function (): void {
    $product = Product::factory()->create();

    (new RecordScrape())($product, ScrapeResult::failure(ScrapeStatus::ParseError, 'Could not normalize price'));

    $check = $product->priceChecks()->latest('checked_at')->first();
    expect($check->price)->toBeNull()
        ->and($check->status)->toBe(ScrapeStatus::ParseError)
        ->and($check->error)->toBe('Could not normalize price');
});

test('recovery: Ok after NeedsJs clears needs_js back to false', function (): void {
    $product = Product::factory()->create([
        'needs_js' => true,
        'last_status' => ScrapeStatus::NeedsJs,
        'last_price' => '0.00',
    ]);

    (new RecordScrape())($product, ScrapeResult::ok('€19,00', '19.00', 'EUR', null, null));
    $product->refresh();

    expect($product->needs_js)->toBeFalse()
        ->and($product->last_price)->toBe('19.00')
        ->and($product->last_status)->toBe(ScrapeStatus::Ok)
        ->and($product->last_error)->toBeNull();
});

test('records every check (history grows by one per call)', function (): void {
    $product = Product::factory()->create();
    $action = new RecordScrape();

    $action($product, ScrapeResult::ok('€10', '10.00', 'EUR', null, null));
    $action($product, ScrapeResult::failure(ScrapeStatus::HttpError, 'HTTP 500'));
    $action($product, ScrapeResult::ok('€11', '11.00', 'EUR', null, null));

    expect($product->priceChecks()->count())->toBe(3);
});
