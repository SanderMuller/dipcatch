<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\CheckjebonPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;

function runCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );
}

/**
 * @param  array<string, mixed>  $attributes  Extra Shop attributes applied after create.
 */
function checkjebonShop(string $url = 'https://ah.nl/producten/product/wi257/roomkaas', array $attributes = []): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    $shop = Shop::factory()->for($product)->create([
        'url' => $url,
        'adapter_key' => 'checkjebon',
        'current_price' => '9.99',
    ]);
    if ($attributes !== []) {
        $shop->forceFill($attributes)->save();
    }

    return $shop;
}

function seedAhDatasetRow(string $size, string $price = '1.15'): void
{
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => $price,
        'size' => $size,
        'refreshed_at' => now(),
    ]);
}

test('recheck of a dataset host falls back to the table when the AH API is down', function (): void {
    Http::fake(ahApiDownFakes());
    Http::preventStrayRequests();

    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => '1.15',
        'size' => '125 g',
        'refreshed_at' => now(),
    ]);

    $shop = checkjebonShop();
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('1.15')
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('checkjebon')
        ->and($shop->consecutive_failures)->toBe(0);

    $check = PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->first();
    expect((string) $check->price)->toBe('1.15')
        ->and($check->status)->toBe(ScrapeStatus::Ok);
});

test('recheck of an AH shop uses the mobile API bonus price', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69'));
    Http::preventStrayRequests();

    $shop = checkjebonShop('https://ah.nl/producten/product/wi526381/lay-s-naturel');
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->current_price)->toBe('1.69')
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->adapter_key)->toBe('ah-api')
        ->and($shop->consecutive_failures)->toBe(0);
});

test('dataset miss records EmptyMatch and ticks the failure counter', function (): void {
    Http::fake(ahApiDownFakes());
    // Table non-empty for AH, but not this product.
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi1',
        'name' => 'Other',
        'price' => '2.00',
        'size' => null,
        'refreshed_at' => now(),
    ]);

    $shop = checkjebonShop('https://ah.nl/producten/product/wi257/roomkaas');
    runCheck($shop);

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::EmptyMatch)
        ->and($shop->consecutive_failures)->toBe(1)
        ->and($shop->last_error)->toBe('checkjebon:not_in_dataset');

    $check = PriceCheck::query()->where('shop_id', $shop->id)->latest('id')->first();
    expect($check->status)->toBe(ScrapeStatus::EmptyMatch);
});

test('re-listed product recovers the shop on the next check', function (): void {
    Http::fake(ahApiDownFakes());
    $shop = checkjebonShop();
    runCheck($shop); // miss: table empty
    expect($shop->refresh()->consecutive_failures)->toBe(1);

    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi257',
        'name' => 'AH Kruiden roomkaas',
        'price' => '1.25',
        'size' => null,
        'refreshed_at' => now(),
    ]);

    runCheck($shop);
    $shop->refresh();
    expect($shop->consecutive_failures)->toBe(0)
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $shop->current_price)->toBe('1.25');
});

test('an AH recheck stores the pack size from salesUnitSize', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69', salesUnitSize: '200 g'));
    Http::preventStrayRequests();

    $shop = checkjebonShop('https://ah.nl/producten/product/wi526381/lay-s-naturel');
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->pack_quantity)->toBe('200.00')
        ->and($shop->pack_unit)->toBe('g');
});

test('a packaging change overwrites the stored pack size', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69', salesUnitSize: '250 g'));
    Http::preventStrayRequests();

    $shop = checkjebonShop('https://ah.nl/producten/product/wi526381/lay-s-naturel', [
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);
    runCheck($shop);

    expect((string) $shop->refresh()->pack_quantity)->toBe('250.00');
});

test('an AH response without the salesUnitSize key keeps the stored pack size', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69', salesUnitSize: null));
    Http::preventStrayRequests();

    $shop = checkjebonShop('https://ah.nl/producten/product/wi526381/lay-s-naturel', [
        'pack_quantity' => '200.00',
        'pack_unit' => 'g',
    ]);
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->pack_quantity)->toBe('200.00')
        ->and($shop->pack_unit)->toBe('g');
});

test('an authoritative empty size clears the pack columns', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhDatasetRow('');

    $shop = checkjebonShop(attributes: ['pack_quantity' => '125.00', 'pack_unit' => 'g']);
    runCheck($shop);

    $shop->refresh();
    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->pack_quantity)->toBeNull()
        ->and($shop->pack_unit)->toBeNull();
});

test('an authoritative unparseable size clears the pack columns', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhDatasetRow('48+ plakken');

    $shop = checkjebonShop(attributes: ['pack_quantity' => '125.00', 'pack_unit' => 'g']);
    runCheck($shop);

    $shop->refresh();
    expect($shop->pack_quantity)->toBeNull()
        ->and($shop->pack_unit)->toBeNull();
});

test('a dataset recheck stores the dataset size', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhDatasetRow('0,75 l');

    $shop = checkjebonShop();
    runCheck($shop);

    $shop->refresh();
    expect((string) $shop->pack_quantity)->toBe('750.00')
        ->and($shop->pack_unit)->toBe('ml');
});

test('an AH bonus stores the period it runs for', function (): void {
    Http::fake(ahApiProductFakes());

    $result = app(AhApiSource::class)->resolve('https://ah.nl/producten/product/wi526381/lay-s-naturel');
    $window = $result->snapshot?->promotionWindow;

    expect($result->snapshot?->price)->toBe('1.69')
        ->and($window?->label)->toBe('VOOR 1.69')
        ->and($window?->startsAt?->toDateString())->toBe('2026-08-31')
        ->and($window?->endsAt?->toDateString())->toBe('2026-09-06')
        // The end is the Amsterdam close of that day, not midnight UTC.
        ->and($window?->endsAt?->setTimezone('Europe/Amsterdam')->format('H:i:s'))->toBe('23:59:59');
});

test('a product with no bonus clears a stored period', function (): void {
    // The live API omits the date keys entirely on a non-bonus product, so
    // authority has to come from `isBonus` being present at all.
    Http::fake(ahApiProductFakes(currentPrice: '3.49', isBonus: false));

    $result = app(AhApiSource::class)->resolve('https://ah.nl/producten/product/wi409179/beemster');

    expect($result->snapshot?->promotionWindow)->toBeNull()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('a bonus without dates yields no period rather than a guessed one', function (): void {
    Http::fake(ahApiProductFakes(bonusStart: null, bonusEnd: null));

    $result = app(AhApiSource::class)->resolve('https://ah.nl/producten/product/wi526381/lay-s-naturel');

    expect($result->snapshot?->price)->toBe('1.69')
        ->and($result->snapshot?->promotionWindow)->toBeNull();
});
