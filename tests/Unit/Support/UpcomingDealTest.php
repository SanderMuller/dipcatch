<?php declare(strict_types=1);

use App\PriceAdapters\PromotionWindow;
use App\Support\UpcomingDeal;
use Carbon\CarbonImmutable;

dataset('labels', [
    'a percentage stays' => ['25% KORTING', '1.99', '25% KORTING'],
    'a multi-buy stays' => ['1+1 GRATIS', '2.09', '1+1 GRATIS'],
    'a fixed total stays' => ['2 voor 2,99', '1.79', '2 voor 2,99'],
    'a later-item discount stays' => ['2e halve PRIJS', '2.49', '2e halve PRIJS'],
    'an Aldi discount stays' => ['-26%', '0.85', '-26%'],
    '"voor" and a price is the price' => ['voor 1,99', '1.99', '1.99'],
    '"per stuk" and a price is the price' => ['per stuk 1,99', '1.99', '1.99'],
    'a size and a price is the price' => ['500 gram 0,79', '0.79', '0.79'],
    'a size with a full stop is the price' => ['250 gram. 2,29', '2.29', '2.29'],
    'OP=OP names no deal' => ['OP=OP', '1.69', '1.69'],
    'wording that starts with "actie" stays' => ['Actie 1+1 gratis', '1.99', 'Actie 1+1 gratis'],
    'no text is the price' => [null, '0.99', '0.99'],
    'whole euros keep no decimals' => [null, '16.00', '16'],
]);

test('the label is the shop\'s deal wording, or the offer price', function (?string $text, ?string $price, ?string $label): void {
    expect(UpcomingDeal::label($text, $price))->toBe($label);
})->with('labels');

test('a price-only text without a stated price keeps the text', function (): void {
    expect(UpcomingDeal::label('per stuk 1,99', null))->toBe('per stuk 1,99')
        ->and(UpcomingDeal::label('OP=OP', null))->toBeNull();
});

function upcomingWindow(?string $offerPrice, ?string $text, string $today = '2.59', string $startsIn = '+3 days', bool $priceDecides = false): ?PromotionWindow
{
    $now = CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Amsterdam');

    return UpcomingDeal::window(
        startsAt: $now->modify($startsIn),
        endsAt: $now->modify('+9 days'),
        offerPrice: $offerPrice,
        text: $text,
        todayPrice: $today,
        priceDecides: $priceDecides,
        now: $now,
    );
}

test('a cheaper offer that starts later is an upcoming window', function (): void {
    $window = upcomingWindow('1.99', 'voor 1,99');

    expect($window?->label)->toBe('1.99')
        ->and($window?->startsAt?->format('Y-m-d'))->toBe('2026-10-27');
});

test('an offer that has started is not upcoming', function (): void {
    expect(upcomingWindow('1.99', null, startsIn: '-1 hour'))->toBeNull()
        ->and(upcomingWindow('1.99', null, startsIn: '+0 seconds'))->toBeNull();
});

test('an offer no cheaper than today needs deal wording', function (): void {
    expect(upcomingWindow('2.59', null))->toBeNull()
        ->and(upcomingWindow('2.99', 'per stuk 2,99'))->toBeNull()
        ->and(upcomingWindow('2.59', '1+1 GRATIS')?->label)->toBe('1+1 GRATIS')
        ->and(upcomingWindow(null, '25% korting')?->label)->toBe('25% korting');
});

test('where the price decides, deal wording does not make an equal price a deal', function (): void {
    expect(upcomingWindow('2.59', '-26%', priceDecides: true))->toBeNull()
        ->and(upcomingWindow('2.99', '-26%', priceDecides: true))->toBeNull()
        ->and(upcomingWindow('1.99', '-26%', priceDecides: true)?->label)->toBe('-26%');
});
