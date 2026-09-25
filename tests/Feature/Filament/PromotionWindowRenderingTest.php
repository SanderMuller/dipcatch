<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: Product}
 */
function seedShopWith(array $attributes): array
{
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);

    Shop::factory()->for($product)->create(['currency' => 'EUR', 'current_price' => '1.69'])
        ->forceFill($attributes)
        ->save();

    return [$user, $product->refresh()];
}

test('a running promotion says until when', function (): void {
    [$user, $product] = seedShopWith([
        'promotion_starts_at' => now()->subDays(2),
        'promotion_ends_at' => now()->addDays(4),
        'promotion_label' => 'VOOR 1.69',
    ]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('Shop page:')
        ->assertSeeText('VOOR 1.69')
        ->assertSeeText('until ' . now()->addDays(4)->setTimezone('Europe/Amsterdam')->format('j M'));
});

test('a promotion with no label of its own shows its offer period', function (): void {
    [$user, $product] = seedShopWith(['promotion_ends_at' => now()->addDays(3)]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('Offer period:')
        ->assertSeeText('until');
});

test('a promotion that has not started says what it gives, where and when, never ended', function (): void {
    [$user, $product] = seedShopWith([
        'promotion_starts_at' => now()->addDays(3),
        'promotion_ends_at' => now()->addDays(9),
        'promotion_label' => '2 voor 4.00',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'current_price' => '2.69',
        'single_item_price' => '2.69',
    ]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('Upcoming deal')
        ->assertSeeText('2 for €4.00')
        ->assertSeeText('Until then: €2.69 each')
        ->assertDontSeeHtml('<del')
        ->assertSeeText('at ' . $product->shops()->firstOrFail()->host)
        ->assertSeeText(now()->addDays(3)->setTimezone('Europe/Amsterdam')->format('j M') . ' – ' . now()->addDays(9)->setTimezone('Europe/Amsterdam')->format('j M'))
        ->assertDontSeeText('ended');
});

test('a promotion that has passed says so, so the price reads as suspect', function (): void {
    [$user, $product] = seedShopWith(['promotion_ends_at' => now()->subDays(2)]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('Offer period:')
        ->assertSeeText('ended ' . now()->subDays(2)->setTimezone('Europe/Amsterdam')->format('j M'));
});

test('a date-only window renders its own day, not the day before', function (): void {
    // 8 September in Amsterdam is stored as 7 September 22:00 UTC. Printed
    // without converting back, it would read "7 Sep".
    [$user, $product] = seedShopWith([
        'promotion_starts_at' => '2036-09-07 22:00:00',
        'promotion_ends_at' => '2036-09-13 21:59:59',
    ]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('8 Sep – 13 Sep')
        ->assertDontSeeText('7 Sep');
});

test('a shop with no promotion shows none', function (): void {
    [$user, $product] = seedShopWith([]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertDontSeeText('Offer period:')
        ->assertDontSeeText('until')
        ->assertDontSeeText('ended');
});

test('a promotion window and a conditional offer are both shown', function (): void {
    [$user, $product] = seedShopWith([
        'promotion_ends_at' => now()->addDays(4),
        'conditional_price' => '1.49',
        'conditional_label' => 'Bonus Box 15% korting',
        'conditional_ends_at' => now()->addDays(4),
    ]);
    $this->actingAs($user);

    mountShopsRelationManager($product)
        ->assertSeeText('Offer period:')
        ->assertSeeText('until')
        ->assertSeeText('Bonus Box 15% korting');
});
