<?php declare(strict_types=1);

use App\Billing\BillingGate;
use App\Support\JsonLd;
use App\Support\StructuredData;
use Illuminate\Support\Facades\Config;

/**
 * @return array<mixed>
 */
function graphFrom(string $url): array
{
    $content = (string) test()->get($url)->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $content, $matches);

    $decoded = json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<mixed>|null
 */
function nodeOfType(string $url, string $type): ?array
{
    $nodes = graphFrom($url)['@graph'] ?? [];

    $node = collect(is_array($nodes) ? $nodes : [])->firstWhere('@type', $type);

    return is_array($node) ? $node : null;
}

test('the JSON-LD context survives Blade compilation', function (string $url): void {
    // Laravel 13 compiles `@context` as a Blade directive, so a literal
    // '@context' key written inside a view is rewritten into a PHP context
    // block and the whole graph becomes unparseable. Building the array in
    // PHP is what keeps this key intact.
    expect(graphFrom($url)['@context'] ?? null)->toBe('https://schema.org');
})->with(['homepage' => '/', 'pricing' => '/pricing', 'privacy' => '/privacy', 'use case' => '/price-alerts/groceries']);

test('no rendered JSON-LD carries a compiled PHP fragment', function (string $url): void {
    $content = (string) $this->get($url)->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $content, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $json) {
        expect($json)->not->toContain('__contextArgs')
            ->and($json)->not->toContain('<?php');
    }
})->with(['homepage' => '/', 'pricing' => '/pricing', 'privacy' => '/privacy', 'use case' => '/price-alerts/groceries']);

test('the homepage graph introduces the publisher, the site and the app', function (string $type): void {
    expect(nodeOfType('/', $type))->toBeArray();
})->with(['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage']);

test('every node identifier is absolute so the graph links across pages', function (): void {
    $base = rtrim(Config::string('app.url'), '/');

    expect($base)->not->toBe('');

    foreach (['/', '/pricing', '/privacy'] as $url) {
        $nodes = graphFrom($url)['@graph'] ?? [];
        assert(is_array($nodes));

        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['@id'])) {
                continue;
            }

            $id = $node['@id'];

            if (! is_string($id)) {
                throw new RuntimeException('A node @id must be a string.');
            }

            expect(str_starts_with($id, $base))->toBeTrue("{$id} is not an absolute node id");
        }
    }
});

test('the pricing page points at the same application entity as the homepage', function (): void {
    expect(nodeOfType('/pricing', 'SoftwareApplication')['@id'] ?? null)
        ->toBe(nodeOfType('/', 'SoftwareApplication')['@id'] ?? null);
});

test('the site node names the organisation that publishes it', function (): void {
    expect(nodeOfType('/', 'WebSite')['publisher']['@id'] ?? null)
        ->toBe(nodeOfType('/', 'Organization')['@id'] ?? null);
});

test('the organisation publishes a contact address when one is configured', function (): void {
    config()->set('site.contact_email', 'hoi@dipcatch.eu');

    expect(nodeOfType('/', 'Organization')['email'] ?? null)->toBe('hoi@dipcatch.eu');
});

test('the organisation keeps quiet when no contact address is set', function (): void {
    config()->set('site.contact_email');

    expect(nodeOfType('/', 'Organization'))->toBeArray()
        ->and(nodeOfType('/', 'Organization'))->not->toHaveKey('email');
});

test('the free plan is always on offer', function (): void {
    config()->set('plans.stripe.pro_price_id');

    $offers = StructuredData::offers();

    expect($offers)->toHaveCount(1)
        ->and($offers[0]['name'])->toBe('Free')
        ->and($offers[0]['price'])->toBe('0');
});

test('the paid plan stays off the graph until Stripe is configured', function (): void {
    config()->set('plans.stripe.pro_price_id');

    expect(collect(StructuredData::offers())->pluck('name'))->not->toContain('Pro');
});

function openTheShop(): void
{
    config()->set('plans.stripe.pro_price_id', 'price_123');
    config()->set('cashier.key', 'pk_test');
    config()->set('cashier.secret', 'sk_test');
    config()->set('cashier.webhook.secret', 'whsec_test');
    config()->set('plans.enabled', true);
}

