<?php declare(strict_types=1);

use App\PriceAdapters\VatStatement;

test('the words under the price are read', function (): void {
    // The fivestartrading-holland.eu shape, from the live page.
    $html = '<div class="price"><p class="price"><bdi>&euro;21,15</bdi></p></div>'
        . '<div><small class="text-success">excl. BTW en verzendkosten, in 5-10 dagen geleverd</small></div>';

    expect(VatStatement::exclusivePhrase($html))->toBe('excl. btw');
});

test('a shop stating it site-wide is read too', function (): void {
    $html = '<footer>&copy; 2005 - 2026 Five Star Trading Holland - Alle prijzen zijn exclusief BTW en verzendkosten.</footer>';

    expect(VatStatement::exclusivePhrase($html))->toBe('exclusief btw');
});

test('a price stated with VAT says nothing', function (): void {
    expect(VatStatement::exclusivePhrase('<p>&euro;19,95 incl. BTW</p>'))->toBeNull();
});

test('excluding shipping is not excluding VAT', function (): void {
    // Plenty of ordinary consumer shops print this, and their prices are
    // complete. Refusing them would hide a price the shopper can pay.
    expect(VatStatement::exclusivePhrase('<p>&euro;19,95 excl. verzendkosten</p>'))->toBeNull()
        ->and(VatStatement::exclusivePhrase('<p>Prijzen zijn exclusief verzendkosten</p>'))->toBeNull();
});

test('a page saying both is not called either way', function (): void {
    $html = '<p>&euro;19,95 excl. 21% btw</p><p>Consumenten betalen incl. btw</p>';

    expect(VatStatement::exclusivePhrase($html))->toBeNull();
});

test('script text is not the page', function (): void {
    // WP Rocket ships `rocket_excluded_pairs` on every page it serves.
    $html = '<script>var rocket_excluded_pairs = []; var x = "excl. btw";</script><p>&euro;19,95</p>';

    expect(VatStatement::exclusivePhrase($html))->toBeNull();
});
