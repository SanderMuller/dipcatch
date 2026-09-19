<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;
use App\Models\Product;
use App\Models\Shop;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('dipcatch.categories.min_path_score', 0.5);
    config()->set('dipcatch.categories.min_separation', 1.5);
    Http::preventStrayRequests();
});

function productWithShop(): Product
{
    $product = Product::factory()->create(['title' => 'Douwe Egberts Aroma Rood 500 g', 'currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://www.ah.nl/producten/product/wi123/douwe-egberts-aroma-rood',
        'current_price' => '5.49',
        'pack_quantity' => '500.00',
        'pack_unit' => 'g',
        'gtin' => '8711000530450',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id, 'cheapest_price' => '5.49'])->save();

    return $product->fresh() ?? $product;
}

it('stores the best-scoring path even when it sits under a department that is not the greedy pick', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(
        ['food' => 0.45, 'pets' => 0.40, 'other' => 0.05, 'home' => 0.10],
        ['food' => ['pantry' => 0.3, 'snacks_sweets' => 0.3, 'frozen' => 0.2, 'bakery' => 0.2], 'pets' => ['pet_food' => 0.95, 'pet_care' => 0.05]],
    ))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    // sqrt(0.40 × 0.95) = 0.62 beats sqrt(0.45 × 0.30) = 0.37.
    expect($verdict->category)->toBe(ProductCategory::PetFood)
        ->and($verdict->runnerUp)->toBe(ProductCategory::Pantry)
        ->and($verdict->pathScore)->toBeGreaterThan(0.6)
        ->and($verdict->inputTokens)->toBe(1200)
        ->and($verdict->outputTokens)->toBe(90);
});

it('leaves the product uncategorised when Other tops the department answer, even if a real path would score higher', function (): void {
    // other at 0.35 with a one-leaf probability of 1.0 would give 0.59 and
    // beat food.coffee_tea at sqrt(0.30 × 0.9) = 0.52; Other is judged alone.
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(
        ['other' => 0.35, 'food' => 0.30, 'home' => 0.35],
        ['food' => ['coffee_tea' => 0.9, 'pantry' => 0.1]],
    ))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    expect($verdict->category)->toBeNull()
        ->and($verdict->winner)->toBe(ProductCategory::CoffeeTea);
});

it('leaves the product uncategorised below the path-score floor', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(
        ['food' => 0.4, 'home' => 0.3, 'pets' => 0.3],
        ['food' => ['coffee_tea' => 0.5, 'pantry' => 0.5]],
    ))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    // sqrt(0.4 × 0.5) = 0.45 < 0.5
    expect($verdict->category)->toBeNull()
        ->and($verdict->pathScore)->toBeLessThan(0.5);
});

it('leaves the product uncategorised when two leaves score within the separation ratio', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(
        ['food' => 0.9, 'home' => 0.1],
        ['food' => ['coffee_tea' => 0.5, 'soft_drinks' => 0.45, 'pantry' => 0.05]],
    ))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    expect($verdict->category)->toBeNull()
        ->and($verdict->winner)->toBe(ProductCategory::CoffeeTea)
        ->and($verdict->runnerUp)->toBe(ProductCategory::SoftDrinks)
        ->and($verdict->separation)->toBeLessThan(1.5);
});

it('treats a runner-up at zero as perfect separation', function (): void {
    $leaves = [];

    foreach (ProductDepartment::real() as $department) {
        $leaves[$department->value] = array_fill_keys(
            array_map(static fn (ProductCategory $c): string => $c->leafKey(), $department->categories()),
            0.0,
        );
    }

    $leaves['food']['coffee_tea'] = 1.0;

    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 1.0], $leaves))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    expect($verdict->category)->toBe(ProductCategory::CoffeeTea)
        ->and($verdict->separation)->toBe(INF);
});

it('throws when a leaf answer is missing, so a partial answer never lets another department win', function (): void {
    // food 0.5 / home 0.5 with leaf_food absent would let home.kitchen at
    // 0.9 score sqrt(0.45) = 0.67 and be stored on half the evidence.
    $answer = typesafeAnswer(['food' => 0.5, 'home' => 0.5], ['home' => ['kitchen' => 0.9, 'furniture' => 0.1]]);
    unset($answer['answers']['leaf_food']);

    Http::fake([TypeSafeClient::ENDPOINT => Http::response($answer)]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, 'leaf_food');
});

