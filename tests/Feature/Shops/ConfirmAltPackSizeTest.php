<?php declare(strict_types=1);

use App\Actions\Shops\CheckOutcome;
use App\Enums\PackProvenance;
use App\Jobs\CheckShopPrice;
use App\Jobs\ConfirmAltPackSize;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::preventStrayRequests();
});

function altCheckShop(Product $product, string $host, string $price, string $quantity, string $unit, ?string $altQuantity = null, ?string $altUnit = null): Shop
{
    $shop = Shop::factory()->for($product)->create(['url' => "https://{$host}/p/" . fake()->uuid(), 'host' => $host]);
    $shop->forceFill([
        'currency' => 'EUR', 'current_price' => $price, 'current_in_stock' => true,
        'pack_quantity' => $quantity, 'pack_unit' => $unit,
        'alt_pack_quantity' => $altQuantity, 'alt_pack_unit' => $altUnit,
    ])->save();

    return $shop;
}

function altCheckOwner(bool $pro = true): User
{
    $user = User::factory()->create(['shop_checks' => $pro]);

    if ($pro) {
        subscribeUser($user);
    }

    return $user;
}

/**
 * Three shops weighing 560 g at about €7 a kilo, and one counting 20 pieces
 * whose 560 g would put it at €1.79 a kilo: a second size that looks wrong.
 */
function doubtfulAltShop(bool $pro = true): Shop
{
    $product = Product::factory()->for(altCheckOwner($pro))->create(['currency' => 'EUR', 'title' => 'Iglo Vissticks']);
    altCheckShop($product, 'jumbo.com', '3.99', '560.00', 'g');
    altCheckShop($product, 'dirk.nl', '4.10', '560.00', 'g');
    altCheckShop($product, 'plus.nl', '3.80', '560.00', 'g');

    return altCheckShop($product, 'ah.nl', '1.00', '20.00', 'piece', '560.00', 'g');
}

function fakeAltAnswer(?float $chance): void
{
    Http::fake([TypeSafeClient::ENDPOINT => $chance === null
        ? Http::response([], 500)
        : Http::response(['answers' => ['page' => ['noul' => $chance]]])]);
}

function confirmAlt(Shop $shop): void
{
    new ConfirmAltPackSize((string) $shop->id, 'Iglo 20 Vissticks', (string) $shop->url, ConfirmAltPackSize::pairKey($shop))->handle(app(ShopMatchCheck::class));
}

function readOutcome(): CheckOutcome
{
    return CheckOutcome::success(new ShopSnapshot('Iglo 20 Vissticks', imageUrl: null, price: '1.00', currency: 'EUR', inStock: true), 'poiesz', imageUrl: null);
}

it('lets a second size Jev confirms into the comparison', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(0.95);

    confirmAlt($shop);

    expect($shop->refresh()->alt_pack_confirmed)->toBeTrue()
        ->and($shop->alt_pack_since)->not->toBeNull()
        ->and($shop->product?->refresh()->comparablePacks()->for($shop)?->provenance)->toBe(PackProvenance::Stated);

    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', '20 piece (560 g)'));
});

it('keeps a second size Jev rejects out, even once the prices make it look right', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(0.1);

    confirmAlt($shop);
    $shop->forceFill(['current_price' => '4.00'])->save();

    expect($shop->refresh()->alt_pack_confirmed)->toBeFalse()
        ->and($shop->product?->refresh()->comparablePacks()->for($shop)?->isExcluded())->toBeTrue();
});

it('stores nothing without an answer, so a later check asks again', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(null);

    confirmAlt($shop);

    expect($shop->refresh())
        ->alt_pack_confirmed->toBeNull()
        ->alt_pack_check_key->toBeNull();
});

it('asks once per pair', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(0.1);

    confirmAlt($shop);
    confirmAlt($shop->refresh());

    Http::assertSentCount(1);
});

