<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestionVerdicts;
use App\Enums\WebFindingStatus;
use App\Livewire\Dashboard;
use App\Livewire\DashboardSuggestedShops;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionVerdict;
use App\Models\User;
use App\Models\WebShopFinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.shop_checks.accept_from', 0.7);
    Cache::flush();
    Http::preventStrayRequests();
    seedChains();
});

function dashboardProduct(User $user, string $title, int $shops = 1, string $host = 'tracked.test'): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);

    foreach (range(1, $shops) as $index) {
        Shop::factory()->for($product)->create(['url' => "https://{$host}/p/{$index}", 'pack_quantity' => '150.00', 'pack_unit' => 'g']);
    }

    return $product->refresh();
}

function storedVerdict(Product $product, string $chain, string $externalId, float $chance): void
{
    ShopSuggestionVerdict::query()->create([
        'product_id' => $product->id,
        'chain' => $chain,
        'external_id' => $externalId,
        'fingerprint' => SuggestionVerdicts::fingerprint(
            SuggestionVerdicts::evidence($product->loadMissing('shops')),
            'Beemster Extra belegen 48+ plakken',
            '150 g',
        ),
        'same_chance' => $chance,
        'checked_at' => now(),
    ]);
}

function dashboardWebFinding(Product $product, string $host, float $chance): void
{
    WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => "https://{$host}/p/1",
        'url_hash' => hash('sha256', $host),
        'host' => $host,
        'add_url' => "https://{$host}/p/1",
        'served_host' => $host,
        'search_title' => "{$product->title} at {$host}",
        'page_title' => "{$product->title} at {$host}",
        'page_price' => '4.99',
        'page_currency' => 'EUR',
        'read_at' => now(),
        'second_chance' => $chance,
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
    ]);
}

/**
 * @return list<string>
 */
function suggestedShopRows(User $user): array
{
    test()->actingAs($user);
    app()->forgetScopedInstances();

    $html = Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)->html();
    preg_match_all('/data-test="suggested-shop">(.*?)<\/li>/s', $html, $matches);

    return array_map(static fn (string $row): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($row))), $matches[1]);
}

it('puts the most likely matches first for an account with the AI check, from the dataset and the web', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);

    $cheese = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar');
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.59', link: 'beemster-dirk');
    // PLUS cannot be tracked yet, so Add would lead nowhere.
    seedRow('plus', 'Beemster Extra belegen 48+ plakken', '150 g', '3.39', link: 'beemster-plus');
    storedVerdict($cheese, 'ah', 'beemster-ah', 0.8);
    storedVerdict($cheese, 'spar', 'beemster-spar', 0.95);

    $coffee = dashboardProduct($user, 'Douwe Egberts Aroma Rood bonen');
    dashboardWebFinding($coffee, 'koffie.test', 0.9);

    $rows = suggestedShopRows($user);

    // Products in the order of their best match, each product's shops likewise.
    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toContain('SPAR')->toContain('95% match')
        ->and($rows[1])->toContain('AH')->toContain('80% match')
        // No stored answer: after the product's answered shops.
        ->and($rows[2])->toContain('Dirk')->toContain('Name match')
        ->and($rows[3])->toContain('koffie.test')->toContain('90% match')
        ->and(implode(' ', $rows))->not->toContain('PLUS');
});

it('picks the products with the most likely stored matches before the rest', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);

    // Oldest and tracked at two shops: last in line without its stored match.
    $likely = dashboardProduct($user, 'Douwe Egberts Aroma Rood bonen', shops: 2);
    $likely->forceFill(['created_at' => now()->subYear()])->save();
    dashboardWebFinding($likely, 'koffie.test', 0.9);

    foreach (range(1, 10) as $index) {
        dashboardProduct($user, "Beemster Extra belegen 48+ plakken {$index}");
    }

    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');

    expect(suggestedShopRows($user)[0])->toContain('koffie.test');
});

it('lists any dataset suggestion for an account without the AI check, closest name first and no web rows', function (): void {
    $user = User::factory()->create(['shop_checks' => false]);

    $cheese = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    seedRow('spar', 'Beemster Extra belegen kaas plakken', '150 g', '3.69', link: 'beemster-spar');
    dashboardWebFinding($cheese, 'koffie.test', 0.9);

    // Another account's product never shows.
    dashboardProduct(User::factory()->create(), 'Beemster Extra belegen 48+ plakken');

    $rows = suggestedShopRows($user);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toContain('AH')->toContain('€3.49')
        ->and($rows[1])->toContain('SPAR')
        ->and(implode(' ', $rows))->not->toContain('koffie.test')->not->toContain('match');
});

