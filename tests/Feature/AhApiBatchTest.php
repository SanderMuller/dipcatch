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

test('a missing product without the unknown-product error is an API error', function (array $answer): void {
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response(['access_token' => 'fake', 'expires_in' => 604798]),
        'https://api.ah.nl/graphql' => Http::response($answer),
    ]);

    $result = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een');

    expect($result->missReason)->toBe(CheckjebonResult::REASON_API_ERROR);
})->with([
    'no errors' => [['data' => ['product' => null]]],
    'an error inside the product' => [['data' => ['product' => null], 'errors' => [['message' => 'Subgraph errors redacted', 'path' => ['product', 'priceV2']]]]],
]);

test('a product AH cannot take orders for is out of stock', function (?bool $isOrderable, bool $inStock): void {
    Http::fake(ahGraphqlProductFakes([
        'title' => 'Iglo Vissticks',
        'priceV2' => ['now' => ['amount' => 4.99], 'was' => ['amount' => 4.99], 'discount' => null],
        ...($isOrderable === null ? [] : ['availability' => ['isOrderable' => $isOrderable]]),
    ]));

    expect(app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een')->snapshot?->inStock)->toBe($inStock);
})->with([
    'not orderable' => [false, false],
    'orderable' => [true, true],
    'not stated' => [null, true],
]);

test('net contents in a unit DipCatch cannot read leave the stored sizes alone', function (): void {
    Http::fake(ahGraphqlProductFakes([
        'title' => 'Iglo Vissticks',
        'priceV2' => ['now' => ['amount' => 4.99], 'was' => ['amount' => 4.99], 'discount' => null],
        'tradeItem' => ['contents' => ['netContents' => ['20.0 Stuks', '1.0 Rol']]],
    ]));

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een')->snapshot;

    expect($snapshot?->altPackSizesAuthoritative)->toBeFalse();
});

test('a product answered in part is an API error, so nothing stored is cleared', function (): void {
    // GraphQL nulls a field it could not resolve and names it in `errors`.
    // Read as complete, the null discount would clear a running bonus.
    Http::fake([
        'https://api.ah.nl/mobile-auth/*' => Http::response(['access_token' => 'fake', 'expires_in' => 604798]),
        'https://api.ah.nl/graphql' => Http::response([
            'data' => ['product' => [
                'title' => 'Gevonden',
                'priceV2' => ['now' => ['amount' => 1.49], 'was' => ['amount' => 1.99], 'discount' => null],
            ]],
            'errors' => [['message' => 'Subgraph errors redacted', 'path' => ['product', 'priceV2', 'discount']]],
        ]),
    ]);

    $result = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een');

    expect($result->missReason)->toBe(CheckjebonResult::REASON_API_ERROR);
});

test('net contents in centilitres read as millilitres', function (): void {
    // Leffe Blond 0.0% states "30.0 Centiliter", captured 2026-11-21.
    Http::fake(ahGraphqlProductFakes([
        'title' => 'Leffe Blond 0.0% 6-pack',
        'priceV2' => ['now' => ['amount' => 6.79], 'was' => ['amount' => 6.79], 'discount' => null],
        'tradeItem' => ['contents' => ['netContents' => ['30.0 Centiliter']]],
    ]));

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1/een')->snapshot;

    expect($snapshot?->altPackSizes)->toBe(['300 ml'])
        ->and($snapshot?->altPackSizesAuthoritative)->toBeTrue();
});
