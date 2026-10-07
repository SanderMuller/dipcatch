<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Jobs\CategoriseExistingProduct;
use App\Livewire\AiFeaturePrompt;
use App\Livewire\Settings\ProductFeatures;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Cache::flush();
});

function proWithCategoriesOff(): User
{
    $user = User::factory()->create(['auto_categories' => false]);
    subscribeUser($user);

    return $user;
}

/**
 * Three products: one to sort, one the person chose "no category" for, and
 * one the AI already judged below its guards.
 *
 * @return array{0: Product, 1: Product, 2: Product}
 */
function existingProducts(User $user): array
{
    return [
        Product::factory()->for($user)->create(['title' => 'Douwe Egberts Aroma Rood']),
        Product::factory()->for($user)->create(['category_set_by' => CategorySource::User]),
        Product::factory()->for($user)->create(['suggested_category' => ProductCategory::values()[0]]),
    ];
}

it('sorts the products already here when automatic categories are switched on in settings', function (): void {
    Queue::fake();
    $user = proWithCategoriesOff();
    [$toSort] = existingProducts($user);
    Product::factory()->create(['title' => 'Someone else']);
    $this->actingAs($user);

    livewire(ProductFeatures::class)
        ->set('auto_categories', true)
        ->call('save')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => is_string($text = data_get($params, 'slots.text')) && str_contains($text, 'sorting your products without a category now'));

    Queue::assertPushed(CategoriseExistingProduct::class, 1);
    Queue::assertPushed(CategoriseExistingProduct::class, fn (CategoriseExistingProduct $job): bool => $job->productId === $toSort->id);
});

it('queues nothing when the switch was already on, or the plan does not include it', function (bool $pro, bool $alreadyOn): void {
    Queue::fake();
    $user = User::factory()->create(['auto_categories' => $alreadyOn]);

    if ($pro) {
        subscribeUser($user);
    }

    existingProducts($user);
    $this->actingAs($user);

    livewire(ProductFeatures::class)->set('auto_categories', true)->call('save');

    Queue::assertNothingPushed();
})->with([
    'already on' => [true, true],
    'free account' => [false, false],
]);

it('sorts the products already here when the prompt switches it on', function (): void {
    Queue::fake();
    $user = proWithCategoriesOff();
    existingProducts($user);
    $this->actingAs($user);

    livewire(AiFeaturePrompt::class, ['place' => 'product_list'])->call('switchOn');

    Queue::assertPushed(CategoriseExistingProduct::class, 1);
});

it('queues no more than the account may ask the AI in a day', function (): void {
    Queue::fake();
    config()->set('dipcatch.categories.daily_limit_per_user', 2);
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);
    Product::factory()->for($user)->count(4)->create();

    expect(CategoriseExistingProduct::queueFor($user))->toBe(2);
    Queue::assertPushed(CategoriseExistingProduct::class, 2);
});

it('stores the AI category when the job runs', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]))]);
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['title' => 'Douwe Egberts Aroma Rood']);

    new CategoriseExistingProduct($product->id)->handle(app(TypeSafeClient::class), app(CategorisationBudget::class));

    expect($product->refresh()->category)->toBe(ProductCategory::from('food.coffee_tea'))
        ->and($product->category_set_by)->toBe(CategorySource::Auto);
});

it('leaves a product alone that got a category, or whose owner switched it off, before the job ran', function (): void {
    Http::preventStrayRequests();
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);
    $chosen = Product::factory()->for($user)->create();
    $job = new CategoriseExistingProduct($chosen->id);
    $chosen->forceFill(['category' => ProductCategory::from('food.coffee_tea'), 'category_set_by' => CategorySource::User])->save();

    $job->handle(app(TypeSafeClient::class), app(CategorisationBudget::class));

    $switchedOff = Product::factory()->for($user)->create();
    $user->forceFill(['auto_categories' => false])->save();
    new CategoriseExistingProduct($switchedOff->id)->handle(app(TypeSafeClient::class), app(CategorisationBudget::class));

    expect($chosen->refresh()->category_set_by)->toBe(CategorySource::User)
        ->and($switchedOff->refresh()->category)->toBeNull();
    Http::assertNothingSent();
});

it('asks the AI nothing once the day\'s budget is spent, or for a product it judged before', function (): void {
    Http::preventStrayRequests();
    config()->set('dipcatch.categories.daily_limit_per_user', 1);
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);
    $budget = app(CategorisationBudget::class);
    $budget->allows($user);
    $overBudget = Product::factory()->for($user)->create();
    $judged = Product::factory()->for($user)->create(['suggested_category' => ProductCategory::from('food.coffee_tea')]);

    new CategoriseExistingProduct($overBudget->id)->handle(app(TypeSafeClient::class), $budget);
    config()->set('dipcatch.categories.daily_limit_per_user', 0);
    new CategoriseExistingProduct($judged->id)->handle(app(TypeSafeClient::class), $budget);

    expect($overBudget->refresh()->category)->toBeNull()
        ->and($judged->refresh()->category)->toBeNull();
    Http::assertNothingSent();
});

it('queues nothing from a prompt left open after the feature was switched on elsewhere', function (): void {
    Queue::fake();
    $user = proWithCategoriesOff();
    existingProducts($user);
    $this->actingAs($user);
    $prompt = livewire(AiFeaturePrompt::class, ['place' => 'product_list']);
    $user->forceFill(['auto_categories' => true])->save();

    $prompt->call('switchOn');

    Queue::assertNothingPushed();
});
