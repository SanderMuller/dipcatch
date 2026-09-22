<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use Database\Seeders\Demo\DatasetCatalog;
use Database\Seeders\Demo\DemoOffer;
use Database\Seeders\Demo\DemoProduct;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // The resolver remembers what the AH API answered, and that memory is
    // static — it must not survive from one test into the next.
    DatasetCatalog::forget();
});

/** One row of the local checkjebon dataset, as the refresh command writes it. */
function checkjebonRow(string $supermarket, string $externalId, string $name, string $price, ?string $size, string $link): void
{
    CheckjebonPrice::query()->create([
        'supermarket' => $supermarket,
        'external_id' => $externalId,
        'name' => $name,
        'price' => $price,
        'size' => $size,
        'link' => $link,
        'refreshed_at' => now(),
    ]);
}

test('a product both chains sell becomes a two-shop product at real addresses', function (): void {
    checkjebonRow('ah', 'wi104064', 'Bonduelle Crispy maïs', '2.09', '300 g', 'wi104064/bonduelle-crispy-mais');
    checkjebonRow('spar', 'spar-1', 'Bonduelle Crispy maïs', '1.55', '300 Gram', 'bonduelle-crispy-ma%C3%AFs-5282209/');

    $products = DatasetCatalog::make(1, enrich: false);

    expect($products)->toHaveCount(1);

    $product = $products[0];
    $urls = array_map(fn (DemoOffer $offer): string => $offer->url(), $product->offers);

    expect($product->title)->toBe('Bonduelle Crispy maïs')
        // Albert Heijn first: CheckjebonSource resolves an ah.nl URL out of
        // this same table, so a recheck of the first offer needs no network.
        ->and($urls[0])->toBe('https://www.ah.nl/producten/product/wi104064/bonduelle-crispy-mais')
        ->and($urls[1])->toBe('https://www.spar.nl/bonduelle-crispy-ma%C3%AFs-5282209/')
        ->and($product->offers[0]->price)->toBe(2.09)
        ->and($product->offers[1]->price)->toBe(1.55)
        ->and($product->offers[0]->packQuantity)->toBe(300.0)
        ->and($product->offers[0]->packUnit)->toBe('g');
});

test('a product only one chain sells is left out', function (): void {
    // Nothing to compare it against, and a bulk row exists to fill the
    // comparison rather than to pose a question about it.
    checkjebonRow('ah', 'wi1', 'Alleen bij AH', '1.00', '100 g', 'wi1/alleen-bij-ah');
    checkjebonRow('spar', 'spar-1', 'Iets anders', '1.00', '100 g', 'iets-anders-1/');

    expect(DatasetCatalog::make(3, enrich: false))->toBeEmpty();
});

test('a pack size this app cannot read is left out', function (): void {
    // It would leave the product with no unit price, which is the column the
    // demo exists to show.
    checkjebonRow('ah', 'wi1', 'Onleesbaar formaat', '1.00', 'per stuk ongeveer', 'wi1/onleesbaar');
    checkjebonRow('spar', 'spar-1', 'Onleesbaar formaat', '1.20', 'per stuk ongeveer', 'onleesbaar-1/');

    expect(DatasetCatalog::make(3, enrich: false))->toBeEmpty();
});

test('an empty dataset asks the seeder to fall back', function (): void {
    // A checkout that has never refreshed the dataset must still seed.
    expect(DatasetCatalog::make(5, enrich: false))->toBeEmpty();
});

test('two accounts offset into the pool do not track the same product', function (): void {
    foreach (range(1, 6) as $i) {
        checkjebonRow('ah', 'wi' . (100 + $i), 'Product ' . $i, '2.00', '100 g', 'wi' . (100 + $i) . '/product-' . $i);
        checkjebonRow('spar', 'spar-' . $i, 'Product ' . $i, '2.20', '100 g', 'product-' . $i . '/');
    }

    $first = array_map(fn (DemoProduct $p): string => $p->title, DatasetCatalog::make(3, enrich: false));
    $second = array_map(fn (DemoProduct $p): string => $p->title, DatasetCatalog::make(3, offset: 3, enrich: false));

    expect(array_intersect($first, $second))->toBeEmpty()
        ->and($first)->toHaveCount(3);
});

test('a product Albert Heijn no longer sells is dropped', function (): void {
    // The dataset keeps a row until its next refresh, and an ah.nl URL for a
    // withdrawn product answers "uit het assortiment" rather than a price.
    // The API answers 404 for exactly those, which is the filter.
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'mobile-auth')) {
            return Http::response(['access_token' => 'fake', 'expires_in' => 604798]);
        }

        if (str_ends_with($request->url(), '/wi1')) {
            return Http::response([], 404);
        }

        return Http::response([
            'productCard' => [
                'title' => 'Nog steeds te koop',
                'currentPrice' => 2.49,
                'salesUnitSize' => '300 g',
                'images' => [['url' => 'https://static.ah.nl/dam/product/AHI_x?rendition=800x800_WEBP']],
            ],
        ]);
    });

    checkjebonRow('ah', 'wi1', 'Uit het assortiment', '1.00', '100 g', 'wi1/uit-het-assortiment');
    checkjebonRow('spar', 'spar-1', 'Uit het assortiment', '1.20', '100 g', 'uit-het-assortiment-1/');
    checkjebonRow('ah', 'wi2', 'Nog steeds te koop', '2.29', '300 g', 'wi2/nog-steeds-te-koop');
    checkjebonRow('spar', 'spar-2', 'Nog steeds te koop', '2.79', '300 g', 'nog-steeds-te-koop-2/');

    $products = DatasetCatalog::make(1);

    expect($products)->toHaveCount(1)
        ->and($products[0]->title)->toBe('Nog steeds te koop')
        // The live price, not the dataset's: a history ending on a stale
        // number makes the first real recheck read a fall that never happened.
        ->and($products[0]->offers[0]->price)->toBe(2.49)
        ->and($products[0]->offers[0]->imageUrl)->toStartWith('https://static.ah.nl/');
});
