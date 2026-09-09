<?php declare(strict_types=1);

use App\Support\Favicon;
use App\Support\MarketingPages;
use App\Support\ShopPages;
use App\Support\UseCases;
use Illuminate\Support\Facades\Config;

/**
 * The page's JSON-LD, narrowed once so every test below reads typed data.
 *
 * @return array{context: string, nodes: list<array<int|string, mixed>>}
 */
function useCaseGraph(string $url): array
{
    $content = (string) test()->get($url)->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $content, $match);

    if (! isset($match[1])) {
        throw new RuntimeException('No JSON-LD script on ' . $url);
    }

    $decoded = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('The JSON-LD on ' . $url . ' is not an object');
    }

    $context = $decoded['@context'] ?? null;
    $graph = $decoded['@graph'] ?? null;

    if (! is_string($context) || ! is_array($graph)) {
        throw new RuntimeException('The JSON-LD on ' . $url . ' has no @context or @graph');
    }

    $nodes = [];

    foreach ($graph as $node) {
        if (! is_array($node)) {
            throw new RuntimeException('The graph on ' . $url . ' holds a non-object node');
        }

        $nodes[] = $node;
    }

    return ['context' => $context, 'nodes' => $nodes];
}

/**
 * The `@type` of every node in a graph.
 *
 * @param  list<array<int|string, mixed>>  $nodes
 * @return list<string>
 */
function graphTypes(array $nodes): array
{
    $types = [];

    foreach ($nodes as $node) {
        $type = $node['@type'] ?? null;

        if (is_string($type)) {
            $types[] = $type;
        }
    }

    return $types;
}

test('every configured use case has copy and shops', function (): void {
    $cases = UseCases::all();

    expect($cases)->toHaveCount(count(Config::array('site.use_cases')));

    foreach ($cases as $case) {
        expect($case->heading)->not->toBe('')
            ->and($case->intro)->not->toBe('')
            ->and($case->example)->not->toBe('')
            ->and($case->description)->not->toBe('')
            ->and($case->faq)->not->toBeEmpty()
            ->and($case->shops())->not->toBeEmpty();
    }
});

test('each use case reads as its own page, not a filled template', function (): void {
    $headings = [];
    $intros = [];

    foreach (UseCases::all() as $case) {
        $headings[] = $case->heading;
        $intros[] = $case->intro;
    }

    expect(array_unique($headings))->toHaveCount(count($headings))
        ->and(array_unique($intros))->toHaveCount(count($intros));
});

test('a use-case page renders its own heading, description and canonical', function (string $slug): void {
    $case = UseCases::find($slug);

    expect($case)->not->toBeNull();
    assert($case !== null);

    $content = (string) $this->get($case->url())->assertOk()->getContent();

    expect($content)->toContain('<h1 class="max-w-[24ch]')
        ->and($content)->toContain(e($case->heading))
        ->and($content)->toContain(e($case->intro))
        ->and($content)->toContain(e($case->example))
        ->and($content)->toContain('<link rel="canonical" href="' . $case->url() . '">');
})->with(['groceries', 'pet-food', 'coffee', 'filters']);

test('an unknown slug is a 404', function (): void {
    $this->get('/price-alerts/nonsense')->assertNotFound();
});

test('the Dutch variant is canonical to itself and reciprocal with the English one', function (): void {
    $case = UseCases::find('coffee');
    assert($case !== null);

    $content = (string) $this->get($case->url('nl'))->assertOk()->getContent();

    expect($content)->toContain('<link rel="canonical" href="' . $case->url('nl') . '">')
        ->and($content)->toContain('<link rel="alternate" hreflang="en" href="' . $case->url() . '">')
        ->and($content)->toContain('<link rel="alternate" hreflang="nl" href="' . $case->url('nl') . '">')
        ->and($content)->toContain('<html lang="nl"');
});

test('the graph carries the application, the FAQ and a breadcrumb', function (): void {
    $case = UseCases::find('groceries');
    assert($case !== null);

    $graph = useCaseGraph($case->url());

    expect($graph['context'])->toBe('https://schema.org')
        ->and(graphTypes($graph['nodes']))->toContain('SoftwareApplication')
        ->and(graphTypes($graph['nodes']))->toContain('FAQPage')
        ->and(graphTypes($graph['nodes']))->toContain('BreadcrumbList');

    $questions = [];

    foreach ($graph['nodes'] as $node) {
        if (($node['@type'] ?? null) !== 'FAQPage') {
            continue;
        }

        $entities = $node['mainEntity'] ?? null;

        if (! is_array($entities)) {
            throw new RuntimeException('The FAQPage node carries no mainEntity list');
        }

        foreach ($entities as $entity) {
            $name = is_array($entity) ? ($entity['name'] ?? null) : null;

            if (! is_string($name)) {
                throw new RuntimeException('A Question node carries no string name');
            }

            $questions[] = $name;
        }
    }

    expect($questions)->toBe(array_map(static fn (array $item): string => $item['q'], $case->faq));
});

