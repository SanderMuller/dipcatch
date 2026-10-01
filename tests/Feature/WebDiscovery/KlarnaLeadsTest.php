<?php declare(strict_types=1);

use App\Services\ShopDiscovery\KlarnaLeads;
use App\Services\ShopDiscovery\ShopLead;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

function klarnaHillsUrl(): string
{
    return 'https://www.klarna.com/nl/shopping/pl/cl456/3202266158/Huisdieren/Hill-s-10kg-Young-Adult-Sterilised-met-Eend-Science/';
}

function klarnaFixture(): string
{
    return File::get(base_path('tests/Fixtures/klarna/hills_sterilised_10kg.html'));
}

test('a Klarna page lists each shop with its website, price, title, size and stock', function (): void {
    $leads = KlarnaLeads::fromHtml(klarnaFixture(), klarnaHillsUrl());

    expect(array_map(static fn (ShopLead $lead): array => [$lead->shopName, $lead->host, $lead->price, $lead->currency, $lead->inStock, $lead->packSize?->quantity], $leads))->toBe([
        ['Medpets', 'medpets.nl', '76.05', 'EUR', true, 10000.0],
        ['Brekz', 'brekz.nl', '85.69', 'EUR', null, 10000.0],
        ['Brekz', 'brekz.nl', '167.95', 'EUR', null, 20000.0],
        ['Zooplus', 'zooplus.nl', '81.99', 'EUR', true, 10000.0],
        ['Zooplus', 'zooplus.nl', '162.99', 'EUR', true, 20000.0],
    ]);
});

test('offer titles come out without HTML entities', function (): void {
    $leads = KlarnaLeads::fromHtml(klarnaFixture(), klarnaHillsUrl());

    expect($leads[1]->title)->toBe("Hill's Adult Sterilised Cat met eend kattenvoer 10 kg");
});

test('an offer whose shop names no website is left out', function (): void {
    $html = str_replace('merchantUrl=brekz.nl', 'merchantUrl=', klarnaFixture());

    $hosts = array_map(static fn (ShopLead $lead): string => $lead->host, KlarnaLeads::fromHtml($html, klarnaHillsUrl()));

    expect($hosts)->toBe(['medpets.nl', 'zooplus.nl', 'zooplus.nl']);
});

test('a page without the offer list gives no leads and says so in the log', function (): void {
    Log::spy();

    expect(KlarnaLeads::fromHtml('<html><body>changed</body></html>', klarnaHillsUrl()))->toBe([]);

    Log::shouldHaveReceived('warning')->with('klarna_payload_missing', ['url' => klarnaHillsUrl()])->once();
});

test('only a Klarna product page counts as a Klarna page', function (string $url, bool $expected): void {
    expect(KlarnaLeads::isKlarnaPage($url))->toBe($expected);
})->with([
    'product page' => [klarnaHillsUrl(), true],
    'without www' => ['https://klarna.com/de/shopping/pl/cl1/123/x/', true],
    'search' => ['https://www.klarna.com/nl/shopping/results/?q=hills', false],
    'click link' => ['https://clk.klarna.com/nl/gotostore/v1/abc', false],
    'home' => ['https://www.klarna.com/nl/', false],
    'another site' => ['https://www.zooplus.nl/nl/shopping/pl/1/', false],
]);

test('an offer title spread over lines and spaces comes out on one line', function (): void {
    // A JSON newline escape and extra spaces inside the title.
    $html = str_replace('Eend - 10 kg"', 'Eend -\n   10 kg  "', klarnaFixture());

    expect($html)->not->toBe(klarnaFixture())
        ->and(KlarnaLeads::fromHtml($html, klarnaHillsUrl())[0]->title)->toBe("Hill's Science Plan Sterilised Cat - Adult - Eend - 10 kg");
});
