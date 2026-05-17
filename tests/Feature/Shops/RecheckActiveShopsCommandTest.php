<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('dispatches CheckShopPrice for offers due for recheck', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 6);
    config()->set('dipcatch.recheck.jitter_minutes', 0);

    $product = Product::factory()->create(['active' => true]);
    $due = Shop::factory()->for($product)->create([
        'last_checked_at' => now()->subHours(10),
    ]);
    $never = Shop::factory()->for($product)->create(['last_checked_at' => null]);
    $fresh = Shop::factory()->for($product)->create([
        'last_checked_at' => now()->subMinutes(30),
    ]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $j): bool => $j->shop->is($due));
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $j): bool => $j->shop->is($never));
    Queue::assertNotPushed(CheckShopPrice::class, fn (CheckShopPrice $j): bool => $j->shop->is($fresh));
});

test('skips dead, inactive, and offers attached to inactive products', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 6);

    $active = Product::factory()->create(['active' => true]);
    $inactive = Product::factory()->inactive()->create();

    Shop::factory()->for($active)->dead()->create(['last_checked_at' => now()->subDay()]);
    Shop::factory()->for($active)->inactive()->create(['last_checked_at' => now()->subDay()]);
    Shop::factory()->for($inactive)->create(['last_checked_at' => now()->subDay()]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('respects scheduler batch size', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 1);
    config()->set('dipcatch.scheduler.batch_size', 2);

    $product = Product::factory()->create();
    Shop::factory()->count(5)->for($product)->create(['last_checked_at' => now()->subHours(2)]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 2);
});

test('never-checked shops are prioritised over oldest checked, then oldest first', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 6);
    config()->set('dipcatch.recheck.jitter_minutes', 0);
    config()->set('dipcatch.scheduler.batch_size', 2);

    $product = Product::factory()->create();
    $oldChecked = Shop::factory()->for($product)->create(['last_checked_at' => now()->subDays(2)]);
    Shop::factory()->for($product)->create(['last_checked_at' => now()->subHours(7)]);
    $never = Shop::factory()->for($product)->create(['last_checked_at' => null]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 2);
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $j): bool => $j->shop->is($never));
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $j): bool => $j->shop->is($oldChecked));
});

test('dispatch delay stays within configured jitter window', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 6);
    config()->set('dipcatch.recheck.jitter_minutes', 5);

    $product = Product::factory()->create();
    Shop::factory()->count(3)->for($product)->create(['last_checked_at' => now()->subDay()]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    /** @var iterable<CheckShopPrice> $jobs */
    $jobs = Queue::pushed(CheckShopPrice::class);
    foreach ($jobs as $job) {
        $delay = $job->delay;
        assert($delay instanceof DateTimeInterface);
        $seconds = $delay->getTimestamp() - now()->getTimestamp();
        expect($seconds)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(5 * 60);
    }
});
