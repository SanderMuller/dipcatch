<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\RelationManagers\ShopsRelationManager;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Translation\PotentiallyTranslatedString;
use Livewire\Features\SupportTesting\Testable;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->tia()->locally();

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Assert that the value under test (a DateTimeInterface-like) has the same
 * UNIX timestamp as the expected value. Narrows the value to
 * `\Carbon\CarbonInterface` via assert() so PHPStan + Pest's template
 * inference don't trip on `?string`-typed Eloquent attributes.
 */
expect()->extend('toBeSameTimestampAs', function (DateTimeInterface $expected): Expectation {
    /** @var mixed $actual */
    $actual = $this->value;
    Assert::assertInstanceOf(DateTimeInterface::class, $actual);
    Assert::assertSame($expected->getTimestamp(), $actual->getTimestamp());

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Clear the Redis bucket a `ThrottleRequestsWithRedis` named limiter writes
 * to, for one throttle key (normally the client IP).
 *
 * `RateLimiter::clear()` does not reach this state. That facade talks to the
 * cache store (`array` under phpunit.xml.dist), while the middleware bypasses the
 * cache and drives an `Illuminate\Redis\Limiters\DurationLimiter` straight
 * on the Redis connection. So the counter lives in a real Redis server and
 * survives between test runs, which makes a throttle test fail with 429 on
 * its first request.
 *
 * The middleware key is `md5($limiterName . $key)`, or `"$limiterName:$key"`
 * when `ThrottleRequests::shouldHashKeys(false)` is set. That flag is a
 * protected static with no getter, so delete both forms.
 */
function clearRedisRateLimiter(string $limiterName, string $key = '127.0.0.1'): void
{
    Redis::connection()->del(
        md5($limiterName . $key),
        $limiterName . ':' . $key,
    );
}

/**
 * Wrap a JSON-LD payload in a minimal HTML document. The single source of
 * truth for the `<html><head><script type="application/ld+json">…</script>
 * </head></html>` shape used across adapter + Shops fixtures.
 */
function withJsonLd(string $jsonLd): string
{
    return "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";
}

/**
 * Build a single-Product JSON-LD page wrapped in HTML. The offer-block uses
 * `@type => Shop` for historical reasons; the JsonLdAdapter tolerates it, so
 * existing tests rely on that exact body. Fixing to schema.org's `Offer`
 * is a separate follow-up — would change the JSON-LD shape the adapter
 * parses.
 */
function jsonLdPage(string $price = '50.00', string $currency = 'EUR', string $title = 'Test Item'): string
{
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return withJsonLd($json);
}

/**
 * Drive an `Illuminate\Contracts\Validation\ValidationRule` against a value
 * and return the collected error messages. Use in rule-unit tests:
 *
 *     expect(runRule(new MyRule(), 'attr', $value))->toBe([]);
 *
 * @return list<string>
 */
function runRule(
    ValidationRule $rule,
    string $attribute,
    mixed $value,
): array {
    $errors = [];
    $rule->validate(
        $attribute,
        $value,
        function (string $message, ?string $attribute = null) use (&$errors): PotentiallyTranslatedString {
            $errors[] = $message;

            return new PotentiallyTranslatedString($message, app('translator'));
        },
    );

    return $errors;
}

/**
 * Fake the AH mobile API as unreachable, so ah.nl flows exercise the
 * checkjebon-dataset fallback. Merge into a wider Http::fake via the
 * returned array.
 *
 * @return array<string, PromiseInterface>
 */
function ahApiDownFakes(): array
{
    return [
        'https://api.ah.nl/*' => Http::response('down', 500),
    ];
}

/**
 * Fake the AH mobile API serving a product (trimmed replica of the
 * detail/v4 response shape, observed live 2026-08-31).
 *
 * @return array<string, PromiseInterface>
 */
function ahApiProductFakes(string $currentPrice = '1.69', string $priceBeforeBonus = '2.19', bool $isBonus = true, string $title = "Lay's Naturel", ?string $salesUnitSize = '200 g'): array
{
    $card = [
        'webshopId' => 526381,
        'title' => $title,
        'images' => [['width' => 800, 'height' => 800, 'url' => 'https://static.ah.nl/dam/product/test.webp']],
        'currentPrice' => (float) $currentPrice,
        'priceBeforeBonus' => (float) $priceBeforeBonus,
        'isBonus' => $isBonus,
        'orderAvailabilityStatus' => 'IN_ASSORTMENT',
    ];

    // A null $salesUnitSize omits the key entirely — the partial-response
    // case the authority flag must treat as non-authoritative.
    if ($salesUnitSize !== null) {
        $card['salesUnitSize'] = $salesUnitSize;
    }

    return [
        'https://api.ah.nl/mobile-auth/v1/auth/token/anonymous' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 604798,
        ]),
        'https://api.ah.nl/mobile-services/product/detail/v4/fir/*' => Http::response([
            'productId' => 526381,
            'productCard' => $card,
        ]),
    ];
}

/**
 * Trimmed replica of dirk.nl's product page: JSON-LD with the capitalized
 * `Price` key plus the Nuxt `__NUXT_DATA__` flat-array payload carrying
 * `packaging` (observed 2026-09-01).
 */
