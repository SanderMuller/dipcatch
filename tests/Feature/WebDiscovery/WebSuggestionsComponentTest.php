<?php declare(strict_types=1);

use App\Enums\WebDiscoveryState;
use App\Enums\WebFindingStatus;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
});

function webSuggestionProduct(bool $shopChecks = true): Product
{
    $user = User::factory()->create(['shop_checks' => $shopChecks]);
    subscribeUser($user);

    $product = Product::factory()->for($user)->create(['title' => 'Douwe Egberts Aroma Rood bonen 1 kg', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://tracked.test/p/1', 'host' => 'tracked.test', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

/**
 * @param  array<model-property<WebShopFinding>, mixed>  $attributes
 */
function proposedFinding(Product $product, string $host, array $attributes = []): WebShopFinding
{
    return WebShopFinding::query()->forceCreate([
        'product_id' => $product->id,
        'url' => "https://{$host}/p/1",
        'url_hash' => hash('sha256', $host),
        'host' => $host,
        'add_url' => "https://{$host}/p/1",
        'served_host' => $host,
        'search_title' => 'Douwe Egberts Aroma Rood bonen 1kg',
        'page_title' => 'Douwe Egberts Aroma Rood 1 kilo bonen',
        'page_pack_quantity' => '1000.000',
        'page_pack_unit' => 'g',
        'page_price' => '17.49',
        'page_currency' => 'EUR',
        'read_at' => now()->setDate(2026, 9, 30),
        'second_chance' => 0.9,
        'status' => WebFindingStatus::Proposed,
        'fingerprint' => WebShopFinding::fingerprintFor($product),
        ...$attributes,
    ]);
}

it('shows a proposed web suggestion with its checked price and unit price', function (): void {
    $product = webSuggestionProduct();
    proposedFinding($product, 'koffiehenk.nl');
    $this->actingAs($product->user()->sole());

    $html = preg_replace('/\s+/', ' ', strip_tags(Livewire::test(ShopSuggestions::class, ['product' => $product])->html()));

    expect($html)->toContain('koffiehenk.nl — Douwe Egberts Aroma Rood 1 kilo bonen')
        ->toContain('€17.49 /kg')
        ->toContain('€17.49 for 1 kg when checked on 30 Sep')
        ->toContain('same product, checked by AI');
});

it('hides web suggestions from an owner without shop checks', function (): void {
    $product = webSuggestionProduct(shopChecks: false);
    proposedFinding($product, 'koffiehenk.nl');
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])->assertDontSee('koffiehenk.nl');
});

it('drops a suggestion once its shop is tracked, and keeps the others', function (): void {
    $product = webSuggestionProduct();
    proposedFinding($product, 'koffiehenk.nl');
    proposedFinding($product, 'koffiezone.nl');
    $this->actingAs($product->user()->sole());

    // Adding one suggested shop, with a barcode the product did not have.
    Shop::factory()->for($product)->create(['url' => 'https://koffiehenk.nl/p/1', 'host' => 'koffiehenk.nl', 'pack_quantity' => '1000.00', 'pack_unit' => 'g', 'gtin' => '8711000000099']);

    $html = Livewire::test(ShopSuggestions::class, ['product' => $product->refresh()])->html();

    expect(substr_count($html, 'data-test="web-suggestion"'))->toBe(1)
        ->and($html)->toContain('koffiezone.nl')
        ->and($html)->not->toContain('koffiehenk.nl/p/1');
});

it('hands the read address to the add-shop form', function (): void {
    $product = webSuggestionProduct();
    $finding = proposedFinding($product, 'koffiehenk.nl', ['add_url' => 'https://koffiehenk.nl/moved']);
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('accept', 'https://koffiehenk.nl/moved', $finding->id)
        ->assertDispatched('suggest-shop', url: 'https://koffiehenk.nl/moved', findingId: $finding->id)
        ->assertSeeHtml("wire:click=\"accept('https:\\/\\/koffiehenk.nl\\/moved', {$finding->id})\"");
});

it('hides a web suggestion on this product and tells the other panel', function (): void {
    $product = webSuggestionProduct();
    $finding = proposedFinding($product, 'koffiehenk.nl');
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('dismissWeb', $finding->id)
        ->assertDispatched('shop-suggestions-changed')
        ->assertDontSee('koffiehenk.nl');

    expect($finding->refresh()->dismissed_at)->not->toBeNull();
});

it('answers 404 for a finding of another product', function (): void {
    $product = webSuggestionProduct();
    $other = webSuggestionProduct();
    $theirs = proposedFinding($other, 'koffiehenk.nl');
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->call('dismissWeb', $theirs->id)
        ->assertNotFound();

    expect($theirs->refresh()->dismissed_at)->toBeNull();
});

it('says it is looking while discovery is queued, and polls often in the first minute, then less', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->actingAs($product->user()->sole());

    $panel = Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('Looking for more shops…')
        ->assertSeeHtml('wire:poll.visible.3s');

    $this->travel(61)->seconds();

    $panel->call('$refresh')->assertSeeHtml('wire:poll.visible.15s');
});

