<?php declare(strict_types=1);

use App\Enums\WebFindingStatus;
use App\Livewire\DashboardSuggestedShops;
use App\Livewire\Settings\HiddenShops;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebShopFinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    // Showing a SPAR suggestion checks its page after the response.
    Http::fake();
    seedChains();
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', link: 'beemster-spar');
    seedRow('lidl', 'Beemster Extra belegen 48+ plakken', '150 g', '3.29', link: 'beemster-lidl');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49', link: 'beemster-ah');
});

function hidingProduct(?User $owner = null, bool $shopChecks = false): Product
{
    $owner ??= User::factory()->create(['shop_checks' => $shopChecks]);

    if ($shopChecks) {
        subscribeUser($owner);
    }

    $product = Product::factory()->for($owner)->create(['title' => 'Beemster Extra belegen 48+ plakken', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://kaasshop.test/p/1', 'pack_quantity' => '150.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

/**
 * @return list<string>
 */
function panelChains(Product $product): array
{
    test()->actingAs($product->user()->sole());
    app()->forgetScopedInstances();

    $html = Livewire::test(ShopSuggestions::class, ['product' => $product])->html();
    preg_match_all('/(Don’t suggest [^<]+?)\s*<\/button>/u', $html, $matches);

    return array_map(static fn (string $label): string => html_entity_decode(trim($label)), $matches[1]);
}

it('covers the host and its subdomains, not a host that only ends the same way', function (): void {
    expect(HiddenShop::covers(['spar.nl'], 'spar.nl'))->toBeTrue()
        ->and(HiddenShop::covers(['spar.nl'], 'www.spar.nl'))->toBeTrue()
        ->and(HiddenShop::covers(['spar.nl'], 'shop.spar.nl'))->toBeTrue()
        ->and(HiddenShop::covers(['spar.nl'], 'myspar.nl'))->toBeFalse()
        ->and(HiddenShop::covers([], 'spar.nl'))->toBeFalse();
});

it('stops suggesting a shop on every product once it is hidden from one, and tells the other panel', function (): void {
    $product = hidingProduct();
    $other = hidingProduct($product->user()->sole());
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('hideShop', 'www.spar.nl')
        ->assertDispatched('shop-suggestions-changed')
        ->assertDispatched('toast-show')
        ->assertDontSee('Don’t suggest SPAR');

    expect(HiddenShop::query()->sole()->only(['host', 'label']))->toBe(['host' => 'spar.nl', 'label' => 'SPAR'])
        ->and(panelChains($other))->not->toContain('Don’t suggest SPAR')->toContain('Don’t suggest AH');
});

it('hides Lidl under every address it is known by, by its short name', function (string $host): void {
    $product = hidingProduct();
    expect(panelChains($product))->toContain('Don’t suggest Lidl');

    HiddenShop::hide($product->user()->sole(), $host);

    expect(panelChains($product))->not->toContain('Don’t suggest Lidl')->toContain('Don’t suggest SPAR')
        ->and(HiddenShop::query()->sole()->label)->toBe('Lidl');
})->with(['lidl.nl', 'boodschaapje.nl']);

it('suggests the shop again after Undo', function (): void {
    $product = hidingProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('hideShop', 'spar.nl')
        ->dispatch('show-shop-again', host: 'spar.nl')
        ->assertSee('Don’t suggest SPAR');

    expect(HiddenShop::query()->count())->toBe(0);
});

it('hides a shop for this account only', function (): void {
    $product = hidingProduct();
    $someoneElse = hidingProduct();
    HiddenShop::hide($someoneElse->user()->sole(), 'spar.nl');

    expect(panelChains($product))->toContain('Don’t suggest SPAR');
});

it('refuses a host that is no host', function (): void {
    $product = hidingProduct();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('hideShop', '   ')
        ->assertStatus(422);

    expect(HiddenShop::query()->count())->toBe(0);
});

it('stops showing a web suggestion already found at a hidden shop', function (): void {
    $product = hidingProduct(shopChecks: true);
    WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => 'https://www.koffie.test/p/1',
        'url_hash' => hash('sha256', 'koffie'),
        'host' => 'koffie.test',
        'search_title' => 'Beemster',
        'second_chance' => 0.9,
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
    ]);
    expect(WebShopFinding::shownFor($product))->toHaveCount(1);

    HiddenShop::hide($product->user()->sole(), 'koffie.test');

    expect(WebShopFinding::shownFor($product->refresh()))->toBeEmpty();
});

it('hides a shop from the dashboard suggestions', function (): void {
    $product = hidingProduct();
    $this->actingAs($product->user()->sole());

    Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class)
        ->assertSeeHtml('aria-label="Add SPAR to Beemster Extra belegen 48+ plakken"')
        ->call('hideShop', 'spar.nl')
        ->assertDispatched('toast-show')
        ->assertDontSeeHtml('aria-label="Add SPAR to Beemster Extra belegen 48+ plakken"')
        ->assertSeeHtml('aria-label="Add AH to Beemster Extra belegen 48+ plakken"');
});

it('lists the hidden shops in settings, and Show again brings one back', function (): void {
    $user = User::factory()->create();
    HiddenShop::hide($user, 'www.spar.nl');
    HiddenShop::hide($user, 'koffie.test');
    HiddenShop::hide(User::factory()->create(), 'vomar.nl');
    $this->actingAs($user);

    Livewire::test(HiddenShops::class)
        ->assertSeeInOrder(['SPAR', 'spar.nl'])
        ->assertSee('koffie.test')
        ->assertDontSee('vomar.nl')
        ->call('showAgain', 'spar.nl')
        ->assertDontSee('SPAR');

    expect(HiddenShop::query()->where('user_id', $user->id)->pluck('host')->all())->toBe(['koffie.test']);
});

it('says how to hide a shop when none is hidden', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(HiddenShops::class)->assertSeeHtml('data-test="hidden-shops-empty"');
});

it('asks in a dialog before it stops suggesting a shop, on the product page and the dashboard', function (): void {
    $product = hidingProduct();
    $this->actingAs($product->user()->sole());

    foreach ([
        Livewire::test(ShopSuggestions::class, ['product' => $product]),
        Livewire::withoutLazyLoading()->test(DashboardSuggestedShops::class),
    ] as $list) {
        // The menu item only opens the dialog; the dialog's button hides the shop.
        $list->assertSeeHtml('data-test="hide-shop-confirm"')
            ->assertSeeHtml('$wire.hideShop(hideHost)')
            ->assertDontSeeHtml('wire:click="hideShop(');
    }
});
