<?php declare(strict_types=1);

use App\Actions\Products\CategoriseProduct;
use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Livewire\Dashboard;
use App\Livewire\DashboardCategorySuggestions;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Cache::flush();
});

it('lists products without a category, the ones with a suggestion first', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Newest without a suggestion', 'created_at' => now()]);
    Product::factory()->for($user)->create(['title' => 'Older granola', 'suggested_category' => ProductCategory::Bakery, 'created_at' => now()->subWeek()]);
    Product::factory()->for($user)->categorised(ProductCategory::CoffeeTea)->create(['title' => 'Aroma Rood']);
    Product::factory()->for($user)->create(['title' => 'Left out on purpose', 'category_set_by' => CategorySource::User]);
    Product::factory()->create(['title' => 'Somebody elses product']);

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->assertSeeInOrder(['Older granola', 'Bread, bakery &amp; cereals', 'Use it', 'Newest without a suggestion', 'Choose a category'], escape: false)
        ->assertDontSee('Aroma Rood')
        ->assertDontSee('Left out on purpose')
        ->assertDontSee('Somebody elses product')
        ->assertDontSeeHtml('data-test="uncategorised-see-all"');
});

it('shows three, and links to the rest in the product list', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->count(4)->create();

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->assertSeeHtml('data-test="uncategorised-see-all"')
        ->assertSeeHtml('category=none');

    expect(substr_count(livewire(DashboardCategorySuggestions::class)->html(), 'data-test="uncategorised-product"'))->toBe(3);
});

it('leaves out a product whose category is still being worked out', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['title' => 'Just added']);
    // Marks it pending; the categorisation itself waits for the request to end.
    CategoriseProduct::afterResponseFor($product);

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)->assertDontSee('Just added');
});

it('files a product under its suggestion, a chosen category, or none, as the person’s choice', function (string $action, array $arguments, ?ProductCategory $expected): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['suggested_category' => ProductCategory::Bakery]);

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->call($action, $product->id, ...$arguments)
        ->assertDontSeeHtml('data-test="uncategorised-product"');

    $product->refresh();

    expect($product->category)->toBe($expected)
        ->and($product->category_set_by)->toBe(CategorySource::User)
        ->and($product->suggested_category)->toBeNull();
})->with([
    'the suggestion' => ['useSuggestion', [], ProductCategory::Bakery],
    'another category' => ['choose', [ProductCategory::CoffeeTea->value], ProductCategory::CoffeeTea],
    'no category' => ['leaveUncategorised', [], null],
]);

it('ignores an unknown category', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)->call('choose', $product->id, 'not-a-category');

    expect($product->refresh()->category_set_by)->toBeNull();
});

it('refuses to file another account’s product', function (): void {
    $product = Product::factory()->create(['suggested_category' => ProductCategory::Bakery]);

    $this->actingAs(User::factory()->create());

    livewire(DashboardCategorySuggestions::class)
        ->call('useSuggestion', $product->id)
        ->assertForbidden();

    expect($product->refresh()->category)->toBeNull();
});

it('sits on the dashboard', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['title' => 'Unsorted thing']);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSeeLivewire(DashboardCategorySuggestions::class);
});

it('lets a Pro account ask for a suggestion, and keeps it on the product', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]))]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->for($user)->create(['title' => 'Aroma Rood 500 g']);

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->assertSeeHtml('data-test="uncategorised-suggest"')
        ->call('suggestCategory', $product->id)
        ->assertSee('Coffee &amp; tea', escape: false)
        ->assertSee('Use it')
        ->assertDontSeeHtml('data-test="uncategorised-suggest"');

    expect($product->refresh()->suggested_category)->toBe(ProductCategory::CoffeeTea)
        ->and($product->category)->toBeNull();
});

it('says why no suggestion came', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 500)]);
    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->call('suggestCategory', $product->id)
        ->assertSee('No suggestion right now. Try again in a moment.');

    expect($product->refresh()->suggested_category)->toBeNull();
});

it('offers no suggestion button to a free account, and asks nothing for it', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::fake();
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $this->actingAs($user);

    livewire(DashboardCategorySuggestions::class)
        ->assertDontSeeHtml('data-test="uncategorised-suggest"')
        ->call('suggestCategory', $product->id);

    Http::assertNothingSent();
});
