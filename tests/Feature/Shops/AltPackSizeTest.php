<?php declare(strict_types=1);

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ShopDraft;
use App\Jobs\CheckShopPrice;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\Hosts\PoieszAdapter;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\AltPackSize;
use App\Support\PackSize;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Http;

/**
 * An AH shop, read through the AH API, with any stored sizes given.
 *
 * @param  array<string, mixed>  $attributes
 */
function ahShop(array $attributes = []): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/producten/product/wi191096/iglo-vissticks',
        'adapter_key' => 'checkjebon',
        'current_price' => '4.99',
    ]);

    if ($attributes !== []) {
        $shop->forceFill($attributes)->save();
    }

    return $shop;
}

function checkAh(Shop $shop): Shop
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    return $shop->refresh();
}

function sized(string $text): PackSize
{
    return PackSize::parse($text) ?? throw new RuntimeException("Not a size: {$text}");
}

function fakeAh(?string $salesUnitSize, array|false|null $netContent): void
{
    Http::fake(ahApiProductFakes(currentPrice: '4.99', isBonus: false, title: 'Iglo Vissticks', salesUnitSize: $salesUnitSize, netContent: $netContent));
    Http::preventStrayRequests();
}

test('AH stores the count as the pack and the weight as the second size', function (): void {
    fakeAh('20 stuks', [[20.0, 'st'], [560.0, 'g']]);

    $shop = checkAh(ahShop());

    expect($shop)
        ->pack_quantity->toBe('20.00')
        ->pack_unit->toBe('piece')
        ->alt_pack_quantity->toBe('560.00')
        ->alt_pack_unit->toBe('g')
        ->and($shop->alt_pack_since)->not->toBeNull();
});

test('AH stores no second size when net content only repeats the pack', function (): void {
    fakeAh('200 g', [[200.0, 'g']]);

    expect(checkAh(ahShop())->alt_pack_quantity)->toBeNull();
});

test('an AH response without the trade item keeps the stored second size', function (): void {
    fakeAh('20 stuks', false);

    $shop = checkAh(ahShop(['pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g']));

    expect($shop->alt_pack_quantity)->toBe('560.00');
});

test('an AH trade item with no net content clears the second size', function (): void {
    fakeAh('20 stuks', null);

    $shop = checkAh(ahShop(['pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g']));

    expect($shop->alt_pack_quantity)->toBeNull()
        ->and($shop->alt_pack_unit)->toBeNull();
});

test('a response without a sales unit still reads the second size against the stored pack', function (): void {
    fakeAh(null, [[20.0, 'st'], [560.0, 'g']]);

    $shop = checkAh(ahShop(['pack_quantity' => '20.00', 'pack_unit' => 'piece']));

    expect($shop)
        ->pack_unit->toBe('piece')
        ->alt_pack_quantity->toBe('560.00');
});

test('a shop that now leads with its second size keeps the old pack as the second one, and what Jev said about it', function (): void {
    fakeAh('560 g', false);
    $since = now()->subWeek()->startOfSecond();

    $shop = checkAh(ahShop([
        'pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g',
        'alt_pack_check_key' => 'answered', 'alt_pack_confirmed' => false, 'alt_pack_since' => $since,
    ]));

    expect($shop)
        ->pack_quantity->toBe('560.00')
        ->pack_unit->toBe('g')
        ->alt_pack_quantity->toBe('20.00')
        ->alt_pack_unit->toBe('piece')
        ->alt_pack_check_key->toBe('answered')
        ->alt_pack_confirmed->toBeFalse()
        ->and($shop->alt_pack_since?->equalTo($since))->toBeTrue();
});

test('an AH trade item with no net content clears the second size before the title is read', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '3.99', isBonus: false, title: 'Iglo Vissticks 20 stuks', salesUnitSize: '560 g', netContent: null));
    Http::preventStrayRequests();

    $shop = checkAh(ahShop(['pack_quantity' => '560.00', 'pack_unit' => 'g', 'alt_pack_quantity' => '20.00', 'alt_pack_unit' => 'piece']));

    expect($shop->alt_pack_quantity)->toBeNull();
});

test('a new pack in the unit of the second size, at another quantity, drops the second size', function (): void {
    fakeAh('500 g', false);

    $shop = checkAh(ahShop(['pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g']));

    expect($shop->pack_quantity)->toBe('500.00')
        ->and($shop->alt_pack_quantity)->toBeNull();
});

test('a pack that changes quantity with nothing new said drops the second size', function (): void {
    fakeAh('500 g', false);

    $shop = checkAh(ahShop(['pack_quantity' => '560.00', 'pack_unit' => 'g', 'alt_pack_quantity' => '20.00', 'alt_pack_unit' => 'piece']));

    expect($shop->alt_pack_quantity)->toBeNull();
});

test('either size moving clears the Jev answer about the pair', function (): void {
    fakeAh('10 stuks', [[10.0, 'st'], [560.0, 'g']]);

    $shop = checkAh(ahShop([
        'pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g',
        'alt_pack_check_key' => 'answered', 'alt_pack_confirmed' => true,
    ]));

    expect($shop->alt_pack_quantity)->toBe('560.00')
        ->and($shop->alt_pack_check_key)->toBeNull()
        ->and($shop->alt_pack_confirmed)->toBeNull();
});