function dirkPage(string $price = '1.69', string $packaging = '150 g', string $productId = '115212'): string
{
    $jsonLd = json_encode([
        '@context' => 'http://schema.org/',
        '@type' => 'Product',
        'name' => 'Beemster Kaas extra belegen 48+ plakken',
        'offers' => ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'Price' => (float) $price],
    ], JSON_THROW_ON_ERROR);

    // Flat devalue array: index 0 = root dict, values are indices.
    $payload = json_encode([
        ['productId' => 1, 'headerText' => 2, 'packaging' => 3],
        $productId,
        'Beemster Kaas extra belegen 48+ plakken',
        $packaging,
    ], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head>'
        . '<body><script type="application/json" id="__NUXT_DATA__">' . $payload . '</script></body></html>';
}

/**
 * Trimmed replica of lidl.nl's product page: standard JSON-LD plus the Nuxt
 * `__NUXT_DATA__` flat-array payload where the product record's `price`
 * points at a price record whose `packaging.text` holds the pack size
 * (observed 2026-09-01).
 */
function lidlPage(string $price = '1.99', string $packaging = '370 g', int $productId = 10033095): string
{
    $jsonLd = json_encode([
        '@context' => 'http://schema.org',
        '@type' => 'Product',
        'name' => "LAY'S",
        'offers' => [['@type' => 'Offer', 'price' => (float) $price, 'priceCurrency' => 'EUR', 'availability' => 'InStoreOnly']],
    ], JSON_THROW_ON_ERROR);

    // Flat devalue array: object values are indices into the same array.
    $payload = json_encode([
        ['productId' => 1, 'price' => 2],
        $productId,
        ['price' => 3, 'packaging' => 4, 'currencyCode' => 6],
        (float) $price,
        ['text' => 5],
        $packaging,
        'EUR',
    ], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head>'
        . '<body><script type="application/json" id="__NUXT_DATA__">' . $payload . '</script></body></html>';
}

/**
 * Mount the per-product Shops relation manager scoped to a given product.
 * Centralises the (ownerRecord + pageClass) wiring so tests don't repeat it.
 */
function mountShopsRelationManager(Product $product): Testable
{
    return Pest\Livewire\livewire(ShopsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ]);
}

/**
 * Chains as the dataset ships them, with the base URLs a product URL is
 * built from.
 *
 * @var array<string, array{0: string, 1: string}>
 */
