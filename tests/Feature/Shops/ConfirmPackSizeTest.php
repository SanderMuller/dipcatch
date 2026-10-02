<?php declare(strict_types=1);

use App\Actions\Shops\CheckOutcome;
use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Jobs\ConfirmPackSize;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::preventStrayRequests();
});

/**
 * Vitamin C at two shops that state 800 tablets, about €0.03 each, and a
 * bargain shop that states no size at €8.80: borrowed, that reads €0.011 a
 * tablet, which looks wrong.
 */
function silentPackProduct(bool $pro = true): Shop
{
    $user = User::factory()->create(['shop_checks' => $pro]);

    if ($pro) {
        subscribeUser($user);
    }

    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'title' => 'Roter Vitamine C 70 mg 800 kauwtabletten']);
    Shop::factory()->for($product)->create(['url' => 'https://www.benushop.nl/p/1', 'host' => 'benushop.nl', 'current_price' => '21.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);
    Shop::factory()->for($product)->create(['url' => 'https://www.jumbo.com/p/1', 'host' => 'jumbo.com', 'current_price' => '24.99', 'pack_quantity' => '800.00', 'pack_unit' => 'piece']);

    return Shop::factory()->for($product)->create(['url' => 'https://www.koopjesdrogisterij.nl/p/1', 'host' => 'koopjesdrogisterij.nl', 'current_price' => '8.80', 'pack_quantity' => null, 'pack_unit' => null]);
}

function fakePackAnswer(?float $chance): void
{
    Http::fake([TypeSafeClient::ENDPOINT => $chance === null
        ? Http::response([], 500)
        : Http::response(['answers' => ['page' => ['noul' => $chance]]])]);
}

function confirmPack(Shop $shop): void
{
    new ConfirmPackSize((string) $shop->id, 'Roter Vitamine C 70 mg kauwtablet (800 kauwtabletten)')->handle(app(ShopMatchCheck::class));
}

it('takes the size the other shops state as the page\'s own when Jev is sure, so the shop compares and can win', function (): void {
    $shop = silentPackProduct();
    expect($shop->product?->comparablePacks()->for($shop)?->exclusion)->toBe(PackExclusion::SizeImplausible);
    fakePackAnswer(0.92);

    confirmPack($shop);

    $pack = $shop->refresh()->product?->refresh()->comparablePacks()->for($shop);
    expect($pack?->provenance)->toBe(PackProvenance::Confirmed)
        ->and($pack?->canWin())->toBeTrue()
        ->and($pack?->unitPriceFor($shop->current_price))->toBe('0.0110')
        ->and($shop->product?->best_value_shop_id)->toBe((string) $shop->id);
    Http::assertSent(fn ($request): bool => str_contains((string) json_encode($request->data()), '800 piece')
        && str_contains((string) json_encode($request->data()), '(800 kauwtabletten)'));
});

it('stores nothing on an unsure answer, and asks about the same page and size only once', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.5);

    confirmPack($shop);
    confirmPack($shop->refresh());

    expect($shop->confirmedPackSize())->toBeNull()
        ->and($shop->pack_check_key)->not->toBeNull();
    Http::assertSentCount(1);
});

it('asks again on a later check when Jev did not answer', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(null);

    confirmPack($shop);

    expect($shop->refresh()->pack_check_key)->toBeNull()
        ->and($shop->confirmedPackSize())->toBeNull();
});

it('lets a size the page states win over a confirmed one', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.95);
    confirmPack($shop);

    $shop->refresh()->forceFill(['pack_quantity' => '400.00', 'pack_unit' => 'piece'])->save();

    $pack = $shop->product?->refresh()->comparablePacks()->for($shop->refresh());
    expect($pack?->provenance)->toBe(PackProvenance::Stated)
        ->and($pack?->size?->quantity)->toBe(400.0);
});

it('queues the check after a read only for an account with the AI shop check', function (bool $pro, int $queued): void {
    Queue::fake();
    $shop = silentPackProduct($pro);
    $outcome = CheckOutcome::success(new ShopSnapshot(title: 'Roter Vitamine C (800 kauwtabletten)', imageUrl: null, price: '8.80', currency: 'EUR', inStock: true), 'jsonld', imageUrl: null);

    ConfirmPackSize::afterRead((string) $shop->id, $outcome);

    Queue::assertPushed(ConfirmPackSize::class, $queued);
})->with([
    'with the check' => [true, 1],
    'free' => [false, 0],
]);
