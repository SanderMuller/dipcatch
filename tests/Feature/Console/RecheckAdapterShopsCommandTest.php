<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('rechecks every tracked shop the named adapter reads, and no other', function (): void {
    $zooplus = Shop::factory()->create(['adapter_key' => 'zooplus']);
    Shop::factory()->create(['adapter_key' => 'jsonld']);
    Shop::factory()->dead()->create(['adapter_key' => 'zooplus']);

    $this->artisan('dipcatch:recheck-adapter', ['adapter' => 'zooplus'])
        ->expectsOutputToContain('Dispatched 1 zooplus recheck(s).')
        ->assertSuccessful();

    Queue::assertPushed(CheckShopPrice::class, 1);
    Queue::assertPushed(CheckShopPrice::class, fn (CheckShopPrice $job): bool => $job->shop->is($zooplus));
});

test('a dry run counts and queues nothing', function (): void {
    Shop::factory()->count(2)->create(['adapter_key' => 'zooplus']);

    $this->artisan('dipcatch:recheck-adapter', ['adapter' => 'zooplus', '--dry-run' => true])
        ->expectsOutputToContain('2 zooplus shop(s) would be rechecked.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
