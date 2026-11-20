<?php declare(strict_types=1);

use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * One product at a time costs a round trip each. The demo seeder wants
 * sixty-eight of them and spent sixteen of its twenty-four seconds waiting.
 */
test('many products are asked about together and answered by URL', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'mobile-auth')) {
            return Http::response(['access_token' => 'fake', 'expires_in' => 604798]);
        }

        if (data_get($request->data(), 'variables.id') === 2) {
            // AH's answer for an id it does not know, captured 2026-11-20.
            return Http::response(['data' => null, 'errors' => [['message' => 'Subgraph errors redacted', 'path' => ['product']]]]);
        }

        return Http::response(['data' => ['product' => [
            'title' => 'Gevonden',
            'salesUnitSize' => '200 g',
            'imagePack' => [['medium' => ['url' => 'https://static.ah.nl/dam/product/AHI_x']]],
            'priceV2' => ['now' => ['amount' => 1.49], 'was' => ['amount' => 1.49], 'discount' => null],
        ]]]);
    });

    $results = app(AhApiSource::class)->resolveMany([
        'https://www.ah.nl/producten/product/wi1/een',
        'https://www.ah.nl/producten/product/wi2/twee',
        'https://www.ah.nl/producten/product/wi3/drie',
    ]);

    expect($results)->toHaveCount(3)
        // Keyed by the URL that was asked, so a caller pairs them up without
        // relying on the order they came back in.
        ->and($results['https://www.ah.nl/producten/product/wi1/een']->isFound())->toBeTrue()
        ->and($results['https://www.ah.nl/producten/product/wi1/een']->snapshot?->price)->toBe('1.49')
        // A product Albert Heijn no longer sells is a miss, not a gap in
        // the array.
        ->and($results['https://www.ah.nl/producten/product/wi2/twee']->isFound())->toBeFalse()
        ->and($results['https://www.ah.nl/producten/product/wi3/drie']->isFound())->toBeTrue();
});

test('a URL naming no product is a miss rather than a request', function (): void {
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response(['access_token' => 'fake', 'expires_in' => 604798]),
    ]);

    $results = app(AhApiSource::class)->resolveMany(['https://www.ah.nl/producten/']);

    expect($results['https://www.ah.nl/producten/']->isFound())->toBeFalse();

    Http::assertSentCount(1);
});

test('no token means every answer is a miss and nothing is asked', function (): void {
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response([], 500),
    ]);

    $results = app(AhApiSource::class)->resolveMany([
        'https://www.ah.nl/producten/product/wi1/een',
        'https://www.ah.nl/producten/product/wi2/twee',
    ]);

    expect($results)->toHaveCount(2)
        ->and($results['https://www.ah.nl/producten/product/wi1/een']->isFound())->toBeFalse();

    Http::assertSentCount(1);
});

test('a query AH rejects is an API error, not a product it does not sell', function (): void {
    // A field the schema no longer has fails the whole query. It must not
    // read as "not in the dataset", or a schema change hides as delistings.
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response(['access_token' => 'fake', 'expires_in' => 604798]),
        'https://api.ah.nl/graphql' => Http::response(['errors' => [[
            'message' => 'Cannot query field "salesUnitSize" on type "Product".',
            'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED'],
        ]]]),
    ]);

    $result = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een');

    expect($result->missReason)->toBe(CheckjebonResult::REASON_API_ERROR);
});
