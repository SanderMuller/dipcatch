<?php declare(strict_types=1);

use App\Billing\Plan;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Laravel\Cashier\Subscription;

it('blocks the product that would cross the free limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);

    expect(app(PlanLimits::class)->canAddProduct($user))->toBeFalse();

    app(PlanLimits::class)->guardProduct($user);
})->throws(PlanLimitReached::class);

it('allows the product that lands exactly on the free limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(19)->create(['user_id' => $user->id]);

    app(PlanLimits::class)->guardProduct($user);

    expect(app(PlanLimits::class)->remainingProducts($user))->toBe(1);
});

it('lets a pro account past the product limit', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    Product::factory()->count(25)->create(['user_id' => $user->id]);

    app(PlanLimits::class)->guardProduct($user);

    expect(app(PlanLimits::class)->remainingProducts($user))->toBeNull();
});

it('names the limit in the singular when the plan allows one', function (): void {
    config()->set('plans.free.max_products', 1);

    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    expect(fn () => app(PlanLimits::class)->guardProduct($user))
        ->toThrow(PlanLimitReached::class, 'Your plan tracks one product. Upgrade to Pro for unlimited products, or remove it first.');
});

it('blocks the fifth shop on a free account', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->count(4)->create(['product_id' => $product->id]);

    app(PlanLimits::class)->guardShop($product->refresh());
})->throws(PlanLimitReached::class);

it('lets a pro account past the shop limit', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->count(9)->create(['product_id' => $product->id]);

    app(PlanLimits::class)->guardShop($product->refresh());

    expect(app(PlanLimits::class)->remainingShops($product))->toBeNull();
});

it('keeps every product of a downgraded account and only blocks the next one', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);
    Product::factory()->count(60)->create(['user_id' => $user->id]);

    // The subscription lapses.
    Subscription::query()
        ->where('user_id', $user->id)
        ->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
    $user->refresh();

    expect($user->plan())->toBe(Plan::Free)
        // Nothing was deleted, deactivated or hidden.
        ->and(Product::query()->where('user_id', $user->id)->where('active', true)->count())->toBe(60)
        ->and(app(PlanLimits::class)->canAddProduct($user))->toBeFalse();
});
