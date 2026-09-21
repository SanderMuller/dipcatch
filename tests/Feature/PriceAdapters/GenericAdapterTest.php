<?php declare(strict_types=1);

use App\PriceAdapters\GenericAdapter;

beforeEach(function (): void {
    $this->adapter = new GenericAdapter();
});

test('extracts from .price class with currency symbol', function (): void {
    $html = <<<'HTML'
<html><body>
  <h1>Demo Item</h1>
  <span class="price">€ 29,99</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('29.99')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Demo Item');
});

test('extracts from [data-price]', function (): void {
    $html = <<<'HTML'
<title>Plugged Item</title>
<div data-price="49.00">$49.00</div>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->price)->toBe('49.00')
        ->and($result->snapshot?->currency)->toBe('USD');
});

test('skips when no price selectors match', function (): void {
    $result = $this->adapter->extract('https://x.test', '<html><body><p>no prices</p></body></html>');

    expect($result->isSkip())->toBeTrue();
});

test('skips when matched selector has no currency hint', function (): void {
    $html = '<span class="price">29.99</span>';

    $result = $this->adapter->extract('https://x.test', $html);

    // No currency symbol or ISO code anywhere → skip (low confidence).
    expect($result->isSkip())->toBeTrue();
});

test('uses og:image as imageUrl', function (): void {
    $html = <<<'HTML'
<meta property="og:image" content="https://shop.test/p.jpg" />
<span class="price">£10.00</span>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->imageUrl)->toBe('https://shop.test/p.jpg')
        ->and($result->snapshot?->currency)->toBe('GBP');
});

test('a blank h1 gives the page title its turn', function (): void {
    $html = <<<'HTML'
<html><head><title>Page title</title></head><body>
  <h1>   </h1>
  <span class="price">€ 29,99</span>
</body></html>
HTML;

    $result = $this->adapter->extract('https://x.test', $html);

    expect($result->snapshot?->title)->toBe('Page title');
});

test('takes the shelf price, not the per-litre rate printed above it', function (): void {
    // hoogvliet.com, reported 2026-09-20: a bottle of mayonnaise at 3,59 was
    // stored as 4,79 — the regular price per litre, printed first. The stored
    // number was already a unit price, in the column the unit comparison
    // divides.
    $html = <<<'HTML'
<h1>Remia Mayonaise 750 milliliter</h1>
<div class="product-price-per-unit">Reguliere prijs per liter € 4,79</div>
<div class="product-price">€ 3,59</div>
HTML;

    $result = $this->adapter->extract('https://www.hoogvliet.com/p/1', $html);

    expect($result->snapshot?->price)->toBe('3.59');
});

test('takes the shelf price when the rate is the larger number', function (): void {
    // The Sensodyne case from the same report: 7,29 for 75 ml prints a per-litre
    // line of 97,20. Reading the rate makes the shop look absurdly expensive —
    // which is the harmless direction. Over a litre the same bug reads as a
    // bargain and wins the ranking.
    $html = <<<'HTML'
<h1>Sensodyne Rapid Relief 75 milliliter</h1>
<span class="price price--unit">Reguliere prijs per liter € 97,20</span>
<span class="price price--current">€ 7,29</span>
HTML;

    $result = $this->adapter->extract('https://www.hoogvliet.com/p/2', $html);

    expect($result->snapshot?->price)->toBe('7.29');
});

test('refuses a price that equals a rate the page states elsewhere', function (): void {
    // The rate and the words that label it are often two elements, so neither
    // half reads as a rate on its own. The value is the link between them.
    $html = <<<'HTML'
<h1>Olijfolie 500 ml</h1>
<span class="price">€ 11,98</span>
<div class="unit"><span class="label">Prijs per liter</span><span class="amount">€ 11,98</span></div>
<span class="price price--actual">€ 5,99</span>
HTML;

    $result = $this->adapter->extract('https://shop.test/p/3', $html);

    expect($result->snapshot?->price)->toBe('5.99');
});

test('skips rather than storing a rate when the page shows nothing else', function (): void {
    // A confidently wrong price is worse than none: this is the reading that
    // would otherwise reach the unit comparison as a pack price.
    $html = '<h1>Item</h1><span class="price">Prijs per kilo € 22,33</span>';

    $result = $this->adapter->extract('https://shop.test/p/4', $html);

    expect($result->isSkip())->toBeTrue();
});

test('leaves an ordinary price alone when the page mentions a unit somewhere', function (): void {
    // The marker has to read the label, not the page. A description that
    // happens to say "per stuk" must not cost the shop its price.
    $html = <<<'HTML'
<h1>Stroopwafels</h1>
<p class="description">Heerlijke stroopwafels, individueel verpakt per stuk in folie, zodat elke wafel vers blijft tot het moment dat je hem opent en in je koffie legt.</p>
<span class="price">€ 2,49</span>
HTML;

    $result = $this->adapter->extract('https://shop.test/p/5', $html);

    expect($result->snapshot?->price)->toBe('2.49');
});

test('reads a one-litre pack whose rate is the same number as its price', function (): void {
    // A litre of mayonnaise prices its litre at what the bottle costs. Refusing
    // any candidate equal to a stated rate made every 1 kg and 1 L product
    // unreadable — milk, juice, oil, the commonest packs there are.
    $html = <<<'HTML'
<h1>Remia Mayonaise 1 liter</h1>
<div class="price-per-unit">Prijs per liter € 3,59</div>
<div class="price">€ 3,59</div>
HTML;

    $result = $this->adapter->extract('https://shop.test/p/6', $html);

    expect($result->snapshot?->price)->toBe('3.59');
});

test('does not read a delivery note as a rate per litre', function (): void {
    // "verzending/levering" contains "/l". Matching unit markers as bare
    // substrings refused a real price over a word that has nothing to do with
    // litres.
    $html = '<h1>Item</h1><div class="price">€ 4,99 verzending/levering</div>';

    $result = $this->adapter->extract('https://shop.test/p/7', $html);

    expect($result->snapshot?->price)->toBe('4.99');
});
