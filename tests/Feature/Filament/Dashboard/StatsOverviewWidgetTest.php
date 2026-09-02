<?php declare(strict_types=1);

use App\Filament\App\Widgets\StatsOverviewWidget;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

use function Pest\Livewire\livewire;

function seedSavings(User $user, string $currency, string $absSum, string $dropAbs = '10.00'): void
{
    $product = Product::factory()->for($user)->create(['currency' => $currency]);
    $shop = Shop::factory()->for($product)->create();

    $remaining = (float) $absSum;
    while ($remaining > 0) {
        $thisAmount = min((float) $dropAbs, $remaining);
        $check = PriceCheck::factory()->for($shop)->create();
        PriceDropEvent::factory()->state([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'price_check_id' => $check->id,
            'currency' => $currency,
            'drop_abs' => (string) $thisAmount,
        ])->create();
        $remaining -= $thisAmount;
    }
}

test('counts only the current user\'s active products', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Product::factory()->count(3)->for($me)->create(['active' => true]);
    Product::factory()->count(2)->for($me)->inactive()->create();
    Product::factory()->count(4)->for($other)->create(['active' => true]);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Tracked products')
        ->assertSeeText('3');
});

test('Active drops counts only products with last_notified_price set, scoped to user', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Product::factory()->count(2)->for($me)->create(['last_notified_price' => '49.00']);
    Product::factory()->count(3)->for($me)->create(['last_notified_price' => null]);
    Product::factory()->count(5)->for($other)->create(['last_notified_price' => '10.00']);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Active drops')
        ->assertSeeText('2');
});

test('Lifetime savings sums drop_abs in user\'s default currency', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    seedSavings($me, 'EUR', '25.00');

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Lifetime savings')
        ->assertSeeText('€25.00');
});

test('Lifetime savings shows per-currency breakdown', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    seedSavings($me, 'EUR', '10.00');
    seedSavings($me, 'USD', '30.00');

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('€10.00')
        ->assertSeeText('$30.00')
        ->assertSeeText('FX not converted in v1');
});

test('Lifetime savings empty state when no drops yet', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('€0.00')
        ->assertSeeText('No drops fired yet. Keep tracking.');
});

test('cross-user isolation: another user\'s drops do not leak into savings sum', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    $other = User::factory()->create();
    seedSavings($other, 'EUR', '500.00');

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('€0.00');
});