it('throws when an answer carries none of the keys the request asked for', function (): void {
    $answer = typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]);
    $answer['answers']['leaf_food']['probabilities'] = ['espresso' => 0.7, 'lungo' => 0.3];

    Http::fake([TypeSafeClient::ENDPOINT => Http::response($answer)]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, 'no recognisable probability');
});

it('retries once after a rate limit', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::sequence()
        ->push(['error' => 'slow down'], 429)
        ->push(typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    expect($verdict->category)->toBe(ProductCategory::CoffeeTea);
    Http::assertSentCount(2);
});

it('throws when the body is not JSON', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response('<html>maintenance</html>', 200)]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, 'not JSON');
});

it('sends the bearer token, the evidence from the cheapest shop and one leaf question per real department', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]))]);

    app(TypeSafeClient::class)->categorise(productWithShop());

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $questions = is_array($body['questions'] ?? null) ? array_keys($body['questions']) : [];

        expect($request->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue()
            ->and($body['model'])->toBe('jev-latest')
            ->and($body['state'])->toBe([
                'product_title' => 'Douwe Egberts Aroma Rood 500 g',
                'pack_size' => '500 g',
                'gtin' => '8711000530450',
                'price' => '5.49 EUR',
                'offers' => [[
                    'shop' => 'ah.nl',
                    'url_path' => '/producten/product/wi123/douwe-egberts-aroma-rood',
                ]],
            ])
            ->and($questions)->toContain('department', 'leaf_food', 'leaf_car_travel')
            ->and($questions)->not->toContain('leaf_other')
            ->and($questions)->toHaveCount(1 + count(ProductDepartment::real()))
            ->and($body['questions']['department']['criteria'])->toHaveKey('other')
            ->and($body['questions']['leaf_pets']['criteria'])->toHaveKeys(['pet_food', 'pet_care', 'pet_accessories']);

        return true;
    });
});

it('sends the title alone for a product without shops', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]))]);
    $product = Product::factory()->create(['title' => 'Hand-made product']);

    app(TypeSafeClient::class)->categorise($product);

    Http::assertSent(function (Request $request): bool {
        expect($request->data()['state'])->toBe(['product_title' => 'Hand-made product', 'offers' => []]);

        return true;
    });
});

it('omits the GTIN, pack size and price the evidence shop does not carry', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]))]);
    $product = Product::factory()->create(['title' => 'Mystery item']);
    Shop::factory()->for($product)->create([
        'url' => 'https://example.com/p/mystery',
        'current_price' => null,
        'pack_quantity' => null,
        'pack_unit' => null,
        'gtin' => null,
    ]);

    app(TypeSafeClient::class)->categorise($product->fresh() ?? $product);

    Http::assertSent(function (Request $request): bool {
        $state = $request->data()['state'];

        expect($state)->not->toHaveKeys(['gtin', 'pack_size', 'price'])
            ->and($state['offers'][0]['shop'])->toBe('example.com');

        return true;
    });
});

it('retries once after a server error and uses the second answer', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::sequence()
        ->push(['error' => 'boom'], 503)
        ->push(typesafeAnswer(['food' => 1.0], ['food' => ['coffee_tea' => 1.0]]))]);

    $verdict = app(TypeSafeClient::class)->categorise(productWithShop());

    expect($verdict->category)->toBe(ProductCategory::CoffeeTea);
    Http::assertSentCount(2);
});

it('throws after two server errors', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::sequence()->push([], 500)->push([], 500)]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, '500');
    Http::assertSentCount(2);
});

it('does not retry a rejected key', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, '401');
    Http::assertSentCount(1);
});

it('throws when the connection fails on both attempts', function (): void {
    // A request that never connects is not recorded as sent, so the attempts
    // are counted by hand.
    $attempts = 0;
    Http::fake([TypeSafeClient::ENDPOINT => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('timed out');
    }]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class, 'unreachable')
        ->and($attempts)->toBe(2);
});

it('throws when the answer carries no department probabilities', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['model' => 'jev-latest', 'answers' => []])]);

    expect(fn () => app(TypeSafeClient::class)->categorise(productWithShop()))
        ->toThrow(TypeSafeRequestFailed::class);
});
