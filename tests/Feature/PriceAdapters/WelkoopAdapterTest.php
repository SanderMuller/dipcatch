<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\WelkoopAdapter;

beforeEach(function (): void {
    $this->adapter = new WelkoopAdapter();
});

test('skips when the URL host is not welkoop.nl', function (): void {
    $result = $this->adapter->extract('https://other.com/p/1', '<html></html>');

    expect($result->isSkip())->toBeTrue();
});

test('reads the current price, not the JSON-LD list price', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Royal Canin Kitten',
        'image' => 'https://www.welkoop.nl/kitten.jpg',
        'offers' => ['@type' => 'Shop', 'price' => '31.50', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $html = withJsonLd($json)
        . '<h1>Royal Canin Kitten</h1>'
        . '<p aria-label="Oorspronkelijke prijs € 31,50">31,50</p>'
        . '<p aria-label="Huidige prijs € 26,77">26,77</p>';

    $result = $this->adapter->extract('https://www.welkoop.nl/royal-canin-kitten-kattenvoer-2kg_1018191', $html);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('26.77')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Royal Canin Kitten')
        ->and($result->snapshot?->imageUrl)->toBe('https://www.welkoop.nl/kitten.jpg');
});

test('fails when the current-price label is missing, even if JSON-LD has a list price', function (): void {
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Royal Canin Kitten',
        'offers' => ['@type' => 'Shop', 'price' => '31.50', 'priceCurrency' => 'EUR'],
    ], JSON_THROW_ON_ERROR);

    $result = $this->adapter->extract('https://welkoop.nl/kitten', withJsonLd($json));

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('welkoop_extraction_failed');
});

test('fails when a welkoop page has neither a current price nor JSON-LD', function (): void {
    $result = $this->adapter->extract('https://www.welkoop.nl/p/1', '<html><body><p>broken page</p></body></html>');

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureReason)->toBe('welkoop_extraction_failed');
});

test('welkoop reads its h1 before og:title, and og:title under name', function (): void {
    $priced = '<p aria-label="Huidige prijs € 12,34">12,34</p>';
    $withBoth = '<html><head><meta name="og:title" content="Og name"></head><body>'
        . '<h1>H1 name</h1>' . $priced . '</body></html>';
    $ogOnly = '<html><head><meta name="og:title" content="Og name"></head><body>' . $priced . '</body></html>';

    expect(new WelkoopAdapter()->extract('https://www.welkoop.nl/p/1', $withBoth)->snapshot?->title)->toBe('H1 name')
        ->and(new WelkoopAdapter()->extract('https://www.welkoop.nl/p/1', $ogOnly)->snapshot?->title)->toBe('Og name');
});
