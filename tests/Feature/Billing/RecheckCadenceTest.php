<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function shopFor(User $user, ?CarbonImmutable $lastCheckedAt): Shop
{
    $product = Product::factory()->create(['user_id' => $user->id, 'active' => true]);

    return Shop::factory()->create([
        'product_id' => $product->id,
        'active' => true,
        'last_checked_at' => $lastCheckedAt,
    ]);
}

function proUser(): User
{
    $user = User::factory()->create();

    subscribeUser($user);

    return $user;
}

it('rechecks a pro shop after two hours while the free shop waits for six', function (): void {
    Queue::fake();

    $threeHoursAgo = CarbonImmutable::now()->subHours(3);
    $proShop = shopFor(proUser(), $threeHoursAgo);
    $freeShop = shopFor(User::factory()->create(), $threeHoursAgo);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($proShop));
    Queue::assertNotPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($freeShop));
});

it('still rechecks a free shop once its own interval has passed', function (): void {
    Queue::fake();

    $shop = shopFor(User::factory()->create(), CarbonImmutable::now()->subHours(7));

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($shop));
});

it('does not let a pro backlog starve free accounts', function (): void {
    Queue::fake();

    // One tick of capacity, and more due Pro work than fits. The free shop
    // is the oldest, so a fair queue takes it first.
    config()->set('dipcatch.scheduler.batch_size', 1);

    $pro = proUser();
    shopFor($pro, CarbonImmutable::now()->subHours(3));
    shopFor($pro, CarbonImmutable::now()->subHours(4));
    $freeShop = shopFor(User::factory()->create(), CarbonImmutable::now()->subDays(2));

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 1);
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($freeShop));
});

it('drops a blocked account back to the free cadence', function (): void {
    Queue::fake();

    $user = proUser();
    $user->forceFill(['billing_blocked_at' => now()])->save();
    $shop = shopFor($user, CarbonImmutable::now()->subHours(3));

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertNotPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($shop));
});
