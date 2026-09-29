<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Enums\ScrapeStatus;
use App\Enums\VariantResolution;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\ShopifyAdapter;
use App\PriceAdapters\VariantCandidate;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function prometeusUrl(string $query = ''): string
{
    return 'https://www.prometeus.nl/products/fit-co-protein-bar-10-x-55-g' . $query;
}

/** The live Prometeus page, trimmed: three flavours at €12,99, and no product JSON-LD. */
function prometeusPage(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/scraper/shopify_prometeus_variants.html'));
}

/**
 * A Shopify page as small as a test needs.
 *
 * @param  list<array{id: int, price: int, name: string, public_title: ?string, available?: bool}>  $variants
 */
function shopifyPage(array $variants, string $extraHead = ''): string
{
    $meta = json_encode(['product' => ['id' => 1, 'variants' => array_map(
        static fn (array $variant): array => array_diff_key($variant, ['available' => true]),
        $variants,
    )]], JSON_THROW_ON_ERROR);
    $theme = json_encode(array_values(array_filter(array_map(
        static fn (array $variant): ?array => isset($variant['available']) ? ['id' => $variant['id'], 'available' => $variant['available']] : null,
        $variants,
    ))), JSON_THROW_ON_ERROR);

    return <<<HTML
        <html><head>
        <meta property="og:title" content="Whey Protein">
        <meta property="og:price:amount" content="19,99">
        <meta property="og:price:currency" content="EUR">
        {$extraHead}
        <script>Shopify.currency = {"active":"EUR","rate":"1.0"};</script>
        <script>var meta = {$meta};</script>
        </head><body>
        <script type="application/json" data-product-variants>{$theme}</script>
        </body></html>
        HTML;
}

it('offers a chooser for a page selling several variants when nothing names one', function (): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl(), prometeusPage());

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->failureReason)->toBe('multiple_variants')
        ->and(array_map(fn (VariantCandidate $candidate): string => $candidate->title, $result->variants))->toBe([
            'Hazelnut Chocolate / 2026-11-25',
            'Peanut Caramel / 2026-11-25',
            'Caramel Choco / 2026-11-25',
        ])
        ->and($result->variants[1]->key)->toBe(prometeusUrl('?variant=56124322185598'))
        ->and($result->variants[1]->price)->toBe('12.99')
        ->and($result->variants[1]->currency)->toBe('EUR')
        ->and($result->variants[1]->inStock)->toBeTrue();
});

it('reads the variant the URL names, under that variant\'s name', function (): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl('?variant=56124322185598'), prometeusPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('12.99')
        ->and($result->snapshot?->title)->toContain('Peanut Caramel')
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->variantsOnPage)->toBe(3)
        ->and($result->snapshot?->variantResolution)->toBe(VariantResolution::Url);
});

it('reads the variant a chosen key names, whether a variant URL, an id or a SKU', function (string $key): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl(), prometeusPage(), new AdapterContext(variantKey: $key));

    expect($result->snapshot?->title)->toContain('Peanut Caramel')
        ->and($result->snapshot?->variantResolution)->toBe(VariantResolution::VariantKey);
})->with([
    'variant URL' => prometeusUrl('?variant=56124322185598'),
    'variant id' => '56124322185598',
    'SKU' => '14792',
]);

it('never falls back to another variant when a chosen key matches none', function (): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl(), prometeusPage(), new AdapterContext(variantKey: '999'));

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->failureReason)->toBe('variant_key_no_match')
        ->and($result->unmatchedVariantKey)->toBe('999');
});

it('keeps a shop saved without a variant on the page default when it is rechecked', function (): void {
    // Saved before this reader could see variants, the shop had a price. A
    // recheck must not start failing it with a chooser nobody will answer.
    $html = shopifyPage([
        ['id' => 1, 'price' => 1999, 'name' => 'Whey - Vanilla', 'public_title' => 'Vanilla', 'available' => false],
        ['id' => 2, 'price' => 1499, 'name' => 'Whey - Cookies', 'public_title' => 'Cookies', 'available' => true],
    ]);

    $result = new ShopifyAdapter()->extract('https://shop.example/products/whey', $html, new AdapterContext(acceptPageDefault: true));

    // Shopify's own default: the first variant in stock.
    expect($result->snapshot?->price)->toBe('14.99')
        ->and($result->snapshot?->variantResolution)->toBe(VariantResolution::PageDefault)
        ->and($result->snapshot?->variantNote())->toBe('This page sells 2 variants; none was named, so this is the page default.');
});

