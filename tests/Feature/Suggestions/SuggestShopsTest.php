<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestShops;
use App\Models\CheckjebonChain;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionDismissal;
use App\Services\Suggestions\ShopSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * The measured Beemster catalogue (specs/shop-suggestions.md Section 2):
 * seven chains carry the same article, three carry something else.
 */
function seedBeemsterCatalogue(): void
{
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    seedRow('dekamarkt', 'Beemster Kaas extra belegen 48+ plakken', '150 g', '3.39');
    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', '3.29');
    seedRow('jumbo', 'Beemster Extra Belegen Plakken 150 g', null, '3.49');
    seedRow('hoogvliet', 'Beemster Extra Belegen 48+ Plakken', '150 gram', '3.29');
    seedRow('plus', 'Beemster Extra Belegen plakken', 'Per 150 g', '3.39');
    seedRow('spar', 'Beemster kaas plakken belegen 48+', '150 Gram', '3.69');
    seedRow('lidl', 'Goudse kaas extra belegen plakken', '250 g', '3.14');
    seedRow('poiesz', 'Uniekaas Goudse kaasplakken 48+ belegen', '150 Gram', '2.69');
    seedRow('vomar', "G'woon aardappelkroketjes 750g", '750G', '1.69');
}

/**
 * The tracked product: a 150 g pack, tracked at one shop that belongs to no
 * dataset chain, so the chain exclusion never fires by accident.
 */
function beemsterProduct(): Product
{
    $product = Product::factory()->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);

    Shop::factory()->for($product)->create([
        'url' => 'https://kaasshop.test/p/1',
        'pack_quantity' => '150.00',
        'pack_unit' => 'g',
    ]);

    return $product->refresh();
}

function suggest(Product $product): array
{
    return app(SuggestShops::class)($product);
}

test('it offers the chains that carry the article and rejects the ones that do not', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $suggestions = suggest(beemsterProduct());

    // Seven chains carry this article; DekaMarkt is one of them but its
    // dataset links do not resolve, so it never reaches a suggestion.
    expect(collect($suggestions)->pluck('chain')->sort()->values()->all())
        ->toBe(['ah', 'dirk', 'hoogvliet', 'jumbo', 'plus', 'spar']);
});

test('it builds the product url from the chain base url and the stored link', function (): void {
    seedChains();
    seedRow('jumbo', 'Beemster Extra Belegen Plakken 150 g', null, '3.49', link: 'beemster-extra-belegen-plakken-150-g-729242ZK');

    $suggestion = suggest(beemsterProduct())[0];

    expect($suggestion->url)->toBe('https://www.jumbo.com/producten/beemster-extra-belegen-plakken-150-g-729242ZK')
        ->and($suggestion->chainLabel)->toBe('Jumbo')
        ->and($suggestion->price)->toBe('3.49');
});

test('it marks only the chains the price engine can resolve as trackable', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $trackable = collect(suggest(beemsterProduct()))
        ->filter(fn (mixed $suggestion): bool => $suggestion instanceof ShopSuggestion && $suggestion->trackable)
        ->pluck('chain')
        ->sort()
        ->values()
        ->all();

    // Hoogvliet parses through the generic adapter but returns a wrong price.
    expect($trackable)->toBe(['ah', 'dirk', 'jumbo', 'spar']);
});

test('a chain the product already tracks is never suggested', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    Shop::factory()->for($product)->create(['url' => 'https://www.ah.nl/producten/product/wi409179/beemster']);

    expect(collect(suggest($product))->pluck('chain')->all())->not->toContain('ah');
});

test('tracking lidl.nl suppresses the boodschaapje row, and the reverse', function (string $trackedUrl): void {
    seedChains();
    seedRow('lidl', 'Beemster Extra belegen 48+ plakken', '150 g', '3.19');

    $product = beemsterProduct();
    Shop::factory()->for($product)->create(['url' => $trackedUrl]);

    expect(suggest($product))->toBeEmpty();
})->with([
    'https://www.lidl.nl/p/beemster-extra-belegen/p123',
    'https://boodschaapje.nl/product/8128671',
]);