test('the application node is the same entity as on the homepage', function (): void {
    $idOf = function (string $url): string {
        foreach (useCaseGraph($url)['nodes'] as $node) {
            if (($node['@type'] ?? null) === 'SoftwareApplication') {
                $id = $node['@id'] ?? null;

                if (! is_string($id)) {
                    throw new RuntimeException('The application node carries no string @id on ' . $url);
                }

                return $id;
            }
        }

        throw new RuntimeException('No application node on ' . $url);
    };

    expect($idOf('/price-alerts/groceries'))->toBe($idOf('/'));
});

test('the sitemap lists every use-case page in both locales', function (): void {
    $locs = array_map(static fn (array $entry): string => $entry['loc'], MarketingPages::all());

    foreach (UseCases::all() as $case) {
        expect($locs)->toContain($case->url())
            ->and($locs)->toContain($case->url('nl'));
    }

    // Home, pricing, privacy and the shops hub, plus one per use case and one
    // per shop, each in two locales.
    expect($locs)->toHaveCount((4 + count(UseCases::all()) + count(ShopPages::all())) * 2);
});

test('a shop dropped from the supported hosts disappears from the page', function (): void {
    $case = UseCases::find('pet-food');
    assert($case !== null);

    expect(array_column($case->shops(), 'host'))->toContain('zooplus.nl');

    $hosts = array_values(array_filter(Config::array('site.supported_hosts'), 'is_string'));

    Config::set('site.supported_hosts', array_values(array_diff($hosts, ['zooplus.nl'])));

    expect(array_column($case->shops(), 'host'))->not->toContain('zooplus.nl');

    // The prose still names Zooplus — that is written copy, not a live claim
    // about what the app supports. What must go is the shop pill.
    $content = (string) $this->get($case->url())->assertOk()->getContent();

    // e(): the view emits the URL through {{ }}, so its `&` is already `&amp;`.
    // Without this the needle never appears and the assertion cannot fail.
    expect($content)->not->toContain("url('" . e(Favicon::url('zooplus.nl', 32)) . "')")
        ->and($content)->toContain("url('" . e(Favicon::url('bol.com', 32)) . "')");
});

test('a use case whose shops are all gone omits the block instead of showing an empty one', function (): void {
    Config::set('site.supported_hosts', ['ah.nl']);

    $case = UseCases::find('filters');
    assert($case !== null);

    expect($case->shops())->toBe([]);

    $this->get($case->url())->assertOk()->assertDontSee(__('Which shops this works with'));
});

test('a use-case page marks no header link as the current page', function (): void {
    // The header links Pricing only (the use-case links live in the footer),
    // so a use-case page must highlight nothing. Pricing itself highlights one
    // link per nav, and the header renders two navs: desktop and mobile.
    $useCase = (string) $this->get('/price-alerts/coffee')->assertOk()->getContent();
    $pricing = (string) $this->get('/pricing')->assertOk()->getContent();

    expect(substr_count($useCase, 'aria-current="page"'))->toBe(0)
        ->and(substr_count($pricing, 'aria-current="page"'))->toBe(2);
});

test('the homepage and the footers link every use-case page', function (): void {
    $home = (string) $this->get('/')->assertOk()->getContent();
    $pricing = (string) $this->get('/pricing')->assertOk()->getContent();
    $privacy = (string) $this->get('/privacy')->assertOk()->getContent();

    foreach (UseCases::all() as $case) {
        expect($home)->toContain('href="' . $case->url() . '"')
            ->and($pricing)->toContain('href="' . $case->url() . '"')
            ->and($privacy)->toContain('href="' . $case->url() . '"');
    }
});

test('llms.txt lists every use-case page', function (): void {
    $content = (string) $this->get('/llms.txt')->assertOk()->getContent();

    foreach (UseCases::all() as $case) {
        expect($content)->toContain($case->url())
            ->and($content)->toContain($case->heading);
    }
});
