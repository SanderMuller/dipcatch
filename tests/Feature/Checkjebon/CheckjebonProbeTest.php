<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Enums\ProbeFailure;
use App\Livewire\Products\CreateProductFromUrl;
use App\Models\CheckjebonPrice;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function seedAhRow(string $externalId = 'wi257', string $name = 'AH Kruiden roomkaas', string $price = '1.25'): void
{
    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => $externalId,
        'name' => $name,
        'price' => $price,
        'size' => '125 g',
        'refreshed_at' => now(),
    ]);
}

test('probe resolves an AH URL via the mobile API with the bonus price', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69', priceBeforeBonus: '2.19'));
    Http::preventStrayRequests();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.ah.nl/producten/product/wi526381/lay-s-naturel?utm_source=x', $user);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('ah-api')
        ->and($outcome->snapshot?->title)->toBe("Lay's Naturel")
        ->and($outcome->snapshot?->price)->toBe('1.69')
        ->and($outcome->snapshot?->raw['is_bonus'] ?? null)->toBeTrue()
        ->and($outcome->host)->toBe('ah.nl');
});

test('probe falls back to the dataset when the AH API is down', function (): void {
    Http::fake(ahApiDownFakes());
    Http::preventStrayRequests();
    seedAhRow();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.ah.nl/producten/product/wi257/ah-kruiden-roomkaas?utm_source=x', $user);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('checkjebon')
        ->and($outcome->snapshot?->title)->toBe('AH Kruiden roomkaas')
        ->and($outcome->snapshot?->price)->toBe('1.25')
        ->and($outcome->host)->toBe('ah.nl');
});

test('dataset probes do not consume the per-user probe rate limit', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $outcome = app(ProbeShopUrl::class)(null, 'https://www.ah.nl/producten/product/wi257/x', $user);
        expect($outcome->isSuccess())->toBeTrue();
    }

    expect(RateLimiter::attempts("dipcatch:probe:user:{$user->id}"))->toBe(0);
});

test('AH product missing from the dataset fails with not_in_dataset', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.ah.nl/producten/product/wi999999/unknown', $user);

    expect($outcome->isFailed())->toBeTrue()
        ->and($outcome->errorCode)->toBe(ProbeFailure::NotInDataset)
        ->and($outcome->context)->toBe(['reason' => 'not_in_dataset'])
        ->and($outcome->shouldOfferManualSelector())->toBeFalse();
});

test('empty dataset fails with dataset_empty reason', function (): void {
    Http::fake(ahApiDownFakes());
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.ah.nl/producten/product/wi257/x', $user);

    expect($outcome->errorCode)->toBe(ProbeFailure::NotInDataset)
        ->and($outcome->context)->toBe(['reason' => 'dataset_empty']);
});

test('lidl.nl URLs probe over the network through the Lidl adapter', function (): void {
    // The URL keeps its `www.` host: that is what the shop serves.
    Http::fake([
        'https://www.lidl.nl/robots.txt' => Http::response('', 404),
        'https://www.lidl.nl/*' => Http::response(lidlPage(), 200),
    ]);
    Http::preventStrayRequests();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.lidl.nl/p/lay-s/p10033095', $user);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('lidl')
        ->and($outcome->snapshot?->price)->toBe('1.99')
        ->and($outcome->snapshot?->packSize)->toBe('370 g');
});

test('currency mismatch still fires when adding to a non-EUR product', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $product = Product::factory()->create(['currency' => 'USD']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://www.ah.nl/producten/product/wi257/x', $user);

    expect($outcome->errorCode)->toBe(ProbeFailure::CurrencyMismatch)
        ->and($outcome->context)->toBe(['expected' => 'USD', 'actual' => 'EUR']);
});

