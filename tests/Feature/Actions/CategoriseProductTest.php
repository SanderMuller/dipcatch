<?php declare(strict_types=1);

use App\Actions\Products\CategoriseProduct;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Livewire\Products\CreateProductFromUrl;
use App\Livewire\Products\CreateProductManual;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Support\DraftToken;
use App\Mcp\Tools\CreateProductTool;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Cashier\Subscription;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

/**
 * A confident "food.coffee_tea" answer, with every other leaf question spread
 * evenly, faked at the TypeSafe endpoint and stacked on the create-flow fakes.
 *
 * @param  array<string, mixed>  $otherFakes
 */
function fakeConfidentCoffee(array $otherFakes = []): void
{
    Http::fake($otherFakes + [
        TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]])),
    ]);
}

function proUserWantingCategories(): User
{
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);

    return $user;
}

it('categorises a product created from a URL once the request has terminated', function (): void {
    fakeConfidentCoffee(fakeJsonLdOffer(name: 'Douwe Egberts Aroma Rood'));
    $user = proUserWantingCategories();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm')
        ->assertHasNoErrors();

    // The Livewire test harness sends its calls through the HTTP kernel,
    // whose terminate() fires the registered callback; a plain component
    // call would need an explicit app()->terminate() here.
    app()->terminate();

    $product = Product::query()->where('user_id', $user->id)->sole();

    Http::assertSent(fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT);
    expect($product->category)->toBe(ProductCategory::CoffeeTea)
        ->and($product->category_set_by)->toBe(CategorySource::Auto);
});

it('categorises a product created by hand after the response', function (): void {
    fakeConfidentCoffee();
    $user = proUserWantingCategories();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('currency', 'EUR')
        ->call('save')
        ->assertHasNoErrors();

    app()->terminate();

    Http::assertSentCount(1);
    expect(Product::query()->where('user_id', $user->id)->sole()->category)->toBe(ProductCategory::CoffeeTea);
});

it('categorises a product created over MCP after the response', function (): void {
    fakeConfidentCoffee();
    $user = proUserWantingCategories();
    $draft = DraftToken::issue($user, ['title' => 'Coffee 500 g', 'price' => '2.00', 'currency' => 'EUR', 'in_stock' => true], 'https://ah.nl/p/coffee', 'ah', variantKey: null);

    DipCatchServer::actingAs($user)
        ->tool(CreateProductTool::class, ['draft' => $draft, 'confirm' => true])
        ->assertOk();

    app()->terminate();

    Http::assertSentCount(1);
    expect($user->products()->sole()->category)->toBe(ProductCategory::CoffeeTea);
});

it('sends nothing for a free account, an opted-out account, or without a key', function (string $case): void {
    fakeConfidentCoffee();
    $user = match ($case) {
        'free account with the switch on' => User::factory()->create(['auto_categories' => true]),
        'Pro account with the switch off' => tap(User::factory()->create(['auto_categories' => false]), fn (User $u): Subscription => subscribeUser($u)),
        default => tap(proUserWantingCategories(), fn () => config()->set('services.typesafe.key', '')),
    };
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('currency', 'EUR')
        ->call('save')
        ->assertHasNoErrors();

    app()->terminate();

    Http::assertNothingSent();
    expect(Product::query()->where('user_id', $user->id)->sole()->category)->toBeNull();
})->with(['free account with the switch on', 'Pro account with the switch off', 'no key configured']);

it('sends nothing when the category was already set at creation', function (): void {
    fakeConfidentCoffee();
    $user = proUserWantingCategories();
    $product = Product::factory()->categorised(ProductCategory::PetFood)->create(['user_id' => $user->id]);

    CategoriseProduct::afterResponseFor($product);
    app()->terminate();

    Http::assertNothingSent();
});

it('stops when the person chose a category before the callback ran', function (): void {
    fakeConfidentCoffee();
    $user = proUserWantingCategories();
    $product = Product::factory()->create(['user_id' => $user->id]);

    CategoriseProduct::afterResponseFor($product);
    $product->forceFill(['category' => ProductCategory::PetFood, 'category_set_by' => CategorySource::User])->save();
    app()->terminate();

    Http::assertNothingSent();
    expect($product->fresh()?->category)->toBe(ProductCategory::PetFood);
});

it('does not write over a choice made between the request and the write', function (): void {
    $user = proUserWantingCategories();
    $product = Product::factory()->create(['user_id' => $user->id]);

    Http::fake([TypeSafeClient::ENDPOINT => function () use ($product): PromiseInterface {
        // The edit form lands while the request is in flight.
        Product::query()->whereKey($product->id)->update(['category' => 'pets.pet_food', 'category_set_by' => 'user']);

        return Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]));
    }]);

    new CategoriseProduct($product->id)->handle(app(TypeSafeClient::class));

    expect($product->fresh()?->category)->toBe(ProductCategory::PetFood)
        ->and($product->fresh()?->category_set_by)->toBe(CategorySource::User);
});

it('logs a warning and leaves the product untouched when the request fails for good', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 401)]);
    Log::spy();
    $user = proUserWantingCategories();
    $product = Product::factory()->create(['user_id' => $user->id]);

    new CategoriseProduct($product->id)->handle(app(TypeSafeClient::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['product_id'] === $product->id
            && is_string($context['error'])
            && str_contains($context['error'], '401'));
    expect($product->fresh()?->category)->toBeNull()
        ->and($product->fresh()?->category_set_by)->toBeNull();
});

it('keeps the best guess as a suggestion when the answer is not confident enough', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['other' => 0.9, 'food' => 0.1], ['food' => ['coffee_tea' => 0.9, 'pantry' => 0.1]]))]);
    $user = proUserWantingCategories();
    $product = Product::factory()->create(['user_id' => $user->id]);

    new CategoriseProduct($product->id)->handle(app(TypeSafeClient::class));

    expect($product->fresh()?->category)->toBeNull()
        ->and($product->fresh()?->category_set_by)->toBeNull()
        ->and($product->fresh()?->suggested_category)->toBe(ProductCategory::CoffeeTea);
});

it('clears a stale suggestion when a confident answer places the product', function (): void {
    fakeConfidentCoffee();
    $user = proUserWantingCategories();
    $product = Product::factory()->create(['user_id' => $user->id, 'suggested_category' => ProductCategory::PetFood]);

    new CategoriseProduct($product->id)->handle(app(TypeSafeClient::class));

    expect($product->fresh()?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($product->fresh()?->suggested_category)->toBeNull();
});
