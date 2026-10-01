<?php declare(strict_types=1);

use App\Actions\Products\SuggestAlert;
use App\Enums\DepthSource;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    RateLimiter::clear('shop-check:alert-suggestion:app');
});

/** A product at one 1 kg shop with a promotion label, for an account on Pro and with AI help unless told otherwise. */
function productForJev(bool $pro = true, bool $optIn = true): Product
{
    $user = User::factory()->create(['shop_checks' => $optIn]);

    if ($pro) {
        subscribeUser($user);
    }

    $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'category' => ProductCategory::PetFood]);
    Shop::factory()->for($product)->create([
        'url' => 'https://ah.nl/p/1', 'currency' => 'EUR', 'current_in_stock' => true,
        'pack_quantity' => '1000.00', 'pack_unit' => 'g',
    ])->forceFill(['current_price' => '4.00', 'promotion_label' => '2e halve prijs'])->save();

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

/** @param array<string, float> $probabilities */
function fakeDepthAnswer(array $probabilities): void
{
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([
        'answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => $probabilities]],
    ])]);
}

it('takes the band Jev is confident about', function (PromotionDepthBand $band): void {
    fakeDepthAnswer([$band->value => 0.8, PromotionDepthBand::Unknown->value => 0.2]);

    expect(app(SuggestAlert::class)->band(productForJev()))->toBe($band);
})->with([
    PromotionDepthBand::HalfOrMore,
    PromotionDepthBand::Deep,
    PromotionDepthBand::Moderate,
    PromotionDepthBand::Small,
    PromotionDepthBand::Fixed,
]);

it('asks one Choice with every band, the shops\' promotions and the category', function (): void {
    fakeDepthAnswer([PromotionDepthBand::Deep->value => 0.9]);

    app(SuggestAlert::class)->band(productForJev());

    $request = Http::recorded()->sole()[0];

    expect($request['questions'])->toHaveCount(1)
        ->and(data_get($request->data(), 'questions.' . TypeSafeClient::PROMOTION_DEPTH_QUESTION . '.criteria'))
        ->toHaveKeys(array_column(PromotionDepthBand::cases(), 'value'))
        ->and(data_get($request->data(), 'state.offers.0.promotion'))->toBe('2e halve prijs')
        ->and(data_get($request->data(), 'state.category'))->toBe(ProductCategory::PetFood->label());
});

it('lets the category decide when Jev is not confident', function (): void {
    fakeDepthAnswer([PromotionDepthBand::Deep->value => 0.4, PromotionDepthBand::Small->value => 0.35, PromotionDepthBand::Unknown->value => 0.25]);

    // Without the label, whose undated offer would hide the normal price.
    $product = productForJev();
    $product->shops()->update(['promotion_label' => null]);

    $suggestion = app(SuggestAlert::class)($product->refresh());

    expect($suggestion->band)->toBe(PromotionDepthBand::Unknown)
        ->and($suggestion->depthSource)->toBe(DepthSource::Category)
        ->and($suggestion->depth)->toBe(20);
});

it('falls back to the category when Jev fails', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 500)]);

    expect(app(SuggestAlert::class)->band(productForJev()))->toBe(PromotionDepthBand::Unknown);
});

it('sends nothing without a reason to ask', function (bool $pro, bool $optIn, string $key): void {
    Http::fake();
    config()->set('services.typesafe.key', $key);

    expect(app(SuggestAlert::class)->band(productForJev($pro, $optIn)))->toBe(PromotionDepthBand::Unknown);
    Http::assertNothingSent();
})->with([
    'a free account' => [false, true, 'test-key'],
    'a pro account without the AI shop check' => [true, false, 'test-key'],
    'no TypeSafe key' => [true, true, ''],
]);

it('stops asking once the daily budget is spent', function (): void {
    config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
    fakeDepthAnswer([PromotionDepthBand::Deep->value => 0.9]);
    $product = productForJev();

    expect(app(SuggestAlert::class)->band($product))->toBe(PromotionDepthBand::Deep)
        ->and(app(SuggestAlert::class)->band($product))->toBe(PromotionDepthBand::Unknown);
    Http::assertSentCount(1);
});

it('drops an answer for a product that changed while Jev read it', function (): void {
    $product = productForJev();
    Http::fake([TypeSafeClient::ENDPOINT => function () use ($product) {
        $product->shops()->update(['gtin' => '8710400000001']);

        return Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::Deep->value => 0.9]]]]);
    }]);

    expect(app(SuggestAlert::class)->band($product))->toBe(PromotionDepthBand::Unknown);
});

it('changes the fingerprint when what Jev reads changes', function (): void {
    $product = productForJev();
    $before = app(SuggestAlert::class)->fingerprint($product);

    $product->shops()->update(['promotion_label' => null]);

    expect(app(SuggestAlert::class)->fingerprint($product->refresh()))->not->toBe($before);
});

it('falls back when TypeSafe answers without the question', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['answers' => []])]);

    expect(app(SuggestAlert::class)->band(productForJev()))->toBe(PromotionDepthBand::Unknown);
});

it('takes a band from the confidence cut-off up', function (float $chance, PromotionDepthBand $band): void {
    fakeDepthAnswer([PromotionDepthBand::Deep->value => $chance, PromotionDepthBand::Small->value => 0.3]);

    expect(app(SuggestAlert::class)->band(productForJev()))->toBe($band);
})->with([
    'at 0.5' => [0.5, PromotionDepthBand::Deep],
    'just below' => [0.49, PromotionDepthBand::Unknown],
]);

it('drops an answer once a shop links another variant', function (): void {
    $product = productForJev();
    Http::fake([TypeSafeClient::ENDPOINT => function () use ($product) {
        $product->shops()->update(['url' => 'https://ah.nl/p/1?variant=blue']);

        return Http::response(['answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::Deep->value => 0.9]]]]);
    }]);

    expect(app(SuggestAlert::class)->band($product))->toBe(PromotionDepthBand::Unknown);
});