it('never prices a variant the URL names but the page no longer sells', function (bool $recheck): void {
    // Not the page default either: the stored link names one variant, and
    // pricing another under it is the silent pick this reader exists to stop.
    $result = new ShopifyAdapter()->extract(prometeusUrl('?variant=1'), prometeusPage(), new AdapterContext(acceptPageDefault: $recheck));

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->failureReason)->toBe('variant_key_no_match');
})->with(['on a probe' => false, 'on a recheck' => true]);

it('never reads a malformed variant parameter as naming none', function (string $query): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl($query), prometeusPage(), new AdapterContext(acceptPageDefault: true));

    expect($result->isAmbiguous())->toBeTrue();
})->with(['not a number' => '?variant=abc', 'empty' => '?variant=', 'an array' => '?variant[]=56124322185598']);

it('refuses a variant URL from another product, even with an id this page lists', function (): void {
    $key = 'https://www.prometeus.nl/products/another-bar?variant=56124322185598';

    $result = new ShopifyAdapter()->extract(prometeusUrl(), prometeusPage(), new AdapterContext(variantKey: $key));

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->failureReason)->toBe('variant_key_no_match');
});

it('keeps the other parameters of the pasted URL on each variant\'s link', function (): void {
    $result = new ShopifyAdapter()->extract(prometeusUrl('?currency=EUR&variant=9'), prometeusPage());

    expect($result->variants[1]->key)->toBe(prometeusUrl('?currency=EUR&variant=56124322185598'));
});

it('refuses to price a list it could not read in full', function (): void {
    // One row without a price: counted as read, the page would sell one variant.
    $html = str_replace('"price":1299,"name":"Fit', '"price":null,"name":"Fit', prometeusPage());

    $result = new ShopifyAdapter()->extract(prometeusUrl(), $html, new AdapterContext(acceptPageDefault: true));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('shopify_variant_unreadable');
});

it('says a page with one variant sells one, under the product name', function (): void {
    $html = shopifyPage([['id' => 7, 'price' => 2999, 'name' => 'Whey Protein - Natural / 500g', 'public_title' => 'Natural / 500g']]);

    $result = new ShopifyAdapter()->extract('https://shop.example/products/whey', $html);

    expect($result->snapshot?->title)->toBe('Whey Protein')
        ->and($result->snapshot?->price)->toBe('29.99')
        ->and($result->snapshot?->inStock)->toBeNull()
        ->and($result->snapshot?->variantNote())->toBe('This page sells one variant.');
});

it('reads each variant\'s own price and stock', function (): void {
    $html = shopifyPage([
        ['id' => 1, 'price' => 1999, 'name' => 'Whey - Vanilla', 'public_title' => 'Vanilla', 'available' => true],
        ['id' => 2, 'price' => 1499, 'name' => 'Whey - Cookies', 'public_title' => 'Cookies', 'available' => false],
    ]);

    $result = new ShopifyAdapter()->extract('https://shop.example/products/whey?variant=2', $html);

    expect($result->snapshot?->price)->toBe('14.99')
        ->and($result->snapshot?->inStock)->toBeFalse()
        ->and($result->snapshot?->stockSignal)->toBe('shopify:available=false');
});

it('reads a variant name that contains the end of a script statement', function (): void {
    $html = shopifyPage([['id' => 1, 'price' => 500, 'name' => 'Bar {limited}; edition', 'public_title' => null]]);

    expect(new ShopifyAdapter()->extract('https://shop.example/products/bar', $html)->snapshot?->price)->toBe('5.00');
});

it('leaves a page that is not Shopify\'s to the other readers', function (): void {
    expect(new ShopifyAdapter()->extract('https://shop.example/p/1', '<html><head><meta property="og:price:amount" content="1"></head></html>')->isSkip())
        ->toBeTrue();
});