it('voids an answer about a pair that changed while Jev answered', function (): void {
    $shop = doubtfulAltShop();
    Http::fake([TypeSafeClient::ENDPOINT => function () use ($shop) {
        // A price check reads a new count mid-question.
        Shop::query()->whereKey($shop->id)->update(['pack_quantity' => '10.00']);

        return Http::response(['answers' => ['page' => ['noul' => 0.95]]]);
    }]);

    confirmAlt($shop);

    expect($shop->refresh()->alt_pack_confirmed)->toBeNull();
});

it('queues the question after a read only for an account with the AI check, and only once', function (bool $pro, int $pushed): void {
    Queue::fake();
    $shop = doubtfulAltShop($pro);

    ConfirmAltPackSize::afterRead((string) $shop->id, (string) $shop->url, readOutcome(), ConfirmAltPackSize::pairKey($shop));
    ConfirmAltPackSize::afterRead((string) $shop->id, (string) $shop->url, readOutcome(), ConfirmAltPackSize::pairKey($shop));

    Queue::assertPushed(ConfirmAltPackSize::class, $pushed);
})->with([
    'with the AI check' => [true, 1],
    'without it' => [false, 0],
]);

it('asks about a doubtful second size on a product that stays in the other unit', function (): void {
    Queue::fake();
    $shop = doubtfulAltShop();

    expect($shop->product?->comparablePacks()->unit())->toBe('g')
        ->and($shop->product?->comparablePacks()->altInDoubt($shop))->toBeTrue();

    ConfirmAltPackSize::afterRead((string) $shop->id, (string) $shop->url, readOutcome(), ConfirmAltPackSize::pairKey($shop));

    Queue::assertPushed(ConfirmAltPackSize::class);
});

it('lets a blocked target conversion through once Jev rejects the shop that disagreed', function (): void {
    $user = altCheckOwner();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR', 'title' => 'Iglo Vissticks',
        'unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece',
    ]);
    altCheckShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    altCheckShop($product, 'jumbo.com', '3.69', '420.00', 'g', '15.00', 'piece');
    altCheckShop($product, 'dirk.nl', '3.99', '560.00', 'g', '20.00', 'piece');
    // Its count says each stick weighs 56 g: twice everyone else's.
    $odd = altCheckShop($product, 'plus.nl', '4.49', '560.00', 'g', '10.00', 'piece');
    $product->refresh()->recomputeCheapestShop();
    expect($product->isUnitTargetSuspended())->toBeTrue();

    fakeAltAnswer(0.1);
    confirmAlt($odd);

    expect($odd->refresh()->alt_pack_confirmed)->toBeFalse()
        ->and($product->refresh()->unit_price_target_effective)->toBe('8.9286');
});

it('asks about a shop whose item size blocks a waiting conversion, and only while a target waits', function (bool $withTarget, int $pushed): void {
    Queue::fake();
    $product = Product::factory()->for(altCheckOwner())->create([
        'currency' => 'EUR', 'title' => 'Iglo Vissticks',
        ...($withTarget ? ['unit_price_target' => '0.25', 'unit_price_target_unit' => 'piece'] : []),
    ]);
    altCheckShop($product, 'ah.nl', '4.99', '20.00', 'piece', '560.00', 'g');
    altCheckShop($product, 'jumbo.com', '3.69', '420.00', 'g', '15.00', 'piece');
    altCheckShop($product, 'dirk.nl', '3.99', '560.00', 'g', '20.00', 'piece');
    // Plausible per kilo and per piece, but 56 g a stick against everyone's 28.
    $odd = altCheckShop($product, 'plus.nl', '3.50', '560.00', 'g', '10.00', 'piece');
    $product->refresh()->recomputeCheapestShop();

    expect($product->comparablePacks()->altInDoubt($odd))->toBeFalse();

    ConfirmAltPackSize::afterRead((string) $odd->id, (string) $odd->url, readOutcome(), ConfirmAltPackSize::pairKey($odd));

    Queue::assertPushed(ConfirmAltPackSize::class, $pushed);
})->with([
    'a target waits' => [true, 1],
    'no target' => [false, 0],
]);

