<?php declare(strict_types=1);

use App\Actions\Shops\TrackedElsewhere;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Adding a shop only ever checked for a duplicate inside the product being
 * added to. Across products the same URL is permitted on purpose — a variant
 * page serves several — so an accidental second copy said nothing, and one
 * account ended up with two "Fanta Cassis 1,5 L" products on one URL, both
 * alerting on the same fall.
 */
function trackedProduct(User $user, string $title, string $url, ?string $variantKey = null): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);

    Shop::factory()->for($product)->create(['url' => $url])
        ->forceFill(['currency' => 'EUR', 'current_price' => '2.85', 'variant_key' => $variantKey])
        ->save();

    return $product->refresh();
}

it('names the other product already tracking the page', function (): void {
    $user = User::factory()->create();
    trackedProduct($user, 'Fanta Cassis 1,5 L', 'https://www.ah.nl/producten/product/wi156794/fanta-cassis');

    $titles = TrackedElsewhere::productTitles($user->id, 'https://www.ah.nl/producten/product/wi156794/fanta-cassis', variantKey: null);

    expect($titles)->toBe(['Fanta Cassis 1,5 L'])
        ->and(TrackedElsewhere::note($titles))->toContain('Fanta Cassis 1,5 L');
});

it('says nothing when the same page is tracked under a different variant', function (): void {
    // The legitimate case, and the reason this is a note rather than a refusal:
    // one page really does sell several things.
    $user = User::factory()->create();
    trackedProduct($user, 'Barebells Salty Peanut', 'https://realsupps.nl/products/barebells', 'salty-peanut');

    $titles = TrackedElsewhere::productTitles($user->id, 'https://realsupps.nl/products/barebells', 'creamy-crisp');

    expect($titles)->toBeEmpty();
});

it('never names another account\'s products', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    trackedProduct($theirs, 'Their private product', 'https://shop.test/p/1');

    expect(TrackedElsewhere::productTitles($mine->id, 'https://shop.test/p/1', variantKey: null))->toBeEmpty();
});

it('does not name the product being added to', function (): void {
    $user = User::factory()->create();
    $product = trackedProduct($user, 'Fanta Cassis', 'https://shop.test/p/1');

    expect(TrackedElsewhere::productTitles($user->id, 'https://shop.test/p/1', variantKey: null, excludeProductId: $product->id))->toBeEmpty();
});

it('warns in the add_shop preview without refusing the add', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd(json_encode([
            '@type' => 'Product',
            'name' => 'Fanta Cassis',
            'offers' => ['@type' => 'Offer', 'price' => '2.85', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ], JSON_THROW_ON_ERROR)), 200, ['Content-Type' => 'text/html']),
    ]);

    $user = User::factory()->create();
    trackedProduct($user, 'Fanta Cassis 1,5 L', 'https://shop.example.com/p/1');
    $other = Product::factory()->for($user)->create(['title' => 'Fanta Cassis duplicate', 'currency' => 'EUR']);

    DipCatchServer::actingAs($user)
        ->tool(AddShopTool::class, ['product_id' => (string) $other->id, 'url' => 'https://shop.example.com/p/1'])
        ->assertOk()
        ->assertSee('already tracked on')
        ->assertSee('Fanta Cassis 1,5 L')
        // Still a preview with a draft: the caller decides, the tool does not.
        ->assertSee('draft');
});