it('lets the Shopify list overrule JSON-LD that names only the selected variant', function (): void {
    // One Product with one offer: read alone, it says the page sells one thing.
    $jsonLd = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Whey","offers":{"@type":"Offer","price":"19.99","priceCurrency":"EUR"}}</script>';
    $html = shopifyPage([
        ['id' => 1, 'price' => 1999, 'name' => 'Whey - Vanilla', 'public_title' => 'Vanilla'],
        ['id' => 2, 'price' => 1499, 'name' => 'Whey - Cookies', 'public_title' => 'Cookies'],
    ], $jsonLd);

    expect(new JsonLdAdapter()->extract('https://shop.example/products/whey', $html)->isSkip())->toBeTrue();

    $result = app(AdapterResolver::class)->resolve('https://shop.example/products/whey', $html);

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->adapterKey)->toBe('shopify')
        ->and($result->variants)->toHaveCount(2);
});

it('keeps JSON-LD that lists every variant itself', function (): void {
    $group = [
        '@context' => 'https://schema.org',
        '@type' => 'ProductGroup',
        'name' => 'Whey',
        'hasVariant' => [
            ['@type' => 'Product', 'name' => 'Whey Vanilla', 'sku' => 'V', 'url' => 'https://shop.example/products/whey?variant=1', 'offers' => ['@type' => 'Offer', 'price' => '19.99', 'priceCurrency' => 'EUR', 'url' => 'https://shop.example/products/whey?variant=1']],
            ['@type' => 'Product', 'name' => 'Whey Cookies', 'sku' => 'C', 'url' => 'https://shop.example/products/whey?variant=2', 'offers' => ['@type' => 'Offer', 'price' => '14.99', 'priceCurrency' => 'EUR', 'url' => 'https://shop.example/products/whey?variant=2']],
        ],
    ];
    $html = shopifyPage([
        ['id' => 1, 'price' => 1999, 'name' => 'Whey - Vanilla', 'public_title' => 'Vanilla'],
        ['id' => 2, 'price' => 1499, 'name' => 'Whey - Cookies', 'public_title' => 'Cookies'],
    ], '<script type="application/ld+json">' . json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . '</script>');

    $result = app(AdapterResolver::class)->resolve('https://shop.example/products/whey?variant=2', $html);

    expect($result->adapterKey)->toBe('jsonld')
        ->and($result->snapshot?->price)->toBe('14.99');
});

it('takes a paste through the chooser to a shop that links the picked flavour', function (): void {
    Http::fake([
        'https://www.prometeus.nl/robots.txt' => Http::response('', 404),
        'https://www.prometeus.nl/products/*' => Http::response(prometeusPage(), 200, ['Content-Type' => 'text/html']),
    ]);
    $user = User::factory()->create();

    $first = app(ProbeShopUrl::class)(null, prometeusUrl(), $user);

    expect($first->isAmbiguous())->toBeTrue()
        ->and($first->variants)->toHaveCount(3);

    $picked = app(ProbeShopUrl::class)(null, prometeusUrl(), $user, variantKey: $first->variants[1]->key);

    expect($picked->isSuccess())->toBeTrue()
        ->and($picked->adapterKey)->toBe('shopify')
        ->and($picked->snapshot?->title)->toContain('Peanut Caramel')
        // Stored as the variant's own address, so a recheck names it again.
        ->and($picked->normalizedUrl)->toBe(prometeusUrl('?variant=56124322185598'));
});

it('keeps pricing a shop that was saved before its variants could be seen', function (): void {
    Http::fake([
        'https://www.prometeus.nl/robots.txt' => Http::response('', 404),
        'https://www.prometeus.nl/products/*' => Http::response(prometeusPage(), 200, ['Content-Type' => 'text/html']),
    ]);
    RateLimiter::clear(ShopFetcher::throttleKey('www.prometeus.nl'));
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => prometeusUrl(),
        'adapter_key' => 'og',
        'variant_key' => null,
        'currency' => 'EUR',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();

    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and((string) $shop->current_price)->toBe('12.99')
        ->and($shop->adapter_key)->toBe('shopify');
});