function suggestionChains(): array
{
    return [
        'ah' => ['AH', 'https://www.ah.nl/producten/product/'],
        'dekamarkt' => ['DekaMarkt', 'https://www.dekamarkt.nl/boodschappen/x/x/x/'],
        'dirk' => ['Dirk', 'https://www.dirk.nl/boodschappen/x/x/x/'],
        'hoogvliet' => ['Hoogvliet', 'https://www.hoogvliet.com/product/'],
        'jumbo' => ['Jumbo', 'https://www.jumbo.com/producten/'],
        'lidl' => ['Lidl (via boodschaapje.nl)', 'https://boodschaapje.nl/product/'],
        'plus' => ['PLUS', 'https://www.plus.nl/product/'],
        'poiesz' => ['Poiesz', 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/'],
        'spar' => ['SPAR', 'https://www.spar.nl/'],
        'vomar' => ['Vomar', 'https://www.vomar.nl/producten/'],
    ];
}

function seedChains(?DateTimeInterface $refreshedAt = null): void
{
    foreach (suggestionChains() as $chain => [$label, $baseUrl]) {
        CheckjebonChain::query()->create([
            'chain' => $chain,
            'label' => $label,
            'base_url' => $baseUrl,
            'refreshed_at' => $refreshedAt ?? now(),
        ]);
    }
}

function seedRow(string $chain, string $name, ?string $size, string $price = '1.00', ?DateTimeInterface $refreshedAt = null, ?string $link = null): CheckjebonPrice
{
    return CheckjebonPrice::query()->create([
        'supermarket' => $chain,
        'external_id' => $link ?? ($chain . '-' . md5($name)),
        'name' => $name,
        'size' => $size,
        'price' => $price,
        'link' => $link ?? ($chain . '-' . md5($name)),
        'refreshed_at' => $refreshedAt ?? now(),
    ]);
}

/**
 * Trimmed replica of a Poiesz webshop product page: no JSON-LD at all, only
 * the Nuxt `__NUXT_DATA__` flat-array payload whose product record carries
 * price, name, image, pack description and EAN (observed 2026-09-01). Index
 * 0 is the product; a recommended product rides along at index 1 to prove
 * the id in the URL decides.
 */
function poieszPage(string $price = '1.99', string $packageDescription = '120.00 Gram', string $productId = '278550', string $ean = '5060503500747'): string
{
    $payload = json_encode([
        ['id' => 2, 'name' => 3, 'image' => 4, 'price' => 5, 'packageDescription' => 6, 'ean' => 7],
        ['id' => 8, 'name' => 9, 'image' => 4, 'price' => 10, 'packageDescription' => 6, 'ean' => 11],
        $productId,
        "Ella's Kitchen Aardbeien met Appel 4+ Mnd.",
        'https://images.poiesz-supermarkten.nl/artikelen/278550.jpg',
        (float) $price,
        $packageDescription,
        $ean,
        '503642',
        'Zwitsal Shampoo',
        4.29,
        '8710447318539',
    ], JSON_THROW_ON_ERROR);

    return '<html><body><script type="application/json" id="__NUXT_DATA__">' . $payload . '</script></body></html>';
}

/**
 * Trimmed replica of a vomar.nl product page: a Nuxt 2 app whose SSR state
 * lives in a minified `window.__NUXT__` IIFE, so the product fields sit in
 * a `productDetails:{…}` object literal. Passing null for the description
 * reproduces a value the minifier hoisted into an IIFE parameter, which the
 * adapter must not read as a name (observed 2026-09-01).
 */
function vomarPage(string $price = '2.39', ?string $description = 'Aardappelgratin Kaas', string $articleNumber = '119614', string $ean = '8718989087319'): string
{
    $name = $description === null ? 'T' : '"' . $description . '"';

    $state = 'window.__NUXT__=(function(a,b,d,T){return {layout:a,data:[{productDetails:{'
        . 'id:"5ab39d03-f524-4daf-9011-a13ce60aa4d8",articleNumber:' . $articleNumber . ','
        . 'description:' . $name . ',contents:"500",unit:"gram",price:' . $price . ',inWebshop:d,'
        . 'primaryEan:"' . $ean . '",brand:b,labels:[],'
        . 'images:[{fileName:"product-images\u002F21070f17-ce64-430b-ae24-ef8572a67a37.png",imageType:a}]'
        . '}}]}}("x","y",true,"Vomar Ontbijtkoek"));';

    return '<html><body><h1> Ontbijtkoek </h1><script>' . $state . '</script></body></html>';
}

/**
 * Trimmed replica of a dekamarkt.nl product page: the Nuxt `__NUXT_DATA__`
 * flat-array payload with a product record (headerText, packaging, images)
 * and a price record (normalPrice, offerPrice and the offer window), both
 * keyed by productId. Its JSON-LD carries no product data (observed
 * 2026-09-01), so a page without the payload has nothing to fall back on.
 */
function dekaMarktPage(
    string $normalPrice = '1.95',
    ?string $offerPrice = '1.59',
    string $productId = '126549',
    ?string $offerStart = '-1 day',
    ?string $offerEnd = '+6 days',
    ?string $conflictingPrice = null,
): string {
    $records = [
        ['productId' => 1, 'headerText' => 2, 'packaging' => 3, 'images' => 4],
        $productId,
        'Knorr Kruidenmix airfryer knoflook kip',
        '70 g',
        [5],
        ['image' => 6, 'mainImage' => 7],
        'artikelen/492657_2026-08-25_14-04-01-473_57ec706c.png',
        true,
        [
            'productId' => 1,
            'normalPrice' => 9,
            'offerPrice' => 10,
            'startDate' => 11,
            'endDate' => 12,
        ],
        (float) $normalPrice,
        $offerPrice === null ? null : (float) $offerPrice,
        $offerStart === null ? null : now()->modify($offerStart)->toIso8601String(),
        $offerEnd === null ? null : now()->modify($offerEnd)->toIso8601String(),
    ];

    if ($conflictingPrice !== null) {
        // A second price record for the same article, stating another price.
        $records[] = ['productId' => 1, 'normalPrice' => count($records) + 1];
        $records[] = (float) $conflictingPrice;
    }

    $payload = json_encode($records, JSON_THROW_ON_ERROR);

    return '<html><body><script type="application/json" id="__NUXT_DATA__">' . $payload . '</script></body></html>';
}

/**
 * A DekaMarkt page whose offer window is stated with the exact timestamps a
 * captured page uses — an Amsterdam offset, not the test clock's own zone.
 */
function dekaMarktPageWithWindow(string $start, string $end): string
{
    $page = dekaMarktPage();

    return str_replace(
        [json_encode(now()->modify('-1 day')->toIso8601String()), json_encode(now()->modify('+6 days')->toIso8601String())],
        [json_encode($start), json_encode($end)],
        $page,
    );
}

/**
 * Trimmed replica of a spar.nl product page: JSON-LD carrying the price,
 * name, GTIN and an HTML-escaped brand, plus the offer subtitle that holds
 * the pack size the JSON-LD omits (observed 2026-09-02).
 */
function sparPage(string $price = '2.45', ?string $packSize = '200 Gram'): string
{
    $jsonLd = json_encode([
        '@context' => 'http://www.schema.org/',
        '@type' => 'Product',
        'name' => 'Lay&#39;s chips naturel',
        'gtin13' => '8710398526014',
        'image' => 'https://www.spar.nl/media/lays.png',
        'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR);

    $subtitle = $packSize === null ? '' : '<h2 class="c-offer__subtitle">' . $packSize . '</h2>';

    return '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head>'
        . '<body>' . $subtitle . '<div id="product-offer"></div>'
        . '<p class="spar-paragraph__14-400">125 Gram</p></body></html>';
}
