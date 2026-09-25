<?php declare(strict_types=1);

use App\Actions\Shops\ProbeOutcome;
use App\Livewire\Shops\AddShop;
use App\Mcp\Support\ProbeReporter;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * An Amazon page with no featured offer carries no price at all. Saying so
 * sends the person back later, where the generic message sent them to
 * request a reader for a shop DipCatch already reads.
 */
beforeEach(function (): void {
    Cache::flush();
    Cache::put('dipcatch:robots:amazon.nl', [], 3600);
});

test('the add form says Amazon has no main offer, not that the page cannot be read', function (): void {
    Http::fake(['https://www.amazon.nl/dp/B0923725WZ' => Http::response(
        '<html><body><span id="productTitle">CeraVe Moisturising Cream</span><div id="desktop_buybox"><div id="unqualifiedBuyBox"></div></div></body></html>',
    )]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://www.amazon.nl/dp/B0923725WZ')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'extraction_failed')
        ->assertSee('Amazon has no main offer for this product right now')
        ->assertDontSee('DipCatch could not read a price from that page')
        ->assertDontSee('Request a shop');

    expect(Shop::query()->count())->toBe(0);
});

test('the assistant hears the same cause', function (): void {
    $response = new ProbeReporter()->explain(ProbeOutcome::extractionFailed('amazon_no_featured_offer'));

    expect((string) $response->content())->toContain('Amazon shows no main offer for this product right now');
});