test('a dismissed row is not offered again', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    /** @var ShopSuggestion $dirk */
    $dirk = collect(suggest($product))->firstWhere('chain', 'dirk');
    $action->dismiss($product, 'dirk', $dirk->externalId);

    expect(collect(suggest($product))->pluck('chain')->all())->not->toContain('dirk');
});

test('dismissing the same row twice is idempotent', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    $action->dismiss($product, 'dirk', 'dirk-row');
    $action->dismiss($product, 'dirk', 'dirk-row');

    expect(ShopSuggestionDismissal::query()->count())->toBe(1);
});

test('equal scores break on external id, so the list is stable', function (): void {
    seedChains();
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.29', link: 'zzz');
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.19', link: 'aaa');

    $suggestions = suggest(beemsterProduct());

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->externalId)->toBe('aaa');
});

test('an empty dataset suggests nothing', function (): void {
    seedChains();

    expect(suggest(beemsterProduct()))->toBeEmpty();
});

test('a chain whose rows are older than 96 hours drops out while a fresh chain stays', function (): void {
    seedChains();
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.29', refreshedAt: now()->subHour());
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '150 g', '3.69', refreshedAt: now()->subHours(97));

    expect(collect(suggest(beemsterProduct()))->pluck('chain')->all())->toBe(['dirk']);
});

test('a non-EUR product gets no suggestions — the dataset is EUR only', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $product->forceFill(['currency' => 'USD'])->save();

    expect(suggest($product->refresh()))->toBeEmpty();
});

test('a product whose shops carry different pack sizes matches both sizes', function (): void {
    seedChains();
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', '150 g', '3.29');
    seedRow('spar', 'Beemster Extra belegen 48+ plakken', '250 g', '4.99');

    $product = beemsterProduct();
    Shop::factory()->for($product)->create([
        'url' => 'https://shop-b.test/p/2',
        'pack_quantity' => '250.00',
        'pack_unit' => 'g',
    ]);
    $product->refresh();

    expect(collect(suggest($product))->pluck('chain')->sort()->values()->all())->toBe(['dirk', 'spar']);
});

test('matching is case-insensitive — production runs a case-sensitive LIKE', function (): void {
    seedChains();
    seedRow('dirk', 'BEEMSTER EXTRA BELEGEN 48+ PLAKKEN', '150 G', '3.29');

    expect(suggest(beemsterProduct()))->toHaveCount(1);
});

test('a product with no sized shop still matches on its title alone', function (): void {
    seedChains();
    seedRow('dirk', 'Beemster Extra belegen 48+ plakken', null, '3.29');

    $product = Product::factory()->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://kaasshop.test/p/1',
        'pack_quantity' => null,
        'pack_unit' => null,
    ]);

    expect(suggest($product->refresh()))->toHaveCount(1);
});

test('a short product title still matches — no token reaches the preferred length', function (): void {
    seedChains();
    seedRow('dirk', 'Ola Big Ben', '90 ml', '1.55');

    $product = Product::factory()->create(['title' => 'Ola Big Ben', 'currency' => 'EUR']);

    expect(collect(suggest($product))->pluck('chain')->all())->toBe(['dirk']);
});

test('a second call inside one request reuses the computed suggestions', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    $action($product);

    DB::enableQueryLog();
    $action($product);

    expect(DB::getQueryLog())->toBeEmpty();
});

