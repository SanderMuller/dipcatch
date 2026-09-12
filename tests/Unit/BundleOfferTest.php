<?php declare(strict_types=1);

use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopSnapshot;
use Carbon\CarbonImmutable;

test('supported labels become exact bundle terms', function (string $label, string $price, int $quantity, string $total, string $effective): void {
    $offer = BundleOffer::fromLabel($label, $price);

    expect($offer)->not->toBeNull()
        ->and($offer?->quantity)->toBe($quantity)
        ->and($offer?->totalPrice)->toBe($total)
        ->and($offer?->effectiveUnitPrice())->toBe($effective);
})->with([
    'fixed total with decimal comma' => [' 2   VOOR  €4,00 ', '2.85', 2, '4.00', '2.00'],
    'three for fixed total' => ['3 voor 5', '2.85', 3, '5.00', '1.67'],
    'free item' => ['1+1 GRATIS', '2.85', 2, '2.85', '1.43'],
    'pay for fewer' => ['3 halen 2 betalen', '2.85', 3, '5.70', '1.90'],
    'half price later item' => ['2e halve prijs', '2.85', 2, '4.28', '2.14'],
    'percentage off later item' => ['2e 25% korting', '2.85', 2, '4.99', '2.50'],
    'best tier' => ['2 stuks 20%, 3 stuks 30%, 4 stuks 40% korting', '2.85', 4, '6.84', '1.71'],
]);

test('tier ties select lowest required quantity', function (): void {
    $offer = BundleOffer::fromLabel('2 stuks 50%, 4 stuks 50% korting', '10.00');

    expect($offer?->quantity)->toBe(2)
        ->and($offer?->totalPrice)->toBe('10.00');
});

test('unknown invalid partial and no-saving labels are rejected', function (string $label, string $price = '2.85'): void {
    expect(BundleOffer::fromLabel($label, $price))->toBeNull();
})->with([
    ['vanaf 2 stuks 20% korting'],
    ['2 stuks 20%, plus cadeau'],
    ['2 stuks 0% korting'],
    ['2 stuks 100% korting'],
    ['3 halen 3 betalen'],
    ['0+1 gratis'],
    ['2 voor 6'],
    ['2 voor 4', 'not-money'],
]);

test('storage bounds are enforced', function (int $quantity, string $total): void {
    expect(fn (): BundleOffer => new BundleOffer($quantity, $total))->toThrow(InvalidArgumentException::class);
})->with([
    [1, '1.00'],
    [65_536, '1.00'],
    [2, '0.00'],
    [2, '10000000000.00'],
    [2, '1.001'],
]);

test('snapshot uses bundle only while its promotion runs', function (): void {
    $offer = new BundleOffer(2, '4.00');
    $running = PromotionWindow::make(endsAt: CarbonImmutable::now()->addDay());
    $expired = PromotionWindow::make(endsAt: CarbonImmutable::now()->subDay());

    $snapshot = new ShopSnapshot('Fanta', imageUrl: null, price: '2.85', currency: 'EUR', inStock: true, bundleOffer: $offer);

    expect($snapshot->trackedPrice())->toBe('2.00')
        ->and($snapshot->with(promotionWindow: $running)->trackedPrice())->toBe('2.00')
        ->and($snapshot->with(promotionWindow: $expired)->trackedPrice())->toBe('2.85');
});

test('snapshot copy preserves or authoritatively clears bundle state', function (): void {
    $offer = new BundleOffer(2, '4.00');
    $snapshot = new ShopSnapshot('Fanta', imageUrl: null, price: '2.85', currency: 'EUR', inStock: true, bundleOffer: $offer, bundleOfferAuthoritative: true);

    $preserved = $snapshot->with(packSize: '1.5 l');
    $cleared = $snapshot->with(bundleOfferAuthoritative: true);

    expect($preserved->bundleOffer)->toBe($offer)
        ->and($preserved->bundleOfferAuthoritative)->toBeTrue()
        ->and($cleared->bundleOffer)->toBeNull()
        ->and($cleared->bundleOfferAuthoritative)->toBeTrue();
});
