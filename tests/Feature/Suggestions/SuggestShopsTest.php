<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestShops;
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

test('it offers the seven chains that carry the article and rejects the three that do not', function (): void {
    seedChains();
    seedBeemsterCatalogue();

    $suggestions = suggest(beemsterProduct());

    expect(collect($suggestions)->pluck('chain')->sort()->values()->all())
        ->toBe(['ah', 'dekamarkt', 'dirk', 'hoogvliet', 'jumbo', 'plus', 'spar']);
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

    expect(suggest($product))->toBe([]);
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

    expect(suggest(beemsterProduct()))->toBe([]);
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

    expect(suggest($product->refresh()))->toBe([]);
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
