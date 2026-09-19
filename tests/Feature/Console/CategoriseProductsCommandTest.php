<?php declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    Http::preventStrayRequests();
});

function confidentCoffeeAnswer(): array
{
    return typesafeAnswer(['food' => 0.95, 'home' => 0.05], ['food' => ['coffee_tea' => 0.95, 'pantry' => 0.05]]);
}

function optedInProUser(): User
{
    $user = User::factory()->create(['auto_categories' => true]);
    subscribeUser($user);

    return $user;
}

it('categorises only the null, never-touched products of opted-in Pro accounts', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(confidentCoffeeAnswer())]);
    $pro = optedInProUser();
    $wanted = Product::factory()->create(['user_id' => $pro->id, 'title' => 'Wanted']);
    $cleared = Product::factory()->create(['user_id' => $pro->id, 'title' => 'Cleared', 'category' => null, 'category_set_by' => CategorySource::User]);
    $already = Product::factory()->categorised(ProductCategory::PetFood, CategorySource::Auto)->create(['user_id' => $pro->id, 'title' => 'Already']);

    $optedOutPro = User::factory()->create(['auto_categories' => false]);
    subscribeUser($optedOutPro);
    $optedOut = Product::factory()->create(['user_id' => $optedOutPro->id, 'title' => 'Opted out']);

    $free = Product::factory()->create(['user_id' => User::factory()->create(['auto_categories' => true])->id, 'title' => 'Free']);

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('1 categorised, 0 skipped, 0 failed.')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect($wanted->fresh()?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($wanted->fresh()?->category_set_by)->toBe(CategorySource::Auto)
        ->and($cleared->fresh()?->category)->toBeNull()
        ->and($already->fresh()?->category)->toBe(ProductCategory::PetFood)
        ->and($optedOut->fresh()?->category)->toBeNull()
        ->and($free->fresh()?->category)->toBeNull();
});

it('skips, without a request, an account the SQL pre-filter includes but the entitlement rejects', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(confidentCoffeeAnswer())]);
    // ProUsers::ids() ORs a future trial_ends_at; plan() ignores that trial
    // once a subscription exists and has ended.
    $user = User::factory()->create(['auto_categories' => true, 'trial_ends_at' => CarbonImmutable::now()->addWeek()]);
    subscribeUser($user, status: 'canceled', endsAt: CarbonImmutable::now()->subDay());
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('0 categorised, 1 skipped, 0 failed.')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect($product->fresh()?->category)->toBeNull();
});

it('prints the verdict per product and writes nothing on a dry run', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(confidentCoffeeAnswer())]);
    $pro = optedInProUser();
    $product = Product::factory()->create(['user_id' => $pro->id, 'title' => 'Aroma Rood']);

    $this->artisan('dipcatch:categorise-products --dry-run')
        ->expectsOutputToContain('Aroma Rood | winner food.coffee_tea | path 0.950 | runner-up food.pantry | separation 4.36 | stored')
        ->expectsOutputToContain('[dry run] 1 categorised, 0 skipped, 0 failed. Tokens: 1200 in, 90 out.')
        ->assertSuccessful();

    expect($product->fresh()?->category)->toBeNull()
        ->and($product->fresh()?->category_set_by)->toBeNull();
});

it('caps a run at --limit and --user', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(confidentCoffeeAnswer())]);
    $pro = optedInProUser();
    Product::factory()->count(3)->create(['user_id' => $pro->id]);
    $otherPro = optedInProUser();
    $other = Product::factory()->create(['user_id' => $otherPro->id]);

    $this->artisan("dipcatch:categorise-products --limit=2 --user={$pro->id}")
        ->expectsOutputToContain('2 categorised, 0 skipped, 0 failed.')
        ->assertSuccessful();

    Http::assertSentCount(2);
    expect(Product::query()->where('user_id', $pro->id)->whereNotNull('category')->count())->toBe(2)
        ->and($other->fresh()?->category)->toBeNull();
});

it('keeps going after one product fails, and counts it', function (): void {
    // Two server errors exhaust the retry for the first product; a rejected
    // key would stop the run instead.
    Http::fake([TypeSafeClient::ENDPOINT => Http::sequence()
        ->push([], 500)
        ->push([], 500)
        ->push(confidentCoffeeAnswer())]);
    $pro = optedInProUser();
    [$first, $second] = Product::factory()->count(2)->sequence(['created_at' => now()->subMinute()], ['created_at' => now()])->create(['user_id' => $pro->id]);

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('1 categorised, 0 skipped, 1 failed.')
        ->assertFailed();

    expect($first->fresh()?->category)->toBeNull()
        ->and($second->fresh()?->category)->toBe(ProductCategory::CoffeeTea);
});

it('counts an answer below the guards as skipped and stores nothing', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(typesafeAnswer(['other' => 0.9, 'food' => 0.1]))]);
    $pro = optedInProUser();
    $product = Product::factory()->create(['user_id' => $pro->id]);

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('0 categorised, 1 skipped, 0 failed.')
        ->assertSuccessful();

    expect($product->fresh()?->category)->toBeNull();
});

it('stops at the first rejected key instead of failing every product', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);
    $pro = optedInProUser();
    Product::factory()->count(3)->create(['user_id' => $pro->id]);

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('TypeSafe rejects TYPESAFE_API_KEY')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('refuses to run without a key', function (): void {
    config()->set('services.typesafe.key', '');
    Http::fake();

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('TYPESAFE_API_KEY is not set')
        ->assertFailed();

    Http::assertNothingSent();
});

it('writes each row as it is answered, so a rerun after an interruption continues from the rest', function (): void {
    $calls = 0;
    Http::fake([TypeSafeClient::ENDPOINT => function () use (&$calls) {
        $calls++;

        if ($calls === 2) {
            throw new RuntimeException('worker killed');
        }

        return Http::response(confidentCoffeeAnswer());
    }]);
    $pro = optedInProUser();
    [$first, $second] = Product::factory()->count(2)->sequence(['created_at' => now()->subMinute()], ['created_at' => now()])->create(['user_id' => $pro->id]);

    expect(fn () => Artisan::call('dipcatch:categorise-products'))->toThrow(RuntimeException::class)
        ->and($first->fresh()?->category)->toBe(ProductCategory::CoffeeTea)
        ->and($second->fresh()?->category)->toBeNull();

    $this->artisan('dipcatch:categorise-products')
        ->expectsOutputToContain('1 categorised, 0 skipped, 0 failed.')
        ->assertSuccessful();

    // The call that threw is not recorded as sent, so two of three are.
    Http::assertSentCount(2);
    expect($second->fresh()?->category)->toBe(ProductCategory::CoffeeTea);
});
