<?php declare(strict_types=1);

use App\Enums\WebFindingStatus;
use App\Livewire\Shops\AddShop;
use App\Livewire\Suggestions\ReviewSuggestedShops;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionDismissal;
use App\Models\User;
use App\Models\WebShopFinding;
use App\Support\DashboardSuggestions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    seedChains();
});

function reviewProduct(User $user, string $title): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://tracked.test/p/' . hash('sha256', $title), 'pack_quantity' => '150.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

/**
 * Two dashboard suggestions for a cheese and one for a coffee.
 *
 * @return array{0: User, 1: Product, 2: Product}
 */
function reviewQueueFixture(): array
{
    $user = User::factory()->create();
    $cheese = reviewProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar');
    $coffee = reviewProduct($user, 'Douwe Egberts Aroma Rood bonen');
    seedRow('jumbo', 'Douwe Egberts Aroma Rood bonen', '150 g', '6.49', link: 'de-rood');
    test()->actingAs($user);

    return [$user, $cheese, $coffee];
}

/** @return list<string> */
function dashboardHosts(User $user, Product $product): array
{
    return array_values(array_map(
        static fn (array $row): string => $row['host'],
        array_filter(app(DashboardSuggestions::class)->forUser($user), static fn (array $row): bool => $row['product']->is($product)),
    ));
}

it('starts at the chosen shop, then the same product\'s other shops, then the next product', function (): void {
    [$user, $cheese, $coffee] = reviewQueueFixture();
    [$first, $second] = dashboardHosts($user, $cheese);

    $component = Livewire::test(ReviewSuggestedShops::class)
        ->dispatch('review-suggested-shop', productId: (string) $cheese->id, host: $second);

    expect($component->get('queue'))->toHaveCount(3)->sequence(
        fn ($item) => $item->toMatchArray(['host' => $second, 'product' => (string) $cheese->id]),
        fn ($item) => $item->toMatchArray(['host' => $first, 'product' => (string) $cheese->id]),
        fn ($item) => $item->toMatchArray(['host' => 'jumbo.com', 'product' => (string) $coffee->id]),
    )->and($component->get('total'))->toBe(3);

    $component->assertSee('Suggestion 1 of 3')
        ->assertSeeLivewire(AddShop::class)
        ->assertSee('Beemster Extra belegen 48+ plakken');
});

it('moves on after each add, decline or skip, and sums up at the end', function (): void {
    [, $cheese] = reviewQueueFixture();

    $component = Livewire::test(ReviewSuggestedShops::class)
        ->dispatch('review-suggested-shop', productId: (string) $cheese->id, host: 'ah.nl')
        ->dispatch('shop-added', offerId: 'x')
        ->assertSee('Suggestion 2 of 3')
        ->dispatch('suggestion-reviewed', outcome: 'declined')
        ->assertSee('Suggestion 3 of 3')
        ->assertSee('Douwe Egberts Aroma Rood bonen')
        ->dispatch('suggestion-reviewed', outcome: 'skipped')
        ->assertSee('All suggestions reviewed')
        ->assertSee('You added 1 shop.')
        ->assertSee('1 suggestion is hidden for its product.')
        ->assertSee('1 skipped suggestion stays on the dashboard.');

    $component->call('finish')->assertDispatched('suggested-shops-reviewed')->assertSet('queue', []);
});

it('leaves the dashboard list alone when the dialog closes after skips only', function (): void {
    [, $cheese] = reviewQueueFixture();

    Livewire::test(ReviewSuggestedShops::class)
        ->dispatch('review-suggested-shop', productId: (string) $cheese->id, host: 'ah.nl')
        ->dispatch('suggestion-reviewed', outcome: 'skipped')
        ->call('finish')
        ->assertNotDispatched('suggested-shops-reviewed');
});

it('skips a product paused while the dialog was open', function (): void {
    [$user, $cheese, $coffee] = reviewQueueFixture();
    [$first] = dashboardHosts($user, $cheese);

    $component = Livewire::test(ReviewSuggestedShops::class)
        ->dispatch('review-suggested-shop', productId: (string) $coffee->id, host: 'jumbo.com');

    $cheese->update(['active' => false]);

    $component->dispatch('suggestion-reviewed', outcome: 'skipped')
        ->assertSee('All suggestions reviewed')
        ->assertDontSee($first);
});

