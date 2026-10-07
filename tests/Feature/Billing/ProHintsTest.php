<?php declare(strict_types=1);

use App\Billing\ProPitch;
use App\Livewire\Products\ProductList;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Livewire\livewire;

it('pitches Pro to a free account and never to a Pro account or a guest', function (): void {
    configureStripe();

    $free = User::factory()->create();
    $pro = User::factory()->create();
    subscribeUser($pro);

    expect(ProPitch::for($free))->canBuy->toBeTrue()
        ->and(ProPitch::for($pro))->toBeNull()
        ->and(ProPitch::for(user: null))->toBeNull();
});

it('keeps a selling hint away while Pro cannot be bought, but still explains a limit', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(18)->create(['user_id' => $user->id]);
    $product = Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])->assertDontSeeHtml('data-test="recheck-pro-hint"');
    livewire(ProductList::class)
        ->assertSeeHtml('data-test="product-limit-pro-hint"')
        ->assertDontSeeHtml('href="' . route('app.pro') . '"');
});

it('says how often each plan checks, to a free account only', function (): void {
    configureStripe();

    $free = User::factory()->create();
    $this->actingAs($free);

    livewire(ProductShow::class, ['product' => Product::factory()->create(['user_id' => $free->id])])
        ->assertSeeHtml('data-test="recheck-pro-hint"')
        ->assertSee('Your plan checks each shop every 24 hours. Pro checks every 6 hours');

    $pro = User::factory()->create();
    subscribeUser($pro);
    $this->actingAs($pro);

    livewire(ProductShow::class, ['product' => Product::factory()->create(['user_id' => $pro->id])])
        ->assertDontSeeHtml('data-test="pro-hint"')
        ->assertDontSeeHtml('data-test="recheck-pro-hint"');
});

it('warns a free account three products before its limit, and at it', function (int $products, ?string $says): void {
    configureStripe();

    $user = User::factory()->create();
    Product::factory()->count($products)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $list = livewire(ProductList::class);

    if ($says === null) {
        $list->assertDontSeeHtml('data-test="product-limit-pro-hint"');
    } else {
        $list->assertSeeHtml('data-test="product-limit-pro-hint"')->assertSee($says);
    }
})->with([
    'far from it' => [16, null],
    'three left' => [17, 'You follow 17 of the 20 products your plan allows. Pro follows up to 250.'],
    'at it' => [20, 'You follow all 20 products your plan allows. Pro follows up to 250.'],
    'over it, after leaving Pro' => [24, 'You follow 24 products. Your plan allows 20, so you cannot add more. Pro follows up to 250.'],
]);

it('shows the header Pro link to a free account while Pro can be bought', function (): void {
    configureStripe();

    $free = User::factory()->create();
    $this->actingAs($free)->get(route('app.dashboard'))->assertOk()->assertSeeHtml('data-test="header-pro-link"');

    $pro = User::factory()->create();
    subscribeUser($pro);
    $this->actingAs($pro)->get(route('app.dashboard'))->assertOk()->assertDontSeeHtml('data-test="header-pro-link"');
});

it('lands a free account on the Pro page, with checkout one click away', function (): void {
    configureStripe();

    $this->actingAs(User::factory()->create())
        ->get(route('app.pro'))
        ->assertOk()
        ->assertSee('Catch every good price, at every shop')
        ->assertSeeHtml('href="' . route('billing.checkout') . '"')
        ->assertSee('Free and Pro, side by side');
});

it('sends a Pro account from the Pro page to its billing page', function (): void {
    $pro = User::factory()->create();
    subscribeUser($pro);

    $this->actingAs($pro)->get(route('app.pro'))->assertRedirect(route('app.billing'));
});

it('sells nothing on the Pro page while Pro cannot be bought', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('app.pro'))
        ->assertOk()
        ->assertSee('Pro is not on sale yet.')
        ->assertDontSeeHtml('href="' . route('billing.checkout') . '"');
});

it('offers no Pro button to an account checkout would refuse', function (): void {
    configureStripe();

    $blocked = User::factory()->create(['billing_blocked_at' => now()]);

    expect(ProPitch::for($blocked))->canBuy->toBeFalse();
});

it('promises no free trial to a former subscriber', function (): void {
    configureStripe();
    config()->set('cashier.trial_days', 14);

    $former = User::factory()->create();
    subscribeUser($former, 'canceled', endsAt: CarbonImmutable::now()->subMonth());

    expect(ProPitch::for($former))->offersTrial->toBeFalse();

    $this->actingAs($former)->get(route('app.pro'))->assertOk()->assertDontSee('days free');
    $this->actingAs($former)->get(route('pricing'))->assertOk()->assertDontSee('days free');
});
