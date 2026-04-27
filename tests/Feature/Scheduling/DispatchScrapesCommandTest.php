<?php declare(strict_types=1);

use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('dispatches jobs on the scrapes queue for active products with stale or null last_checked_at', function (): void {
    $due = Product::factory()->create(['active' => true, 'last_checked_at' => now()->subDays(2)]);
    $never = Product::factory()->create(['active' => true, 'last_checked_at' => null]);

    $this->artisan('dipcatch:dispatch-scrapes')->assertSuccessful();

    Queue::assertPushedOn('scrapes', ScrapeProductJob::class, fn (ScrapeProductJob $job): bool => $job->product->is($due));
    Queue::assertPushedOn('scrapes', ScrapeProductJob::class, fn (ScrapeProductJob $job): bool => $job->product->is($never));
    Queue::assertPushed(ScrapeProductJob::class, 2);
});

test('null last_checked_at sorts before stale checks', function (): void {
    config()->set('dipcatch.scheduler.batch_size', 1);
    config()->set('dipcatch.scheduler.jitter_seconds', 0);

    $stale = Product::factory()->create(['active' => true, 'last_checked_at' => now()->subDays(2)]);
    $fresh = Product::factory()->create(['active' => true, 'last_checked_at' => null]);

    $this->artisan('dipcatch:dispatch-scrapes')->assertSuccessful();

    Queue::assertPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job): bool => $job->product->is($fresh));
    Queue::assertNotPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job): bool => $job->product->is($stale));
});

test('skips inactive products and recently-checked products', function (): void {
    Product::factory()->create(['active' => false, 'last_checked_at' => now()->subDays(5)]);
    Product::factory()->create(['active' => true, 'last_checked_at' => now()->subHours(2)]);

    $this->artisan('dipcatch:dispatch-scrapes')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('respects batch_size config', function (): void {
    config()->set('dipcatch.scheduler.batch_size', 2);
    config()->set('dipcatch.scheduler.jitter_seconds', 0);

    Product::factory()->count(5)->create(['active' => true, 'last_checked_at' => now()->subDays(2)]);

    $this->artisan('dipcatch:dispatch-scrapes')->assertSuccessful();

    Queue::assertPushed(ScrapeProductJob::class, 2);
});

test('schedule registers dispatch and prune commands', function (): void {
    Artisan::call('schedule:list');
    $list = Artisan::output();

    expect($list)->toContain('dipcatch:dispatch-scrapes');
    expect($list)->toContain('dipcatch:prune-checks');
});
