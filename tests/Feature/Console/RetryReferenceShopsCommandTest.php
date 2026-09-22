<?php declare(strict_types=1);

use App\Actions\Shops\KeepShopAsLink;
use App\Actions\Shops\ProbeBudget;
use App\Console\Commands\RetryReferenceShopsCommand;
use App\Enums\ShopKind;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopFetcher\ShopFetcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A block is a fact about today. dierapotheker.nl spent a day on a blocked
 * list while the real fault was a trailing slash DipCatch was stripping —
 * a weekly pass would have found it with nobody looking.
 */
beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

function linkOn(Product $product, string $url = 'https://shop.test/p/1'): Shop
{
    return app(KeepShopAsLink::class)($product, $url, 'blocked');
}

/**
 * @return array<string, PromiseInterface>
 */
function readablePage(string $price = '12.99'): array
{
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Now readable 500 g',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

test('a link whose page has become readable starts being tracked', function (): void {
    Http::fake(readablePage());

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    linkOn($product);

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    $shop = $product->refresh()->shops->sole();

    expect($shop->kind)->toBe(ShopKind::Tracked)
        ->and($shop->current_price)->toBe('12.99')
        // It starts life the way every other shop does: a first price check,
        // and the recompute that reads it.
        ->and($product->refresh()->cheapest_price)->toBe('12.99')
        ->and($shop->priceChecks()->count())->toBe(1);
});

test('a link whose page still refuses is left exactly as it was', function (): void {
    Http::fake([
        'https://shop.test/robots.txt' => Http::response('', 404),
        'https://shop.test/p/1' => Http::response('nope', 403),
    ]);

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    linkOn($product);

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    $shop = $product->refresh()->shops->sole();

    expect($shop->kind)->toBe(ShopKind::Reference)
        ->and($shop->retried_at)->not->toBeNull()
        // A refusal is the shop behaving exactly as recorded, not a shop
        // going wrong. Counting it would reach `dead_after` and switch the
        // feature off from the inside.
        ->and($shop->consecutive_failures)->toBe(0)
        ->and($shop->active)->toBeTrue();
});

test('a link is not asked again until its week is up', function (): void {
    Http::fake(readablePage());

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    linkOn($product)->forceFill(['retried_at' => now()->subDays(3)])->save();

    $this->artisan(RetryReferenceShopsCommand::class)
        ->expectsOutputToContain('No links are due a retry.')
        ->assertSuccessful();

    expect($product->refresh()->shops->sole()->kind)->toBe(ShopKind::Reference);

    // A week later it is.
    $this->travel(5)->days();
    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    expect($product->refresh()->shops->sole()->kind)->toBe(ShopKind::Tracked);
});

test('the retry spends nothing from the page budget', function (): void {
    // Nobody asked for it. Charging it to whoever happens to be working at
    // 04:45 would throttle them for a request they did not make.
    Http::fake(readablePage());

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'active' => true]);
    linkOn($product);

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    expect(app(ProbeBudget::class)->spend($user))->toBeNull();
});

test('a paused product leaves its links alone', function (): void {
    Http::fake(readablePage());

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => false]);
    linkOn($product);

    $this->artisan(RetryReferenceShopsCommand::class)->assertSuccessful();

    expect($product->refresh()->shops->sole()->kind)->toBe(ShopKind::Reference);
});

test('a dry run reports without fetching', function (): void {
    Http::fake(readablePage());

    $product = Product::factory()->for(User::factory())->create(['currency' => 'EUR', 'active' => true]);
    linkOn($product);

    $this->artisan(RetryReferenceShopsCommand::class, ['--dry-run' => true])
        ->expectsOutputToContain('would retry')
        ->assertSuccessful();

    expect($product->refresh()->shops->sole()->kind)->toBe(ShopKind::Reference);

    Http::assertNothingSent();
});