test('the Checkjebon fallback keeps the second size it knows nothing about', function (): void {
    Http::fake(ahApiDownFakes());
    Http::preventStrayRequests();
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah', 'external_id' => 'wi191096', 'name' => 'Iglo Vissticks',
        'price' => '4.99', 'size' => '20 stuks', 'refreshed_at' => now(),
    ]);

    $shop = checkAh(ahShop(['pack_quantity' => '20.00', 'pack_unit' => 'piece', 'alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g']));

    expect($shop->alt_pack_quantity)->toBe('560.00');
});

test('a title count is the second size of a pack sold by weight', function (string $title, string $primary, ?string $expected): void {
    $alt = AltPackSize::from([], sized($primary), $title);

    expect($alt === null ? null : $alt->quantity . ' ' . $alt->unit)->toBe($expected);
})->with([
    'Jumbo cross form' => ['Iglo Vissticks 15 stuks 15 x 28 g', '420 g', '15 piece'],
    'Dirk count beside a weight field' => ['Iglo Vissticks 20 stuks', '560 g', '20 piece'],
    'a count adds nothing to a count' => ['Iglo 20 Vissticks', '20 stuks', null],
    'a title with no count' => ['Iglo Vissticks XXL', '840 g', null],
    // True: the bag holds twenty sachets, and it only counts if pieces
    // bring more shops into the comparison.
    'sachets in a weighed bag' => ['Koffie 500 g 20 zakjes', '500 g', '20 piece'],
]);

test('a stated size wins over the title, and one in the pack unit is no second size', function (): void {
    expect(AltPackSize::from(['560 g', '20 stuks'], sized('560 g'), 'Iglo Vissticks 15 stuks')?->quantity)->toBe(20.0)
        ->and(AltPackSize::from(['560 g'], sized('560 g'), title: null))->toBeNull();
});

test('a shop pointed at another page forgets its second size', function (): void {
    $shop = ahShop(['alt_pack_quantity' => '560.00', 'alt_pack_unit' => 'g', 'alt_pack_check_key' => 'k', 'alt_pack_confirmed' => true, 'alt_pack_since' => now()]);

    $shop->updateUrl(UrlNormalizer::normalize('https://ah.nl/producten/product/wi533326/iglo-vissticks'));
    $shop->save();

    expect($shop->refresh())
        ->alt_pack_quantity->toBeNull()
        ->alt_pack_unit->toBeNull()
        ->alt_pack_check_key->toBeNull()
        ->alt_pack_confirmed->toBeNull()
        ->alt_pack_since->toBeNull();
});

test('a shop attached from a preview keeps the second size the page stated', function (): void {
    $draft = ShopDraft::fromSnapshot([
        'title' => 'Iglo 20 Vissticks', 'price' => '4.99', 'currency' => 'EUR', 'in_stock' => true,
        'pack_size' => '20.00 Stuks', 'pack_size_authoritative' => true, 'alt_pack_sizes' => ['560 g'],
    ], 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/305527', 'poiesz');

    expect($draft->altPackSize?->quantity)->toBe(560.0)
        ->and($draft->altPackSize?->unit)->toBe('g');
});

test('Poiesz states its content apart from the description', function (?float $volume, ?string $unitId, array $expected, bool $authoritative): void {
    $this->travelTo('2026-10-06 12:00:00');
    $result = app(PoieszAdapter::class)->extract('https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/278550', poieszPage(packageDescription: '20.00 Stuks', volumeCe: $volume, unitId: $unitId));

    expect($result->snapshot?->altPackSizes)->toBe($expected)
        ->and($result->snapshot?->altPackSizesAuthoritative)->toBe($authoritative);
})->with([
    'grams' => [560.0, 'GR', ['560 g'], true],
    'millilitres' => [900.0, 'ML', ['900 ml'], true],
    'pieces' => [1.0, 'ST', ['1 stuks'], true],
    'an unknown code' => [560.0, 'XX', [], false],
    'no content field' => [null, null, [], false],
]);

test('an attached shop stores the second size its page stated', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    $draft = ShopDraft::fromSnapshot([
        'title' => 'Iglo 20 Vissticks', 'price' => '4.99', 'currency' => 'EUR', 'in_stock' => true,
        'pack_size' => '20.00 Stuks', 'pack_size_authoritative' => true, 'alt_pack_sizes' => ['560 g'],
    ], 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/305527', 'poiesz');

    $shop = app(AttachShop::class)->firstShopOf($product, $draft)->refresh();

    expect($shop)
        ->alt_pack_quantity->toBe('560.00')
        ->alt_pack_unit->toBe('g')
        ->and($shop->alt_pack_since)->not->toBeNull();
});

test('a preview whose source states no second size attaches none, whatever the title counts', function (): void {
    $draft = ShopDraft::fromSnapshot([
        'title' => 'Iglo Vissticks 20 stuks', 'price' => '3.99', 'currency' => 'EUR', 'in_stock' => true,
        'pack_size' => '560 g', 'pack_size_authoritative' => true, 'alt_pack_sizes' => [], 'alt_pack_sizes_authoritative' => true,
    ], 'https://ah.nl/producten/product/wi191096/iglo-vissticks', 'checkjebon');

    expect($draft->altPackSize)->toBeNull();
});
