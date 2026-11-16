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

test('a rule with many wildcards still matches a long address', function (): void {
    // As a regex, each `*` became `.*`, and a long
    // address ran the match into the backtrack limit. preg_match then failed,
    // which read as "no match", so a forbidden page was fetched.
    fakeRobots("User-agent: *\nDisallow: /*a*a*a*a*a*a*a*a*a*a*b\n");

    app(ShopFetcher::class)->fetch('https://shop.example.com/' . str_repeat('a', 20) . 'b' . str_repeat('a', 300));
})->throws(RobotsDisallowed::class);

test('a robots.txt full of wildcard rules does not stall a fetch', function (): void {
    // The shop writes its robots.txt, and every fetch on the host checks each
    // rule against the address.
    fakeRobots("User-agent: *\n" . str_repeat("Disallow: /*a*a*a*a*a*a*a*a*a*a*b\n", 500));

    $started = microtime(true);
    $response = app(ShopFetcher::class)->fetch('https://shop.example.com/b' . str_repeat('a', 300));

    expect($response->html)->toContain('ok')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

test('a trailing dollar rule refuses the address it matches', function (string $rule, string $path): void {
    fakeRobots("User-agent: *\nDisallow: {$rule}\n");

    app(ShopFetcher::class)->fetch('https://shop.example.com' . $path);
})->with([
    'with a wildcard' => ['/*.pdf$', '/docs/file.pdf'],
    'without a wildcard' => ['/cart$', '/cart'],
])->throws(RobotsDisallowed::class);

test('a trailing dollar rule without a wildcard lets a longer address through', function (): void {
    fakeRobots("User-agent: *\nDisallow: /cart$\n");

    $response = app(ShopFetcher::class)->fetch('https://shop.example.com/cart/items');

    expect($response->html)->toContain('ok');
});