test('the chain set is read once per request, not once per caller', function (): void {
    // `hasUsableCatalogue()` reached the chain set directly, outside the memo
    // the class already had for suggestions. The product page renders the
    // component twice and each render calls both entry points, so the two
    // queries ran three times over.
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    $first = $action($product);

    DB::enableQueryLog();

    // The value too, not only the query count: a memo that answers once and
    // then returns nothing would satisfy an empty log.
    expect($action->hasUsableCatalogue())->toBeTrue()
        ->and($action($product))->toEqual($first)
        ->and($action->hasUsableCatalogue())->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('a second product reuses the chain set without reusing the suggestions', function (): void {
    // `__invoke()` reaches the chain set through `eligibleChains()`, but only
    // for a product the per-product memo has not seen. Asking for one is the
    // only way to exercise that path warm — a second product must still cost
    // its candidate rows, and must not inherit the first product's tracked
    // chains.
    seedChains();
    seedBeemsterCatalogue();

    $action = app(SuggestShops::class);
    $action->hasUsableCatalogue();

    $second = Product::factory()->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);

    DB::enableQueryLog();

    $suggestions = $action($second);

    $chainSetQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains((string) $entry['query'], 'checkjebon_chains')
            || str_contains((string) $entry['query'], 'max(refreshed_at)'),
    );

    expect($chainSetQueries)->toBeEmpty()
        ->and(DB::getQueryLog())->not->toBeEmpty()
        ->and(collect($suggestions)->pluck('chain')->all())->toContain('ah');
});

test('a product that already tracks every fresh chain still has a usable catalogue', function (): void {
    // `hasUsableCatalogue()` reads the fresh set, `__invoke()` the eligible
    // one. Memoizing the eligible set instead would make the panel fall
    // silent here rather than say no other shop sells this.
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');

    $product = Product::factory()->create([
        'title' => 'Beemster Extra belegen 48+ plakken',
        'currency' => 'EUR',
    ]);

    Shop::factory()->for($product)->create(['url' => 'https://www.ah.nl/producten/product/wi1/beemster']);

    $action = app(SuggestShops::class);

    expect($action($product))->toBeEmpty()
        ->and($action->hasUsableCatalogue())->toBeTrue();
});

