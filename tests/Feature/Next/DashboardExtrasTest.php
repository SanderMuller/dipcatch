<?php declare(strict_types=1);

use App\Charts\SavingsByMonthSeries;
use App\Livewire\Dashboard;
use App\Livewire\Products\ProductList;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Livewire\livewire;

it('sums the savings per month and per currency, without converting', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    foreach ([['EUR', '2.50'], ['EUR', '1.25'], ['USD', '10.00']] as [$currency, $amount]) {
        PriceDropEvent::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'currency' => $currency,
            'drop_abs' => $amount,
            'fired_at' => CarbonImmutable::now()->startOfMonth()->addDays(2),
        ]);
    }

    $data = new SavingsByMonthSeries($user)->data();

    expect($data['labels'])->toHaveCount(12)
        ->and($data['datasets'])->toHaveCount(2);

    $byCurrency = collect($data['datasets'])->keyBy('currency');

    expect($byCurrency)->toHaveKeys(['EUR', 'USD']);

    $euro = $byCurrency->get('EUR', ['data' => []])['data'];
    $dollar = $byCurrency->get('USD', ['data' => []])['data'];

    // This month is the last column. Euros and dollars stay apart.
    expect(end($euro))->toBe(3.75)
        ->and(end($dollar))->toBe(10.0);
});

it('leaves months with no alerts at zero rather than skipping them', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'drop_abs' => '4.00',
        'fired_at' => CarbonImmutable::now()->startOfMonth()->subMonths(2)->addDay(),
    ]);

    $data = new SavingsByMonthSeries($user)->data();
    $row = $data['datasets'][0]['data'];

    expect($row)->toHaveCount(12)
        ->and($row[9])->toBe(4.0)
        ->and($row[11])->toBe(0.0);
});

it('counts only this accounts drops', function (): void {
    $user = User::factory()->create();
    $stranger = Product::factory()->create();

    PriceDropEvent::factory()->create([
        'user_id' => $stranger->user_id,
        'product_id' => $stranger->id,
        'currency' => 'EUR',
        'drop_abs' => '99.00',
        'fired_at' => CarbonImmutable::now(),
    ]);

    expect(new SavingsByMonthSeries($user)->hasData())->toBeFalse();
});

it('plots savings as one Flux field per currency', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);

    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'drop_abs' => '3.75',
        'fired_at' => CarbonImmutable::now()->startOfMonth()->addDays(2),
    ]);
    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'USD',
        'drop_abs' => '10.00',
        'fired_at' => CarbonImmutable::now()->startOfMonth()->addDays(2),
    ]);

    $chart = new SavingsByMonthSeries($user)->fluxChart();
    $last = $chart['rows'][11];

    expect($chart['series'])->toHaveCount(2)
        ->and($last['EUR'])->toBe(3.75)
        ->and($last['USD'])->toBe(10.0);
});

it('keeps the savings chart off the dashboard and links to it instead', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'drop_abs' => '2.00',
        'fired_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertDontSee('Savings by month')
        ->assertDontSeeHtml('<ui-chart')
        ->assertSeeHtml(route('app.stats'));
});

it('keeps the alert history off the dashboard', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertDontSee('Recent alerts');
});

it('nudges an account that tracks a product at only one shop', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->create(['product_id' => $product->id]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSee('Add a second shop to compare');
});

it('points the nudge at the product with the add-shop form already open', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->create(['product_id' => $product->id]);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('Add a shop')
        ->assertSeeHtml(route('app.products.show', [$product, 'add-shop' => 1]));
});

it('drops the nudge once any product has two shops', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    Shop::factory()->count(2)->create(['product_id' => $product->id]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertDontSee('Add a second shop to compare');
});

it('does not nudge an account with nothing tracked', function (): void {
    // That account gets the "track your first product" callout instead.
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertDontSee('Add a second shop to compare');
});

it('filters the product list by tracking state', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id, 'title' => 'Still watching', 'active' => true]);
    Product::factory()->create(['user_id' => $user->id, 'title' => 'On hold', 'active' => false]);

    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSee('Still watching')
        ->assertSee('On hold')
        ->set('status', 'active')
        ->assertSee('Still watching')
        ->assertDontSee('On hold')
        ->set('status', 'paused')
        ->assertSee('On hold')
        ->assertDontSee('Still watching')
        ->set('status', 'nonsense')
        ->assertSee('Still watching')
        ->assertSee('On hold');
});
