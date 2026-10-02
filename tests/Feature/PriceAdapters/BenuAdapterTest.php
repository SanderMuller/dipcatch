<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\BenuAdapter;

/** A Benu product page: its schema.org Offer, and the deal label over the photo when there is one. */
function benuPage(?string $dealAlt): string
{
    $json = json_encode(['@context' => 'https://schema.org', '@graph' => [[
        '@type' => 'Product',
        'name' => 'Roter Voordeelverpakking Vitamine C 70mg Citroen Kauwtabletten 800 stuks',
        'offers' => ['@type' => 'Offer', 'price' => '21.99', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ]]], JSON_THROW_ON_ERROR);

    $label = $dealAlt === null ? '' : '<div id="artikellayover" class="productinfo-layover actielabel-webp"> <img class="productinfo-layover-image" src="/images/artikellayoverimages/label.webp" alt="' . $dealAlt . '"></div>';

    return '<html><head><script type="application/ld+json">' . $json . '</script></head><body>' . $label . '</body></html>';
}

it('reads the deal on the product photo as an offer', function (string $alt, int $quantity, string $total): void {
    $result = new BenuAdapter()->extract('https://www.benushop.nl/apotheek/vitaminen/vitamine-c/roter-800-stuks', benuPage($alt));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('21.99')
        ->and($result->snapshot?->bundleOffer?->quantity)->toBe($quantity)
        ->and($result->snapshot?->bundleOffer?->totalPrice)->toBe($total);
})->with([
    '1+1 gratis' => ['Actielabel 1 plus 1 gratis', 2, '21.99'],
    '2e halve prijs' => ['Actielabel 2e halve prijs', 2, '32.99'],
]);

it('states no offer without a label, or with one no offer reads from', function (?string $alt): void {
    $result = new BenuAdapter()->extract('https://www.benushop.nl/apotheek/vitaminen/vitamine-c/roter-800-stuks', benuPage($alt));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->bundleOffer)->toBeNull()
        ->and($result->snapshot?->bundleOfferAuthoritative)->toBeTrue();
})->with([
    'no label' => [null],
    'a label without terms' => ['Actielabel nieuw'],
]);

it('skips another shop', function (): void {
    expect(new BenuAdapter()->extract('https://www.etos.nl/p/1', benuPage(null))->isSkip())->toBeTrue();
});
