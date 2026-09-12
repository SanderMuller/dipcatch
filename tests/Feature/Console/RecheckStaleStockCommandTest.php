<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;

/**
 * A mapping fix changes what a page means, but not the rows already written
 * under the old reading. Those rows keep telling the user the product is
 * available, and the only thing that knows better is a fresh check.
 */
beforeEach(function (): void {
    Queue::fake();
});

test('it rechecks only the in-stock rows written before the cutoff', function (): void {
    $product = Product::factory()->create();

    $stale = Shop::factory()->for($product)->create([
        'current_in_stock' => true,
        'last_checked_at' => now()->subDay(),
    ]);

    Shop::factory()->for($product)->create([
        'current_in_stock' => true,
        'last_checked_at' => now(),
    ]);

    Shop::factory()->for($product)->create([
        'current_in_stock' => false,
        'last_checked_at' => now()->subDay(),
    ]);

    $this->artisan('dipcatch:recheck-stock', ['--before' => now()->subHours(2)->toDateTimeString()])
        ->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 1);
    Queue::assertPushed(fn (CheckShopPrice $job): bool => $job->shop->is($stale));
});

test('it refuses to run without a cutoff', function (): void {
    $this->artisan('dipcatch:recheck-stock')->assertFailed();

    Queue::assertNothingPushed();
});

test('a dry run dispatches nothing', function (): void {
    Shop::factory()->create(['current_in_stock' => true, 'last_checked_at' => now()->subDay()]);

    $this->artisan('dipcatch:recheck-stock', ['--before' => now()->toDateTimeString(), '--dry-run' => true])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