test('an empty catalogue is read once too', function (): void {
    // `??=` memoizes an empty set; `?:` or an `empty()` guard would re-query
    // on every call, which is the one case a stale dataset guarantees.
    $action = app(SuggestShops::class);

    expect($action->hasUsableCatalogue())->toBeFalse();

    DB::enableQueryLog();

    expect($action->hasUsableCatalogue())->toBeFalse()
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('the action is scoped to the request, so the chain set cannot outlive one', function (): void {
    // The memo holds the freshness cutoff as well as the rows. Under Octane a
    // singleton would carry both into every later request the worker serves.
    $first = app(SuggestShops::class);

    expect(app(SuggestShops::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(SuggestShops::class))->not->toBe($first);
});

test('a dismissal does not re-read the chain set', function (): void {
    // A dismissal changes which rows are offered, never which chains are
    // fresh, so it clears the per-product memo and leaves this one alone.
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    /** @var ShopSuggestion $dirk */
    $dirk = collect($action($product))->firstWhere('chain', 'dirk');
    $action->dismiss($product, 'dirk', $dirk->externalId);

    DB::enableQueryLog();
    $action->hasUsableCatalogue();

    expect(DB::getQueryLog())->toBeEmpty();
});

test('dismissing drops the memo so the next call reflects it', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $product = beemsterProduct();
    $action = app(SuggestShops::class);

    /** @var ShopSuggestion $dirk */
    $dirk = collect($action($product))->firstWhere('chain', 'dirk');
    $action->dismiss($product, 'dirk', $dirk->externalId);

    expect(collect($action($product))->pluck('chain')->all())->not->toContain('dirk');
});

test('a chain whose dataset links do not resolve is never suggested', function (): void {
    seedChains();
    seedRow('dekamarkt', 'Beemster Extra belegen 48+ plakken', '150 g', '3.39', link: '454156');

    // Every DekaMarkt id in the dataset answers "Het artikel is niet
    // gevonden" — a row nobody can open is worse than no row.
    expect(suggest(beemsterProduct()))->toBeEmpty();
});

test('a price row whose chain is not recorded yet is not suggested', function (): void {
    // The state the importer now creates on purpose, between the price
    // upsert and the chain write. `freshChains()` reads the chain table, so
    // the row is never a candidate — there is no `base_url` to build a
    // product URL from, and guessing one would send the user nowhere.
    seedChains();
    seedBeemsterCatalogue();

    CheckjebonChain::query()->where('chain', 'ah')->delete();

    $suggestions = app(SuggestShops::class)(beemsterProduct());

    expect(collect($suggestions)->pluck('chain')->all())->not->toContain('ah')
        ->and($suggestions)->not->toBeEmpty();
});

test('a row of another variant is not offered, however many words match', function (): void {
    seedChains();
    // Dirk and Spar rows as the dataset holds them: the plain salame pizza.
    seedRow('dirk', 'Dr. Oetker Ristorante pizza salame', '320 g', '2.85');
    seedRow('spar', 'Dr. Oetker ristorante pizza', '320 Gram', '3.49');
    seedRow('plus', 'Dr. Oetker Ristorante pizza al salame vegano', 'Per 296 g', '4.49');

    $product = Product::factory()->create(['title' => 'Dr. Oetker Ristorante pizza al salame vegano', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://pizzashop.test/p/1', 'pack_quantity' => '296.00', 'pack_unit' => 'g']);
    // A wrong 320 g shop already on the product made the plain pizza match
    // on size too.
    Shop::factory()->for($product)->create(['url' => 'https://othershop.test/p/2', 'pack_quantity' => '320.00', 'pack_unit' => 'g']);

    expect(collect(suggest($product->refresh()))->pluck('chain')->all())->toBe(['plus']);
});

test('the vegan row is not offered for the plain product either', function (): void {
    seedChains();
    seedRow('plus', 'Dr. Oetker Ristorante pizza salame vegano', 'Per 320 g', '4.49');

    $product = Product::factory()->create(['title' => 'Dr. Oetker Ristorante pizza salame', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://pizzashop.test/p/1', 'pack_quantity' => '320.00', 'pack_unit' => 'g']);

    expect(suggest($product->refresh()))->toBe([]);
});

test('the same variant under another word still matches', function (): void {
    seedChains();
    seedRow('plus', 'Alpro Plantaardige drink amandel', 'Per 1 l', '2.49');
    seedRow('ah', 'Bonduelle Linzen biologisch', '160 g', '1.29');
    seedRow('dirk', 'Kaffee Cafeïnevrije koffiebonen', '500 g', '6.99');

    $vegan = Product::factory()->create(['title' => 'Alpro vegan drink amandel', 'currency' => 'EUR']);
    Shop::factory()->for($vegan)->create(['url' => 'https://drinkshop.test/p/1', 'pack_quantity' => '1000.00', 'pack_unit' => 'ml']);
    // Organic is a label, not a variant: bio and biologisch are one article.
    $organic = Product::factory()->create(['title' => 'Bonduelle Linzen bio', 'currency' => 'EUR']);
    Shop::factory()->for($organic)->create(['url' => 'https://blikshop.test/p/1', 'pack_quantity' => '160.00', 'pack_unit' => 'g']);
    $decaf = Product::factory()->create(['title' => 'Kaffee decaf koffiebonen', 'currency' => 'EUR']);
    Shop::factory()->for($decaf)->create(['url' => 'https://koffieshop.test/p/1', 'pack_quantity' => '500.00', 'pack_unit' => 'g']);

    expect(collect(suggest($vegan->refresh()))->pluck('chain')->all())->toBe(['plus'])
        ->and(collect(suggest($organic->refresh()))->pluck('chain')->all())->toBe(['ah'])
        ->and(collect(suggest($decaf->refresh()))->pluck('chain')->all())->toBe(['dirk']);
});