it('queues the question from a price check that reads a doubtful second size', function (): void {
    Queue::fake();
    $product = Product::factory()->for(altCheckOwner())->create(['currency' => 'EUR', 'title' => 'Iglo Vissticks']);
    altCheckShop($product, 'jumbo.com', '3.99', '560.00', 'g');
    altCheckShop($product, 'dirk.nl', '4.10', '560.00', 'g');
    altCheckShop($product, 'plus.nl', '3.80', '560.00', 'g');
    $ah = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/producten/product/wi191096/iglo-vissticks', 'adapter_key' => 'checkjebon', 'current_price' => '1.00']);
    // €1.00 for 560 g: the weight looks wrong beside €7 a kilo.
    Http::fake(ahApiProductFakes(currentPrice: '1.00', isBonus: false, title: 'Iglo Vissticks', salesUnitSize: '20 stuks', netContent: [[20.0, 'st'], [560.0, 'g']]));

    new CheckShopPrice($ah)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );

    Queue::assertPushed(ConfirmAltPackSize::class);
});

it('does not judge a pair that changed while the question waited in the queue on the old title', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(0.1);
    $job = new ConfirmAltPackSize((string) $shop->id, 'Iglo 20 Vissticks', (string) $shop->url, ConfirmAltPackSize::pairKey($shop));

    $shop->forceFill(['pack_quantity' => '10.00'])->save();
    $job->handle(app(ShopMatchCheck::class));

    Http::assertNothingSent();
    expect($shop->refresh()->alt_pack_confirmed)->toBeNull();
});

it('does not queue a question when a newer pair was stored after this read', function (): void {
    Queue::fake();
    $shop = doubtfulAltShop();
    $readKey = ConfirmAltPackSize::pairKey($shop);
    $shop->forceFill(['pack_quantity' => '10.00'])->save();

    ConfirmAltPackSize::afterRead((string) $shop->id, (string) $shop->url, readOutcome(), $readKey);

    Queue::assertNothingPushed();
});

it('decides nothing on an answer between a sure yes and a sure no, and does not ask again', function (): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer(0.54);

    confirmAlt($shop);
    confirmAlt($shop->refresh());

    expect($shop->refresh()->alt_pack_confirmed)->toBeNull()
        ->and($shop->alt_pack_check_key)->not->toBeNull()
        ->and($shop->product?->refresh()->comparablePacks()->altInDoubt($shop))->toBeTrue();
    Http::assertSentCount(1);
});

it('confirms from 0.8 and rejects only under 0.2', function (float $chance, ?bool $verdict): void {
    $shop = doubtfulAltShop();
    fakeAltAnswer($chance);

    confirmAlt($shop);

    expect($shop->refresh()->alt_pack_confirmed)->toBe($verdict);
})->with([
    '0.8 confirms' => [0.8, true],
    'just under 0.8 decides nothing' => [0.79, null],
    '0.2 decides nothing' => [0.2, null],
    'just under 0.2 rejects' => [0.19, false],
]);

it('forgets the rejections stored under the old cutoff, and keeps confirmations', function (): void {
    $rejected = doubtfulAltShop();
    $rejected->forceFill(['alt_pack_confirmed' => false, 'alt_pack_check_key' => 'old'])->save();
    $confirmed = altCheckShop($rejected->product()->sole(), 'plus.nl', '3.90', '20.00', 'piece', '560.00', 'g');
    $confirmed->forceFill(['alt_pack_confirmed' => true, 'alt_pack_check_key' => 'kept'])->save();

    (require database_path('migrations/2026_10_07_113701_forget_alt_pack_rejections_from_the_old_cutoff.php'))->up();

    expect($rejected->refresh())
        ->alt_pack_confirmed->toBeNull()
        ->alt_pack_check_key->toBeNull()
        ->and($confirmed->refresh())
        ->alt_pack_confirmed->toBeTrue()
        ->alt_pack_check_key->toBe('kept');
});
