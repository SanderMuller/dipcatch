<?php declare(strict_types=1);

use App\Actions\Suggestions\SuggestionVerdicts;
use App\Actions\Suggestions\SuggestShops;
use App\Livewire\Suggestions\ShopSuggestions;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionVerdict;
use App\Models\User;
use App\Services\Suggestions\ShopSuggestion;
use App\Services\TypeSafe\TypeSafeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.shop_checks.loose_match_from', 0.35);
    config()->set('dipcatch.shop_checks.reject_below', 0.3);
    config()->set('dipcatch.shop_checks.accept_from', 0.7);
    Cache::flush();
    Http::preventStrayRequests();
});

function checkedBeemsterProduct(bool $optedIn = true): Product
{
    $user = User::factory()->create(['shop_checks' => $optedIn]);
    subscribeUser($user);

    $product = Product::factory()->for($user)->create(['title' => 'Beemster Extra belegen 48+ plakken', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://kaasshop.test/p/1', 'pack_quantity' => '150.00', 'pack_unit' => 'g']);

    return $product->refresh();
}

/**
 * @return list<string>
 */
function suggestedChains(Product $product): array
{
    app()->forgetScopedInstances();

    $chains = array_map(static fn (ShopSuggestion $suggestion): string => $suggestion->chain, app(SuggestShops::class)($product));
    sort($chains);

    return $chains;
}

/**
 * The shop named in each candidate a same-product request asked about.
 *
 * @return array<string, string>
 */
function candidateShops(Request $request): array
{
    $questions = $request->data()['questions'] ?? null;
    $shops = [];

    foreach (is_array($questions) ? $questions : [] as $key => $question) {
        $shop = is_array($question) && is_array($question['instructions'] ?? null) && is_array($question['instructions']['candidate'] ?? null)
            ? ($question['instructions']['candidate']['shop'] ?? null)
            : null;

        if (is_string($key) && is_string($shop)) {
            $shops[$key] = $shop;
        }
    }

    return $shops;
}

/**
 * @param  array<string, float>  $chances
 */
function noulAnswers(array $chances): PromiseInterface
{
    return Http::response(['model' => 'jev-latest', 'answers' => array_map(static fn (float $chance): array => ['type' => 'noul', 'noul' => $chance], $chances), 'usage' => ['input_tokens' => 500, 'output_tokens' => 20]]);
}

function verdict(Product $product, CheckjebonPrice $row, float $chance): void
{
    ShopSuggestionVerdict::query()->create([
        'product_id' => $product->id,
        'chain' => $row->supermarket,
        'external_id' => $row->external_id,
        'fingerprint' => SuggestionVerdicts::fingerprint(SuggestionVerdicts::evidence($product->loadMissing('shops')), $row->name, $row->size),
        'same_chance' => $chance,
        'checked_at' => now(),
    ]);
}

test('a loose name match shows only once Jev confirms it, marked as checked', function (): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $loose = seedRow('poiesz', 'Beemster Jong belegen kaas', '150 g', '2.69');
    $product = checkedBeemsterProduct();

    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);

    expect(suggestedChains($product))->toBe(['ah']);

    verdict($product, $loose, 0.9);
    app()->forgetScopedInstances();

    $suggestions = collect(app(SuggestShops::class)($product))->keyBy('chain');

    expect($suggestions->keys()->sort()->values()->all())->toBe(['ah', 'poiesz'])
        ->and($suggestions['poiesz']->checked)->toBeTrue()
        ->and($suggestions['ah']->checked)->toBeFalse();
});

test('a close name match Jev rejects is hidden', function (): void {
    seedChains();
    $ah = seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    seedRow('dirk', 'Beemster Kaas extra belegen 48+ plakken', '150 g', '3.29');
    $product = checkedBeemsterProduct();
    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);

    verdict($product, $ah, 0.1);

    expect(suggestedChains($product))->toBe(['dirk']);
});

test('the rows Jev has no answer for are checked after the response, once, and stored', function (): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $loose = seedRow('poiesz', 'Beemster Jong belegen kaas', '150 g', '2.69');
    $product = checkedBeemsterProduct();

    Http::fake([TypeSafeClient::ENDPOINT => fn (Request $request): PromiseInterface => noulAnswers(array_map(
        static fn (string $shop): float => $shop === 'Poiesz' ? 0.85 : 0.95,
        candidateShops($request),
    ))]);

    suggestedChains($product);
    suggestedChains($product);
    app()->terminate();

    Http::assertSentCount(1);
    expect(ShopSuggestionVerdict::query()->where('product_id', $product->id)->pluck('same_chance', 'chain')->all())
        ->toMatchArray(['ah' => 0.95, 'poiesz' => 0.85])
        ->and(suggestedChains($product))->toBe(['ah', 'poiesz']);

    expect(ShopSuggestionVerdict::query()->where('chain', 'poiesz')->sole()->external_id)->toBe($loose->external_id);
});