it('says so when the chosen suggestion is no longer on the list, and never reviews another account\'s product', function (): void {
    [, $cheese] = reviewQueueFixture();
    $this->actingAs(User::factory()->create());

    Livewire::test(ReviewSuggestedShops::class)
        ->dispatch('review-suggested-shop', productId: (string) $cheese->id, host: 'ah.nl')
        ->assertSet('queue', [])
        ->assertSee('This suggestion is no longer there');
});

it('reads the suggested page once the dialog shows, next to the product', function (): void {
    Http::fake(fakeJsonLdOffer('https://shop.example.com/p/1', '3.29', name: 'Beemster Extra belegen 48+ plakken'));
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://shop.example.com/p/1', 'shop' => 'Example', 'findingId' => null, 'chain' => null, 'externalId' => null]])
        ->assertSee('Reading the product page on Example.')
        ->call('startReview')
        ->assertSet('state', 'preview')
        ->assertSee('€3.29')
        ->assertSee('Add Example')
        ->assertSeeHtml('data-test="review-product"');
});

it('hides a declined dataset suggestion for the product', function (): void {
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://www.ah.nl/producten/product/beemster-ah', 'shop' => 'AH', 'findingId' => null, 'chain' => 'ah', 'externalId' => 'beemster-ah']])
        ->call('decline')
        ->assertDispatched('suggestion-reviewed', outcome: 'declined');

    expect(ShopSuggestionDismissal::query()->where('product_id', $product->id)->where('chain', 'ah')->where('external_id', 'beemster-ah')->exists())->toBeTrue();
});

it('dismisses a declined web suggestion, only for its own product', function (): void {
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $other = reviewProduct(User::factory()->create(), 'Douwe Egberts Aroma Rood bonen');
    $finding = fn (Product $for): int => WebShopFinding::query()->forceCreate([
        'product_id' => $for->id,
        'url' => 'https://kaas.example/p/1',
        'url_hash' => hash('sha256', 'kaas.example' . $for->id),
        'host' => 'kaas.example',
        'search_title' => 'Beemster at kaas.example',
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($for),
    ])->id;
    $own = $finding($product);
    $foreign = $finding($other);
    $this->actingAs($product->user()->sole());

    foreach ([$own, $foreign] as $findingId) {
        Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://kaas.example/p/1', 'shop' => 'kaas.example', 'findingId' => $findingId, 'chain' => null, 'externalId' => null]])
            ->call('decline');
    }

    expect(WebShopFinding::query()->find($own)->dismissed_at)->not->toBeNull()
        ->and(WebShopFinding::query()->find($foreign)->dismissed_at)->toBeNull();
});

it('skips without hiding anything', function (): void {
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://www.ah.nl/producten/product/beemster-ah', 'shop' => 'AH', 'findingId' => null, 'chain' => 'ah', 'externalId' => 'beemster-ah']])
        ->call('skip')
        ->assertDispatched('suggestion-reviewed', outcome: 'skipped');

    expect(ShopSuggestionDismissal::query()->count())->toBe(0);
});

it('keeps the reviewed suggestion locked', function (): void {
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://www.ah.nl/x', 'shop' => 'AH', 'findingId' => null, 'chain' => 'ah', 'externalId' => 'x']])
        ->set('review.url', 'http://169.254.169.254/');
})->throws(CannotUpdateLockedPropertyException::class);

it('says so when the suggested page cannot be read, and offers no add', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Not found', 404),
    ]);
    $product = reviewProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product, 'review' => ['url' => 'https://shop.example.com/p/1', 'shop' => 'Example', 'findingId' => null, 'chain' => null, 'externalId' => null]])
        ->call('startReview')
        ->assertSet('state', 'error')
        ->assertSeeHtml('data-test="review-page-error"')
        ->assertSeeHtml('data-test="review-decline"')
        ->assertSeeHtml('data-test="review-skip"');
});
