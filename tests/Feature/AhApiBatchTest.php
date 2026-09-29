<?php declare(strict_types=1);

use App\Services\AhApi\AhApiSource;
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

        if (str_ends_with($request->url(), '/fir/2')) {
            return Http::response([], 404);
        }

        return Http::response(['productCard' => [
            'title' => 'Gevonden',
            'currentPrice' => 1.49,
            'salesUnitSize' => '200 g',
            'images' => [['url' => 'https://static.ah.nl/dam/product/AHI_x']],
        ]]);
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
        // A product Albert Heijn no longer sells answers 404 and is a miss,
        // not a gap in the array.
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