test('without the opt-in the suggestions stay name matches and nothing is sent', function (): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    seedRow('poiesz', 'Beemster Jong belegen kaas', '150 g', '2.69');
    $product = checkedBeemsterProduct(optedIn: false);

    $suggestions = app(SuggestShops::class)($product);
    app()->terminate();

    expect(collect($suggestions)->pluck('chain')->all())->toBe(['ah'])
        ->and(collect($suggestions)->every(fn (ShopSuggestion $s): bool => ! $s->checked))->toBeTrue();
    Http::assertNothingSent();
});

test('the suggestions panel marks a row Jev confirmed', function (): void {
    seedChains();
    $ah = seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $product = checkedBeemsterProduct();
    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);
    verdict($product, $ah, 0.95);
    $this->actingAs($product->user()->sole());

    Livewire::test(ShopSuggestions::class, ['product' => $product, 'expanded' => true])
        ->assertSeeHtml('data-test="suggestion-checked"')
        ->assertSee('same product, checked by AI');
});

test('a small batch checks each chain\'s best row before any runner-up', function (): void {
    config()->set('dipcatch.shop_checks.max_candidates', 2);
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    seedRow('ah', 'Beemster Extra belegen 48+ plakken jong', '150 g', '3.59');
    seedRow('dirk', 'Beemster kaas extra belegen', '150 g', '3.29');
    $product = checkedBeemsterProduct();

    Http::fake([TypeSafeClient::ENDPOINT => function (Request $request): PromiseInterface {
        expect(array_values(candidateShops($request)))->toBe(['AH', 'Dirk']);

        return noulAnswers(array_map(static fn (): float => 0.9, candidateShops($request)));
    }]);

    suggestedChains($product);
    app()->terminate();

    Http::assertSentCount(1);
});

test('an answer about a row whose name has since changed counts as no answer', function (): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $loose = seedRow('poiesz', 'Beemster Jong belegen kaas', '150 g', '2.69');
    $product = checkedBeemsterProduct();
    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);
    verdict($product, $loose, 0.9);

    $loose->forceFill(['name' => 'Beemster Jong belegen geraspt'])->save();

    expect(suggestedChains($product))->toBe(['ah']);
});

test('a failed or spent check after the response stores nothing and breaks nothing', function (string $case): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $product = checkedBeemsterProduct();

    if ($case === 'budget spent') {
        config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
        RateLimiter::hit("shop-check:suggestions:user:{$product->user_id}", 86400);
    }

    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 500)]);

    expect(suggestedChains($product))->toBe(['ah']);
    app()->terminate();

    expect(ShopSuggestionVerdict::query()->count())->toBe(0);
    $case === 'budget spent' ? Http::assertNothingSent() : Http::assertSentCount(1);
})->with(['server error', 'budget spent']);

test('an answer given before a tracked shop or pack changed counts as no answer', function (): void {
    seedChains();
    seedRow('ah', 'Beemster Extra belegen 48+ plakken', '150 g', '3.49');
    $loose = seedRow('poiesz', 'Beemster Jong belegen kaas', '150 g', '2.69');
    $product = checkedBeemsterProduct();
    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);
    verdict($product, $loose, 0.9);

    $product->shops()->sole()->update(['url' => 'https://kaasshop.test/p/other']);

    expect(suggestedChains($product->refresh()))->toBe(['ah']);
});

test('a borderline name match waits for Jev before it shows, and shows once Jev confirms it', function (): void {
    seedChains();
    // 0.556 against the Beemster product: over the name-match floor, under
    // the hold line.
    $borderline = seedRow('dirk', 'Beemster belegen kaas plakken', '150 g', '3.29');
    $product = checkedBeemsterProduct();
    Cache::put("shop-suggestions:verify:{$product->id}", true, 600);

    expect(suggestedChains($product))->toBe([]);

    verdict($product, $borderline, 0.9);

    expect(suggestedChains($product))->toBe(['dirk']);
});

test('without the opt-in a borderline name match still shows at once', function (): void {
    seedChains();
    seedRow('dirk', 'Beemster belegen kaas plakken', '150 g', '3.29');

    expect(suggestedChains(checkedBeemsterProduct(optedIn: false)))->toBe(['dirk']);
});