it('shows a progress bar while it searches, timed from the queued search, with the count found so far', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->travel(40)->seconds();
    proposedFinding($product, 'koffiehenk.nl');
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSeeHtml('data-test="web-discovery-running"')
        ->assertSeeHtml('data-flux-progress')
        ->assertSeeHtml('Date.now() - 40 * 1000')
        ->assertSee('1 shop found so far');
});

it('keeps the plain line, not a bar near its end, for a search that runs past the polling window', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->travel(301)->seconds();
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('Looking for more shops…')
        ->assertDontSeeHtml('data-flux-progress');
});

it('shows a suggestion that turns proposed while the panel is open', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->actingAs($product->user()->sole());

    $panel = Livewire::test(ShopSuggestions::class, ['product' => $product])->assertDontSee('koffiehenk.nl');

    proposedFinding($product, 'koffiehenk.nl');
    WebDiscovery::mark($product, WebDiscoveryState::Done);

    $panel->call('$refresh')->assertSee('koffiehenk.nl')->assertDontSeeHtml('wire:poll');
});

it('stops polling after the polling window', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->actingAs($product->user()->sole());

    $panel = Livewire::test(ShopSuggestions::class, ['product' => $product])->assertSeeHtml('wire:poll');

    $this->travel(301)->seconds();

    $panel->call('$refresh')->assertDontSeeHtml('wire:poll')->assertSee('Looking for more shops…');
});

it('does not show a finding checked against another pack size or a removed barcode', function (): void {
    $product = webSuggestionProduct();
    proposedFinding($product, 'stale.test', ['fingerprint' => str_repeat('0', 64)]);
    proposedFinding($product, 'gone.test', ['checked_gtins' => ['8711000000001']]);
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertDontSee('stale.test')
        ->assertDontSee('gone.test');
});

it('says a barcode match is a barcode match, not an AI check', function (): void {
    $product = webSuggestionProduct();
    Shop::factory()->for($product)->create(['host' => 'other.test', 'gtin' => '8711000000007', 'pack_quantity' => '1000.00', 'pack_unit' => 'g']);
    proposedFinding($product->refresh(), 'koffiehenk.nl', ['matched_gtin' => '8711000000007', 'checked_gtins' => ['8711000000007']]);
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSee('same barcode as your product')
        ->assertDontSee('same product, checked by AI');
});

it('announces the search and its result in a live region', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::markQueued($product);
    $this->actingAs($product->user()->sole());

    $panel = Livewire::test(ShopSuggestions::class, ['product' => $product])
        ->assertSeeHtml('role="status"');
    expect(preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/.*data-test="web-discovery-status">(.*?)<\/p>.*/s', '$1', $panel->html()))))->toContain('Looking for more shops…');

    proposedFinding($product, 'koffiehenk.nl');
    WebDiscovery::mark($product, WebDiscoveryState::Done);

    $html = $panel->call('$refresh')->html();
    expect(preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/.*data-test="web-discovery-status">(.*?)<\/p>.*/s', '$1', $html))))->toContain('Found 1 more shop on the web.');
});

it('says no other shops were found only once discovery is done, and counts both lists', function (): void {
    seedChains();
    // A fresh catalogue without a match, so the panel may say it found nothing.
    seedRow('spar', 'Unrelated washing powder', '1 kg', '4.99');
    $product = webSuggestionProduct();
    $this->actingAs($product->user()->sole());

    WebDiscovery::markQueued($product);
    Livewire::test(ShopSuggestions::class, ['product' => $product, 'explainEmpty' => true])->assertDontSee('No shop suggestions for this product');

    WebDiscovery::mark($product, WebDiscoveryState::Done);
    Livewire::test(ShopSuggestions::class, ['product' => $product, 'explainEmpty' => true])->assertSee('No shop suggestions for this product');

    seedRow('spar', 'Douwe Egberts Aroma Rood bonen 1 kg', '1 kg', '17.99', link: 'de-aroma-rood/');
    proposedFinding($product, 'koffiehenk.nl');
    // SuggestShops keeps its answer for the request; a new one reads the new row.
    app()->forgetScopedInstances();

    $html = (string) preg_replace('/\s+/', ' ', strip_tags(Livewire::test(ShopSuggestions::class, ['product' => $product])->html()));
    expect($html)->toContain('Also sold at 2')
        ->and((int) strpos($html, 'SPAR'))->toBeLessThan((int) strpos($html, 'koffiehenk.nl'));
});

it('keeps looking while a current finding still waits for its second check', function (): void {
    $product = webSuggestionProduct();
    WebDiscovery::mark($product, WebDiscoveryState::Done);
    proposedFinding($product, 'koffiehenk.nl', ['status' => WebFindingStatus::Read]);
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product])->assertSee('Looking for more shops…');
});
