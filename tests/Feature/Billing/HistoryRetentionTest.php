<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

/**
 * A product carrying history well past the 365-day prune cutoff.
 */
function agedProduct(User $user): Product
{
    $product = Product::factory()->create([
        'user_id' => $user->id,
        'currency' => 'EUR',
        'created_at' => CarbonImmutable::now()->subDays(600),
    ]);
    $shop = Shop::factory()->create(['product_id' => $product->id]);

    ProductCheapestHistory::create([
        'product_id' => $product->id,
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '9.99',
        'started_at' => CarbonImmutable::now()->subDays(500),
        'ended_at' => CarbonImmutable::now()->subDays(400),
    ]);

    PriceDropEvent::factory()->create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'fired_at' => CarbonImmutable::now()->subDays(400),
    ]);

    return $product->refresh();
}

function oldSegmentsFor(Product $product): int
{
    return ProductCheapestHistory::query()->where('product_id', $product->id)->count();
}

it('keeps a pro account history the prune would otherwise delete', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = agedProduct($user);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect(oldSegmentsFor($product))->toBe(1)
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and($product->refresh()->history_kept_from)->not->toBeNull();
});

it('prunes a free account history exactly as before', function (): void {
    $product = agedProduct(User::factory()->create());

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    // The drop-event floor keeps the 50 most recent per product on any
    // plan, so the segment is what separates free from Pro here.
    expect(oldSegmentsFor($product))->toBe(0)
        ->and($product->refresh()->history_kept_from)->toBeNull();
});

it('keeps what was accumulated after the account cancels', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = agedProduct($user);

    // First run stamps the product while the subscription is live.
    $this->artisan('dipcatch:prune-checks')->assertSuccessful();
    $stamp = $product->refresh()->history_kept_from;

    // The subscription lapses; the nightly prune runs again.
    Subscription::query()
        ->where('user_id', $user->id)
        ->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect($user->fresh()?->isPro())->toBeFalse()
        // The promise: a downgrade takes nothing away.
        ->and(oldSegmentsFor($product))->toBe(1)
        ->and($product->refresh()->history_kept_from?->timestamp)->toBe($stamp?->timestamp);
});

it('never moves a stamp once written', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = agedProduct($user);

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();
    $first = $product->refresh()->history_kept_from;

    $this->travel(2)->days();
    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    expect($product->refresh()->history_kept_from?->timestamp)->toBe($first?->timestamp);
});

it('still prunes price_checks for a pro account', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = agedProduct($user);
    $shop = $product->shops()->firstOrFail();

    // 60 rows so the "keep the 50 most recent" floor cannot hide the prune.
    foreach (range(1, 60) as $i) {
        PriceCheck::factory()->create([
            'shop_id' => $shop->id,
            'checked_at' => CarbonImmutable::now()->subDays(400 + $i),
        ]);
    }

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    // The bulky table is the one thing unlimited retention does not cover.
    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBeLessThan(60);
});

it('stamps every product with one statement, not one per product', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    Product::factory()->count(12)->create(['user_id' => $user->id]);

    $stamps = 0;
    DB::listen(function (QueryExecuted $query) use (&$stamps): void {
        if (str_contains($query->sql, 'history_kept_from') && str_starts_with(mb_trim($query->sql), 'update')) {
            $stamps++;
        }
    });

    $this->artisan('dipcatch:prune-checks')->assertSuccessful();

    // One UPDATE over the ProUsers subquery. Asking Entitlements per product
    // — the obvious implementation — would make this twelve.
    expect($stamps)->toBe(1)
        ->and(Product::query()->where('user_id', $user->id)->whereNull('history_kept_from')->count())->toBe(0);
});
