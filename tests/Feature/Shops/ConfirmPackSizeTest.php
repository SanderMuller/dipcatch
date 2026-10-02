<?php declare(strict_types=1);

use App\Actions\Shops\CheckOutcome;
use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Enums\ScrapeStatus;
use App\Jobs\ConfirmPackSize;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Drops\ReferenceEpoch;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
    new ConfirmPackSize((string) $shop->id, 'Roter Vitamine C 70 mg kauwtablet citroen voordeelverpakking', (string) $shop->url)->handle(app(ShopMatchCheck::class));
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
        ->and($shop->product?->best_value_shop_id)->toBe((string) $shop->id)
        // The size checks that alert read it too.
        ->and($shop->unitPriceValue())->toEqualWithDelta(0.011, 0.000001);
    // The page's own title is the evidence, and the question names the one pack size.
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'questions.page.instructions.candidate.pack_size') === null
        && str_contains(json_encode(data_get($request->data(), 'questions.page.instructions.candidate.title')) ?: '', 'citroen voordeelverpakking')
        && str_contains(json_encode(data_get($request->data(), 'questions.page.instructions.question')) ?: '', 'exactly 800 piece')
        && data_get($request->data(), 'state.tracked_pack_sizes') === ['800 piece']);
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

    ConfirmPackSize::afterRead((string) $shop->id, (string) $shop->url, $outcome);
    // A reading of a page the shop no longer points at asks nothing.
    ConfirmPackSize::afterRead((string) $shop->id, 'https://www.koopjesdrogisterij.nl/old', $outcome);

    Queue::assertPushed(ConfirmPackSize::class, $queued);
})->with([
    'with the check' => [true, 1],
    'free' => [false, 0],
]);

it('drops a confirmed size when the shop is pointed at another page', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.95);
    confirmPack($shop);

    $shop->refresh()->updateUrl('https://www.koopjesdrogisterij.nl/p/2');

    expect($shop->refresh()->confirmedPackSize())->toBeNull()
        ->and($shop->pack_check_key)->toBeNull();
});

it('stores no answer for a page the shop no longer points at', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.95);

    new ConfirmPackSize((string) $shop->id, 'Roter Vitamine C (800 kauwtabletten)', 'https://www.koopjesdrogisterij.nl/old')->handle(app(ShopMatchCheck::class));

    expect($shop->refresh()->confirmedPackSize())->toBeNull();
    Http::assertNothingSent();
});

it('sends no drop alert when a confirmed size makes a shop the best value', function (): void {
    Notification::fake();
    $shop = silentPackProduct();
    $product = $shop->product;
    $product?->forceFill(['drop_threshold_pct' => 10])->save();

    // Every shop has been read before: the confirmation is no new shop's first reading.
    foreach ($shop->product()->sole()->shops as $tracked) {
        PriceCheck::factory()->for($tracked)->create(['price' => $tracked->current_price, 'status' => ScrapeStatus::Ok, 'in_stock' => true, 'checked_at' => now()->subDays(3)]);
    }

    $product?->recomputeCheapestShop();
    expect($product?->refresh()->best_value_shop_id)->not->toBe((string) $shop->id);
    fakePackAnswer(0.95);

    confirmPack($shop);

    // Nor on the next two readings of that shop, at the same price.
    foreach ([1, 2] as $hours) {
        $this->travel($hours)->hours();
        $next = PriceCheck::factory()->for($shop)->create(['price' => '8.80', 'status' => ScrapeStatus::Ok, 'in_stock' => true]);
        $product?->refresh()->recomputeCheapestShop($next->id);
    }

    expect($product?->refresh()->best_value_shop_id)->toBe((string) $shop->id)
        ->and(PriceDropEvent::query()->where('product_id', $product?->id)->count())->toBe(0);
});

it('lets go of a confirmed size once the other shops sell another one', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.95);
    confirmPack($shop);

    $shop->product?->shops()->whereNotNull('pack_quantity')->update(['pack_quantity' => '400.00']);

    $pack = $shop->product?->refresh()->comparablePacks()->for($shop->refresh());
    expect($pack?->provenance)->not->toBe(PackProvenance::Confirmed);
});

it('lets go of a confirmed size when the other shops no longer agree on one', function (): void {
    $shop = silentPackProduct();
    fakePackAnswer(0.95);
    confirmPack($shop);

    $shop->product?->shops()->where('host', 'jumbo.com')->update(['pack_quantity' => '400.00']);

    $pack = $shop->product?->refresh()->comparablePacks()->for($shop->refresh());
    expect($pack?->provenance)->not->toBe(PackProvenance::Confirmed);
});

it('starts the reference anew only at the first win of a confirmed shop, not each time it wins again', function (): void {
    $joined = CarbonImmutable::parse('2026-10-01 12:00');
    $segment = fn (string $shopId, string $start): ProductCheapestHistory => new ProductCheapestHistory([
        'best_value_shop_id' => $shopId,
        'best_value_price' => '10.00',
        'pack_quantity' => '800.00',
        'pack_unit' => 'piece',
        'started_at' => $start,
    ]);

    $segments = [
        $segment('a', '2026-09-20 08:00'),
        $segment('c', '2026-10-01 12:00'),
        $segment('a', '2026-10-01 18:00'),
        $segment('c', '2026-10-02 08:00'),
    ];

    $epoch = ReferenceEpoch::currentEpoch($segments, 'piece', ['c' => $joined]);

    // From C's first win on: A's second stretch and C's return stay in.
    expect(count($epoch))->toBe(3);
});