test('create-from-URL flow creates product + shop + check from a seeded dataset row', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow(price: '55.00');
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://www.ah.nl/producten/product/wi257/ah-kruiden-roomkaas')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('title', 'AH Kruiden roomkaas')
        ->assertSet('imageUrl', '')
        // The 125 g pack makes the reference €440 per kilo, in the 100-500
        // tier: 8%. The money amount bands on the €55 pack: 7.00.
        ->assertSeeHtml('placeholder="8.00"')
        ->assertSeeHtml('placeholder="7.00"')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect();

    $product = Product::query()->where('user_id', $user->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->currency)->toBe('EUR')
        ->and($product->image_url)->toBeNull();

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop->adapter_key)->toBe('checkjebon')
        ->and((string) $shop->current_price)->toBe('55.00')
        ->and($shop->host)->toBe('ah.nl')
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);
});

test('add-shop-mode probe on an EUR product succeeds from the dataset', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $product = Product::factory()->create(['currency' => 'EUR']);
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)($product, 'https://www.ah.nl/producten/product/wi257/x', $user);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->adapterKey)->toBe('checkjebon')
        ->and($outcome->snapshot?->price)->toBe('1.25');
});

test('the AH probe transports salesUnitSize and confirm stores it', function (): void {
    Http::fake(ahApiProductFakes(currentPrice: '1.69', salesUnitSize: '200 g'));
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://www.ah.nl/producten/product/wi526381/lay-s-naturel')
        ->call('probe')
        ->assertSet('snapshot.pack_size', '200 g')
        ->assertSet('snapshot.pack_size_authoritative', true)
        ->assertSee('8.45')
        ->assertSee('/kg')
        ->call('confirm')
        ->assertHasNoErrors();

    $shop = Shop::query()->firstOrFail();
    expect((string) $shop->pack_quantity)->toBe('200.00')
        ->and($shop->pack_unit)->toBe('g')
        ->and($shop->unitPrice())->toBe('8.4500')
        ->and($shop->unitPriceLabel())->toBe('/kg');
});

test('the dataset probe transports the dataset size and confirm stores it', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://www.ah.nl/producten/product/wi257/ah-kruiden-roomkaas')
        ->call('probe')
        ->assertSet('snapshot.pack_size', '125 g')
        ->assertSet('snapshot.pack_size_authoritative', true)
        ->call('confirm')
        ->assertHasNoErrors();

    $shop = Shop::query()->firstOrFail();
    expect((string) $shop->pack_quantity)->toBe('125.00')
        ->and($shop->pack_unit)->toBe('g');
});

/**
 * The three `not_in_dataset` reasons each render their own sentence from
 * `livewire/shops/partials/probe-error.blade.php`, and they are the only
 * branch in that partial that picks copy from a context value rather than
 * from the error code. Nothing asserted the rendered text before: the
 * action-level tests above stop at `$outcome->context`, so a reason that
 * stopped reaching the template, or an `@elseif` chain that fell through to
 * the wrong arm, would have passed every one of them.
 */
test('a dataset URL with no product id explains that, not the generic miss', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        // An AH category page: the host is dataset-served, but no `wi<digits>`
        // segment means no product id to look up.
        ->set('url', 'https://www.ah.nl/producten/zuivel')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'not_in_dataset')
        ->assertSet('errorContext.reason', 'unrecognized_url')
        ->assertSee('No product id found in that URL.')
        ->assertDontSee('not in the daily price dataset');
});

test('an unloaded dataset says to load it rather than blaming the product', function (): void {
    Http::fake(ahApiDownFakes());
    // No seedAhRow(): the table is empty, which is an operator problem and
    // not a statement about this product.
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://www.ah.nl/producten/product/wi257/ah-kruiden-roomkaas')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorContext.reason', 'dataset_empty')
        ->assertSee('The daily price dataset has not been loaded yet.')
        ->assertSee('php artisan dipcatch:refresh-checkjebon')
        ->assertDontSee('not in the daily price dataset');
});

test('a product missing from a loaded dataset gets the generic miss', function (): void {
    Http::fake(ahApiDownFakes());
    seedAhRow();
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://www.ah.nl/producten/product/wi999999/unknown')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorContext.reason', 'not_in_dataset')
        ->assertSee('This product is not in the daily price dataset (checkjebon.nl).')
        ->assertDontSee('No product id found')
        ->assertDontSee('has not been loaded yet');
});
