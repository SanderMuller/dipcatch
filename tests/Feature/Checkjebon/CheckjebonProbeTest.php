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

test('lidl.nl URLs fast-fail pointing to boodschaapje', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    $user = User::factory()->create();

    $outcome = app(ProbeShopUrl::class)(null, 'https://www.lidl.nl/p/some-product/p10012345', $user);

    expect($outcome->errorCode)->toBe(ProbeFailure::NotInDataset)
        ->and($outcome->context)->toBe(['reason' => 'use_boodschaapje']);

    Http::assertNothingSent();
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
        // 55.00 sits in the 25-100 tier: 10% / 7.00 absolute.
        ->assertSet('thresholdPct', '10.00')
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
        ->and($shop->host)->toBe('ah.nl');

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);
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
