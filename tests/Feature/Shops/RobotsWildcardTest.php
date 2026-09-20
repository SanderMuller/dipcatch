<?php declare(strict_types=1);

use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * `*` in a robots.txt rule used to be matched literally, which voided every
 * rule that used one — and most shops write their rules that way. Etos
 * disallows `/*_*`, any address holding an underscore, for all crawlers; we
 * read that as the literal prefix `/*_*`, matched nothing, and fetched what
 * they had forbidden.
 */
beforeEach(function (): void {
    Cache::flush();
});

function fakeRobots(string $body): void
{
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response($body, 200),
        'https://shop.example.com/*' => Http::response('<html><body>ok</body></html>', 200),
    ]);
}

test('a wildcard disallow is honoured', function (string $path): void {
    fakeRobots("User-agent: *\nDisallow: /*_*\nDisallow: /*srule=\n");

    app(ShopFetcher::class)->fetch('https://shop.example.com' . $path);
})->with([
    'a wildcard in the middle of the path' => ['/producten/foo_bar.html'],
    'a wildcard against the query' => ['/producten/foo.html?srule=price-low-to-high'],
])->throws(RobotsDisallowed::class);

test('a page the rules do not cover is still fetched', function (): void {
    fakeRobots("User-agent: *\nDisallow: /*_*\nDisallow: /cart/\n");

    $response = app(ShopFetcher::class)->fetch('https://shop.example.com/producten/sensodyne-75-ml.html');

    expect($response->html)->toContain('ok');
});

test('an allow beats a disallow when it matches more of the address', function (): void {
    // Longest match wins, which is what lets a shop close a whole tree and
    // reopen one branch of it.
    fakeRobots("User-agent: *\nDisallow: /*_*\nAllow: /*sitemap*\n");

    $response = app(ShopFetcher::class)->fetch('https://shop.example.com/sitemap_index.xml');

    expect($response->html)->toContain('ok');
});

test('a trailing dollar anchors the end of the address', function (): void {
    fakeRobots("User-agent: *\nDisallow: /*.pdf$\n");

    $response = app(ShopFetcher::class)->fetch('https://shop.example.com/folder.pdf.html');

    expect($response->html)->toContain('ok');
});

test('a rule written for our own name is obeyed', function (): void {
    // The name we send is the name we publish, so a shop following the /bot
    // page's instructions is heard.
    fakeRobots("User-agent: DipCatchBot\nDisallow: /producten/\n\nUser-agent: *\nDisallow:\n");

    app(ShopFetcher::class)->fetch('https://shop.example.com/producten/anything.html');
})->throws(RobotsDisallowed::class);