test('the paid plan is a pre-order while billing is shut', function (): void {
    openTheShop();
    config()->set('plans.enabled', false);
    config()->set('plans.stripe.pro_amount', '4.99');

    expect(BillingGate::isOpen())->toBeFalse();

    $pro = collect(StructuredData::offers())->firstWhere('name', 'Pro');

    expect($pro['availability'] ?? null)->toBe('https://schema.org/PreOrder')
        ->and($pro['price'] ?? null)->toBe('4.99')
        ->and($pro['priceSpecification']['unitCode'] ?? null)->toBe('MON')
        ->and($pro['priceSpecification']['billingIncrement'] ?? null)->toBe(1);
});

test('the paid plan is in stock once billing is open', function (): void {
    openTheShop();

    expect(BillingGate::isOpen())->toBeTrue();

    $pro = collect(StructuredData::offers())->firstWhere('name', 'Pro');

    expect($pro['availability'] ?? null)->toBe('https://schema.org/InStock');
});

test('the trial is its own free offer, measured in days', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_123');
    config()->set('plans.stripe.trial_days', 14);

    $trial = collect(StructuredData::offers())->firstWhere('name', 'Pro trial');

    expect($trial)->toBeArray()
        ->and($trial['price'] ?? null)->toBe('0')
        ->and($trial['eligibleDuration']['value'] ?? null)->toBe(14)
        ->and($trial['eligibleDuration']['unitCode'] ?? null)->toBe('DAY');
});

test('no trial offer appears when there is no trial', function (): void {
    config()->set('plans.stripe.pro_price_id', 'price_123');
    config()->set('plans.stripe.trial_days', 0);

    expect(collect(StructuredData::offers())->pluck('name'))->not->toContain('Pro trial');
});

test('the free offer describes the limits it actually enforces', function (): void {
    config()->set('plans.free.max_products', 20);
    config()->set('plans.free.max_shops_per_product', 4);

    expect(StructuredData::offers()[0]['description'])->toBe('Up to 20 products, with up to 4 shops each.');
});

test('an unlimited free plan is described as unlimited rather than as null', function (): void {
    config()->set('plans.free.max_products');

    expect(StructuredData::offers()[0]['description'])->toBe('Unlimited products.');
});

test('the privacy page dates itself from the value the page shows its readers', function (): void {
    config()->set('site.privacy_updated_at', '2026-02-14');

    expect(nodeOfType('/privacy', 'WebPage')['dateModified'] ?? null)->toBe('2026-02-14');
});

test('the privacy page omits a date when none is configured', function (): void {
    config()->set('site.privacy_updated_at');

    expect(nodeOfType('/privacy', 'WebPage'))->toBeArray()
        ->and(nodeOfType('/privacy', 'WebPage'))->not->toHaveKey('dateModified');
});

test('the deeper marketing pages offer a trail back to the homepage', function (string $url, string $leaf): void {
    $crumbs = nodeOfType($url, 'BreadcrumbList');

    expect($crumbs)->toBeArray()
        ->and($crumbs['itemListElement'][0]['item'] ?? null)->toBe(route('home'))
        ->and($crumbs['itemListElement'][1]['name'] ?? null)->toBe($leaf);
})->with([
    'pricing' => ['/pricing', 'Pricing'],
    'privacy' => ['/privacy', 'Privacy'],
]);

test('an answer containing a script tag cannot break out of the graph', function (): void {
    // JsonLd::script encodes with JSON_HEX_TAG for exactly this.
    $graph = StructuredData::home(
        [['q' => 'Trouble?', 'a' => 'An answer with </script><script>alert(1)</script> inside.']],
        'https://dipcatch.test',
        'A description.',
    );

    $rendered = (string) JsonLd::script($graph);

    expect($rendered)->not->toContain('</script><script>')
        ->and(substr_count($rendered, '</script>'))->toBe(1);
});

test('the offer strings are translated on the Dutch page once billing is live', function (): void {
    openTheShop();
    config()->set('plans.stripe.trial_days', 14);
    config()->set('app.locale', 'nl');

    app()->setLocale('nl');

    $names = collect(StructuredData::offers())->pluck('name');

    expect($names)->toContain('Pro-proefperiode')
        ->and($names)->not->toContain('Pro trial');
});

test('an unlimited free plan reads in Dutch on the Dutch page', function (): void {
    config()->set('plans.free.max_products');
    app()->setLocale('nl');

    expect(StructuredData::offers()[0]['description'])->toBe('Onbeperkt producten.');
});

test('an unlimited shop count reads in Dutch too', function (): void {
    config()->set('plans.free.max_products', 20);
    config()->set('plans.free.max_shops_per_product');
    app()->setLocale('nl');

    expect(StructuredData::offers()[0]['description'])
        ->toBe('Tot 20 producten, met zoveel winkels per product als je wilt.');
});
