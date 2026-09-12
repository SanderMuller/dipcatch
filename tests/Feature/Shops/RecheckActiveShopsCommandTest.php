<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Support\RecheckJitter;
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

test('dispatch delay never exceeds the SQS DelaySeconds ceiling', function (): void {
    // SQS rejects `DelaySeconds` above 900 outright, so a wider configured
    // window must be clamped rather than drawn from. 40 shops against a
    // 60-minute window: uncapped, all 40 landing under 900s is a 1-in-2^40
    // event, so this pins the clamp and not a lucky draw.
    config()->set('dipcatch.recheck.interval_hours', 6);
    config()->set('dipcatch.recheck.jitter_minutes', 60);
    config()->set('dipcatch.scheduler.batch_size', 40);

    $product = Product::factory()->create();
    Shop::factory()->count(40)->for($product)->create(['last_checked_at' => now()->subDay()]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 40);

    /** @var iterable<CheckShopPrice> $jobs */
    $jobs = Queue::pushed(CheckShopPrice::class);
    foreach ($jobs as $job) {
        $delay = $job->delay;
        assert($delay instanceof DateTimeInterface);
        $seconds = $delay->getTimestamp() - now()->getTimestamp();
        expect($seconds)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(900);
    }
});

test('the uniqueness window outlasts the widest delay a dispatch can carry', function (): void {
    // `uniqueFor()` exists to hold the lock past the delayed job's start.
    // Reading a different window than the dispatch does is the drift this
    // pins: both must come from RecheckJitter.
    config()->set('dipcatch.recheck.jitter_minutes', 60);

    $shop = Shop::factory()->for(Product::factory())->create();

    expect(new CheckShopPrice($shop)->uniqueFor())
        ->toBeGreaterThan(RecheckJitter::maxSeconds());
});

test('bundle start and end boundaries bypass normal cadence once', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 24);
    config()->set('dipcatch.recheck.jitter_minutes', 0);
    $product = Product::factory()->create();
    $base = [
        'last_checked_at' => now(),
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ];
    $activation = Shop::factory()->for($product)->create($base + [
        'current_price' => '2.85',
        'promotion_starts_at' => now()->subMinute(),
        'promotion_ends_at' => now()->addDay(),
    ]);
    $expiry = Shop::factory()->for($product)->create($base + [
        'current_price' => '2.00',
        'promotion_starts_at' => now()->subDay(),
        'promotion_ends_at' => now()->subMinute(),
    ]);
    $alreadyActive = Shop::factory()->for($product)->create($base + [
        'current_price' => '2.00',
        'promotion_starts_at' => now()->subMinute(),
        'promotion_ends_at' => now()->addDay(),
    ]);
    $alreadyExpired = Shop::factory()->for($product)->create($base + [
        'current_price' => '2.85',
        'promotion_ends_at' => now()->subMinute(),
    ]);

    $this->artisan('dipcatch:recheck-offers')->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 2);
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($activation));
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($expiry));
    Queue::assertNotPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($alreadyActive));
    Queue::assertNotPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($alreadyExpired));
});