it('links each shop to its page, and Add to the product with this shop\'s comparison open', function (): void {
    $user = User::factory()->create();
    $product = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    $this->actingAs($user);

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)
        ->assertSeeHtml(e(route('app.products.show', [$product, 'add-shop' => 1, 'suggest' => 'https://www.ah.nl/producten/product/beemster-ah'])))
        ->assertSeeHtml('aria-label="Add AH to Beemster Extra belegen 48+ plakken"')
        ->assertSeeHtml('aria-label="Open AH in a new tab to check the product"')
        ->assertSeeHtml('beemster-ah');
});

it('says so when there is nothing to suggest', function (): void {
    $user = User::factory()->create();
    dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($user);

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)->assertSee('No suggested shops right now.');
});

it('loads the suggestions lazily beside Worth a look', function (): void {
    $user = User::factory()->create();
    // One shop only, so Worth a look lists it.
    dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertSee('Worth a look')
        ->assertSee('Add suggested shops to your products')
        ->assertSee('Looking for other shops that sell your products…')
        ->assertSeeHtml('lg:grid-cols-2');
});

it('asks Jev nothing while listing', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');

    expect(suggestedShopRows($user)[0])->toContain('Name match')
        // The product page takes this lock before it sends unanswered rows to Jev.
        ->and(Cache::has("shop-suggestions:verify:{$product->id}"))->toBeFalse();
});

it('shows one row per shop when the dataset and the web both find it, with the surer match', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    storedVerdict($product, 'ah', 'beemster-ah', 0.75);
    dashboardWebFinding($product, 'ah.nl', 0.9);

    $rows = suggestedShopRows($user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toContain('ah.nl')->toContain('90% match');
});

it('shows at most twelve shops, and at most three per product', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $index) {
        dashboardProduct($user, "Beemster Extra belegen 48+ plakken {$index}");
    }

    foreach (['ah', 'spar', 'dirk', 'jumbo'] as $chain) {
        seedRow($chain, 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: "beemster-{$chain}");
    }

    // Five products with four shops each: three each, and twelve in all.
    $rows = suggestedShopRows($user);

    expect($rows)->toHaveCount(12)
        ->and(collect($rows)->countBy(fn (string $row): string => (string) preg_replace('/^.*?plakken (\d).*$/', '$1', $row))->max())->toBe(3);
});
it('compares a suggested shop with the product side by side, marking the words the names do not share', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $coffee = dashboardProduct($user, 'Douwe Egberts Aroma Rood bonen');
    // The page's name for it: "Douwe Egberts Aroma Rood bonen at koffie.test".
    dashboardWebFinding($coffee, 'koffie.test', 0.9);
    $this->actingAs($user);

    $html = Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)->html();
    $panel = (string) strstr($html, 'data-test="suggested-shop-comparison"');
    preg_match_all('/data-test="title-diff-word">([^<]+)</', $panel, $marked);

    expect($html)->toContain('data-test="suggested-shop-compare"')
        ->and($panel)->toContain('https://koffie.test/p/1')->toContain('tracked.test · 150 g')
        ->and($marked[1])->toBe(['at', 'koffie.test']);
});

it('names the web suggestion in the Add link, so the form checks the variant its read picked', function (): void {
    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    dashboardWebFinding($product, 'kaas.example', 0.9);
    $findingId = WebShopFinding::query()->where('product_id', $product->id)->value('id');
    $this->actingAs($user);

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)
        ->assertSeeHtml(e(route('app.products.show', [$product, 'add-shop' => 1, 'suggest' => 'https://kaas.example/p/1', 'finding' => $findingId])));
});

it('keeps the list for a few minutes, and lists again as soon as a shop is hidden', function (): void {
    $user = User::factory()->create();
    dashboardProduct($user, 'Beemster Extra belegen 48+ plakken');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar');

    expect(suggestedShopRows($user))->toHaveCount(2);

    // A new catalogue row alone does not show until the list expires.
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.59', link: 'beemster-dirk');
    expect(suggestedShopRows($user))->toHaveCount(2);

    HiddenShop::hide($user, 'spar.nl');
    expect(collect(suggestedShopRows($user))->contains(fn (string $row): bool => str_contains($row, 'SPAR')))->toBeFalse();
});

it('serves a cached list that holds a checked date, under a store that refuses to rebuild objects', function (): void {
    // Production's cache store unserializes with `serializable_classes`
    // false, so a CarbonImmutable came back as an incomplete object and the
    // dashboard broke on its date.
    config()->set('cache.stores.array.serialize', true);
    Cache::forgetDriver('array');

    $user = User::factory()->create(['shop_checks' => true]);
    subscribeUser($user);
    $product = dashboardProduct($user, 'Douwe Egberts Aroma Rood bonen');
    dashboardWebFinding($product, 'koffie.test', 0.9);

    expect(suggestedShopRows($user))->toHaveCount(1)
        // The second read comes from the cache.
        ->and(suggestedShopRows($user)[0])->toContain('koffie.test');
});
