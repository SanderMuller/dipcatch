<?php declare(strict_types=1);

use App\PriceAdapters\Hosts\EfarmaAdapter;

/**
 * An efarma.nl product page as served on 2026-10-04: a quantity-discount
 * table that uses the same price classes, then the price split over two
 * elements after the "Prijs:" label.
 */
function efarmaPage(string $price = '<span class="diagonal_strike"><small class="cent_price"> 21,99</small></span> <span class="euro_price col_success"> 20,</span><small class="cent_price col_success">89</small>', string $stock = '<h6 class="inlined in_stock">direct leverbaar</h6>'): string
{
    return <<<HTML
        <html><body>
        <div class="panel zoom_img_panel"><img src="/images/dermacosmetics_stamp_nl.png"><img src="https://www.efarma.nl/itempics/HP_IMG/872959.jpg"></div>
        <h1 class="new_header_small_footer">Roter Vitamine C Tablet 70mg Citroen 800 st</h1>
        <p>EAN: 8713304941826 <br>Merk: <br>Producent: Cooper Consumer Health Nl BV</p>
        <div class="small-4 columns text-right">{$stock}</div>
        <table><tr><td>2</td><td><span class="euro_price"> 18</span>,<small class="cent_price">32</small></td></tr></table>
        <div class="result_panel_stock_section">
            <select id="amount0" data-itemid="15592871"><option value="1">1</option></select>
            <div class="small-5 columns text-right">
                <p class="col_text">Prijs:&nbsp;</p>
                {$price}
            </div>
        </div>
        </body></html>
        HTML;
}

beforeEach(function (): void {
    $this->adapter = new EfarmaAdapter();
    $this->url = 'https://www.efarma.nl/roter-vitamine-c-tablet-70mg-citroen/15592871';
});

test('skips a host that is not efarma.nl', function (): void {
    expect($this->adapter->extract('https://other.nl/x/15592871', efarmaPage())->isSkip())->toBeTrue();
});

test('joins the split euro and cent parts after the price label, not the discount table', function (): void {
    $result = $this->adapter->extract($this->url, efarmaPage());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->snapshot?->price)->toBe('20.89')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->title)->toBe('Roter Vitamine C Tablet 70mg Citroen 800 st')
        ->and($result->snapshot?->gtin)->toBe('8713304941826')
        ->and($result->snapshot?->inStock)->toBeTrue()
        ->and($result->snapshot?->imageUrl)->toBe('https://www.efarma.nl/itempics/HP_IMG/872959.jpg');
});

test('reads a price without a discount, where the comma sits between the parts', function (): void {
    $result = $this->adapter->extract($this->url, efarmaPage(price: '<span class="euro_price"> 3</span>,<small class="cent_price">89</small>'));

    expect($result->snapshot?->price)->toBe('3.89');
});

test('reports stock from the label the page shows', function (string $stock, ?bool $inStock): void {
    expect($this->adapter->extract($this->url, efarmaPage(stock: $stock))->snapshot?->inStock)->toBe($inStock);
})->with([
    'out of stock' => ['<label class="col_warning">niet op voorraad</label>', false],
    'no label' => ['', null],
]);

test('a page without the price fails instead of guessing', function (): void {
    $result = $this->adapter->extract($this->url, efarmaPage(price: '<span class="euro_price"> 20,</span>'));

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('efarma_no_price');
});

test('a page describing a different article than the URL is refused', function (): void {
    $result = $this->adapter->extract('https://www.efarma.nl/ander-product/12345678', efarmaPage());

    expect($result->isSuccess())->toBeFalse()
        ->and($result->failureReason)->toBe('efarma_product_mismatch');
});
