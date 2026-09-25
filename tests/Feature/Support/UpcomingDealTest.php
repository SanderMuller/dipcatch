<?php declare(strict_types=1);

use App\Models\Shop;
use App\Support\HeadlinePrice;
use App\Support\PromotionLabel;
use Illuminate\Support\Facades\Blade;

it('states an announced deal in its terms and period', function (): void {
    $this->travelTo('2026-09-25 12:00:00');
    $shop = Shop::factory()->create(['url' => 'https://www.ah.nl/producten/product/wi183521/x', 'current_price' => '2.89']);
    $shop->forceFill([
        'promotion_starts_at' => '2026-09-27 22:00:00',
        'promotion_ends_at' => '2026-10-04 21:59:59',
        'promotion_label' => '25% korting',
    ])->save();

    expect(PromotionLabel::upcomingDeal($shop->refresh()))->toBe('25% korting · 28 Sep – 4 Oct')
        ->and(PromotionLabel::runningDeal($shop))->toBeNull();
});

it('names no upcoming deal once it runs', function (): void {
    $this->travelTo('2026-09-29 12:00:00');
    $shop = Shop::factory()->create(['current_price' => '2.17']);
    $shop->forceFill([
        'promotion_starts_at' => '2026-09-27 22:00:00',
        'promotion_ends_at' => '2026-10-04 21:59:59',
        'promotion_label' => '25% korting',
    ])->save();

    expect(PromotionLabel::upcomingDeal($shop->refresh()))->toBeNull();
});

/** An AH shop with next week's bonus announced, seen on 25 Sep. */
function announcedAhDeal(): Shop
{
    test()->travelTo('2026-09-25 12:00:00');
    $shop = Shop::factory()->create(['url' => 'https://www.ah.nl/producten/product/wi183521/x', 'current_price' => '2.89', 'currency' => 'EUR']);
    $shop->forceFill([
        'promotion_starts_at' => '2026-09-27 22:00:00',
        'promotion_ends_at' => '2026-10-04 21:59:59',
        'promotion_label' => '25% korting',
    ])->save();

    return $shop->refresh();
}

it('shows an announced deal on the card with its terms, its shop and its period', function (): void {
    $html = Blade::render('<x-shop-deal :shop="$shop" :show-source="false" />', ['shop' => announcedAhDeal()]);

    expect($html)->toContain('Upcoming deal')
        ->toContain('25% korting')
        ->toContain('at ah.nl')
        ->toContain('28 Sep – 4 Oct');
});

it('lists an announced deal in the hover details apart from the discount now', function (): void {
    $product = announcedAhDeal()->product()->firstOrFail()->load('shops');

    $html = Blade::render('<x-product-card.details :product="$product" :headline="$headline" />', [
        'product' => $product,
        'headline' => HeadlinePrice::of($product),
    ]);

    expect($html)->toContain('data-test="details-upcoming"')
        ->toContain('25% korting · 28 Sep – 4 Oct')
        ->toContain('No discount right now.');
});
