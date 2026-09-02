<?php declare(strict_types=1);

use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\OfferValidity;
use Carbon\CarbonImmutable;

/**
 * @return array<string, mixed>
 */
function offerValidUntil(?string $until, ?string $from = null): array
{
    $offer = ['@type' => 'Offer', 'price' => '2.45', 'priceCurrency' => 'EUR'];

    if ($until !== null) {
        $offer['priceValidUntil'] = $until;
    }

    if ($from !== null) {
        $offer['validFrom'] = $from;
    }

    return $offer;
}

test('a weekly end date is a promotion period', function (): void {
    // spar.nl states a bare date; it means the close of that day here.
    $window = OfferValidity::windowFrom(offerValidUntil('2026-09-03'), CarbonImmutable::parse('2026-09-02'));

    expect($window?->endsAt->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i:s'))->toBe('2026-09-03 23:59:59');
});

test('a timestamp keeps the instant it states', function (): void {
    $window = OfferValidity::windowFrom(
        offerValidUntil('2026-09-09T09:04:05.412Z'),
        CarbonImmutable::parse('2026-09-02'),
    );

    expect($window?->endsAt->utc()->format('Y-m-d H:i'))->toBe('2026-09-09 09:04');
});

test('a far-future placeholder is not a promotion', function (): void {
    // pharmacy4pets states this on a product that is not on offer at all.
    $window = OfferValidity::windowFrom(offerValidUntil('2027-12-31'), CarbonImmutable::parse('2026-09-02'));

    expect($window)->toBeNull();
});

test('the placeholder cutoff falls between 89 and 91 days', function (): void {
    $now = CarbonImmutable::parse('2026-09-02 12:00', 'Europe/Amsterdam');

    expect(OfferValidity::windowFrom(offerValidUntil($now->addDays(89)->toDateString()), $now))->not->toBeNull()
        ->and(OfferValidity::windowFrom(offerValidUntil($now->addDays(91)->toDateString()), $now))->toBeNull();
});

test('an offer stating no end date has no period', function (): void {
    expect(OfferValidity::windowFrom(offerValidUntil(null)))->toBeNull();
});

test('an unreadable date yields no period rather than a guess', function (): void {
    expect(OfferValidity::windowFrom(offerValidUntil('soon')))->toBeNull();
});

test('a start after the end is refused', function (): void {
    $window = OfferValidity::windowFrom(
        offerValidUntil('2026-09-03', from: '2026-09-10'),
        CarbonImmutable::parse('2026-09-02'),
    );

    expect($window)->toBeNull();
});

test('the JSON-LD adapter reports the period and speaks for it', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => "Lay's chips naturel",
        'offers' => offerValidUntil(CarbonImmutable::now()->addDays(5)->toDateString()),
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', withJsonLd($json));

    expect($result->snapshot?->promotionWindow?->isRunning())->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});

test('an offer that states no end date clears a stored period', function (): void {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => "Lay's chips naturel",
        'offers' => offerValidUntil(null),
    ], JSON_THROW_ON_ERROR);

    $result = new JsonLdAdapter()->extract('https://shop.test/p/1', withJsonLd($json));

    expect($result->snapshot?->promotionWindow)->toBeNull()
        // Authoritative: the offer priced the product, so it speaks for the
        // promotion too, and a window stored earlier is cleared.
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
});
