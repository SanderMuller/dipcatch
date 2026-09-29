<?php declare(strict_types=1);

use App\PriceAdapters\TradeGate;

test('a page that withholds its price and says why is gated', function (): void {
    // The prometeus.nl shape: the only price on the page is in OpenGraph,
    // the body shows none, and it says what to do instead.
    $html = '<meta property="og:price:amount" content="12,99">'
        . '<body><p>Vendor: Prometeus B2B</p><p>Sign in to see prices</p></body>';

    expect(TradeGate::gatedPhrase($html, '12.99'))->toBe('sign in to see prices');
});

test('a page showing the price is not gated, whatever else it says', function (): void {
    // A shop with a trade portal still sells this at a real price, and the
    // phrase belongs to a link in the chrome around it.
    $html = '<body><p>&euro;12,99</p><p>Sign in to see prices for your B2B account</p></body>';

    expect(TradeGate::gatedPhrase($html, '12.99'))->toBeNull();
});

test('a price split across elements still counts as shown', function (): void {
    // hoogvliet.com writes every price this way. Reading it as a page with no
    // price would gate a shop that is plainly selling.
    $html = '<body><span>12</span><span>,99</span><p>Sign in to see prices</p></body>';

    expect(TradeGate::gatedPhrase($html, '12.99'))->toBeNull();
});

test('a vendor named B2B is not a gate', function (): void {
    // Prometeus names its own vendor "Prometeus B2B", and a bare token would
    // disqualify any shop that links to a trade portal.
    expect(TradeGate::gatedPhrase('<body><p>Vendor: Prometeus B2B</p></body>', '12.99'))->toBeNull();
});

test('a theme shipping its catalogue as JSON is not the page', function (): void {
    // A Shopify theme puts every price in a script tag. Counting that as the
    // page stating its price would hide every gated shop there is.
    $html = '<script>var product = {"price":1299,"priceFormatted":"12,99"};</script>'
        . '<body><p>Sign in to see prices</p></body>';

    expect(TradeGate::gatedPhrase($html, '12.99'))->toBe('sign in to see prices');
});
