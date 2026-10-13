<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\TomAndCoAdapter;

/**
 * A Tom&Co product page as served on 2026-10-06: JSON-LD says InStock, the
 * home-delivery block says whether it can be delivered.
 */
function tomAndCoPage(string $delivery): string
{
    $json = json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Tribal Kip Adult 12kg', 'offers' => [
        '@type' => 'Offer', 'price' => '89.99', 'priceCurrency' => 'EUR', 'availability' => 'http://schema.org/InStock',
    ]], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">' . $json . '</script></head><body>'
        . '<div class="delivery-info delivery-info--deliverytime ' . $delivery . ' direct-availability-hidden"><div class="notes"><span>Momenteel niet leverbaar</span></div></div>'
        . '<div class="delivery-info delivery-info--pickupinstore is-available">Ophalen in de winkel</div>'
        . '</body></html>';
}

test('a product that cannot be delivered reads out of stock', function (): void {
    $result = new TomAndCoAdapter()->extract('https://www.tomandco.com/nl-be/tribal-kip-adult-12kg.html', tomAndCoPage('is-not-available'));

    expect($result->snapshot?->price)->toBe('89.99')
        ->and($result->snapshot?->inStock)->toBeFalse();
});

test('a product that can be delivered keeps its in-stock reading', function (): void {
    $result = new TomAndCoAdapter()->extract('https://www.tomandco.com/nl-be/tribal-kip-adult-12kg.html', tomAndCoPage('is-available'));

    expect($result->snapshot?->inStock)->toBeTrue();
});

test('the delivery block is found whatever the order of its classes', function (): void {
    $html = str_replace(
        'class="delivery-info delivery-info--deliverytime is-not-available direct-availability-hidden"',
        'class="is-not-available delivery-info--deliverytime"',
        tomAndCoPage('is-not-available'),
    );
    $html = str_replace('<body>', '<body><span class="delivery-info--deliverytime-label">Levering</span>', $html);

    expect(new TomAndCoAdapter()->extract('https://www.tomandco.com/nl-be/tribal-kip-adult-12kg.html', $html)->snapshot?->inStock)->toBeFalse();
});
