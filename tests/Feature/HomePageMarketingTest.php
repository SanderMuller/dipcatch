<?php declare(strict_types=1);

use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\SupportedShops;

test('the homepage carries SEO and sharing meta', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="description"', escape: false)
        ->assertSee('property="og:title"', escape: false)
        ->assertSee('rel="canonical"', escape: false)
        ->assertSee('Price alerts for the things you buy anyway');
});

test('guests see the supported shops, a bottom call to action, and the footer links', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Works with')
        ->assertSee('ah.nl')
        ->assertSee('Stop checking prices by hand.')
        ->assertSee(route('privacy'))
        ->assertSee('verification email');
});

test('the header offers account creation to guests and the app to members', function (): void {
    $this->get(route('home'))->assertSee('Create account');

    $this->actingAs(User::factory()->create());

    $this->get(route('home'))
        ->assertSee('Open app')
        ->assertDontSee('Stop checking prices by hand.');
});

test('the contact link only renders when a contact address is configured', function (): void {
    config()->set('site.contact_email');
    $this->get(route('home'))->assertDontSee('mailto:', escape: false);

    config()->set('site.contact_email', 'hello@example.test');
    $this->get(route('home'))->assertSee('mailto:hello@example.test', escape: false);
});

test('the privacy page renders for guests', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('What we store')
        ->assertSee('Resend')
        ->assertSee('hello@example.test');
});

test('robots.txt keeps crawlers out of the app but allows the public pages', function (): void {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('Disallow: /app')
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /invite/')
        ->toContain('Allow: /');
});

test('the hero leads with the compare-across-shops headline', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Same product, every shop, one alert.')
        ->assertSee('compares them on price per kilo or per piece');
});

test('the phone mock shows grocery examples from supported shops only', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Lay’s Naturel 200 g', escape: false)
        ->assertSee('Beemster Extra Belegen 48+ 150 g', escape: false)
        ->assertSee('Page toiletpapier 24 rollen')
        ->assertSee('ah.nl')
        ->assertSee('dirk.nl')
        ->assertSee('jumbo.com')
        ->assertDontSee('mediamarkt.nl');
});

test('the phone mock is an informative image with a label matching the cards', function (): void {
    $money = static fn (string $amount): string => MoneyFormatter::format($amount, 'EUR');

    $old = $money('2.19');
    $new = __(':price (bonus)', ['price' => $money('1.69')]);
    $unit = __(':price /kg · cheapest of :count shops', ['price' => $money('8.45'), 'count' => 4]);

    $firstCard = __(':product at :shop: from :old to :new, :unit', [
        'product' => 'Lay’s Naturel 200 g',
        'shop' => 'ah.nl',
        'old' => $old,
        'new' => $new,
        'unit' => $unit,
    ]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('role="img"', escape: false)
        ->assertSee('aria-label="' . e(__('Example alerts: :items', ['items' => $firstCard])), escape: false)
        ->assertSee(e($old), escape: false)
        ->assertSee(e($new), escape: false)
        ->assertSee(e($unit), escape: false);
});

test('the homepage renders no decorative image element', function (): void {
    // A decorative <img alt=""> still becomes a bare `![](…)` reference in the
    // Markdown twin Cloudflare serves to assistants. The shop favicons and the
    // phone mock's per-card favicons are therefore background images. The
    // logo keeps its <img>: it has a real alt and converts to `![DipCatch]`.
    $content = (string) $this->get(route('home'))->assertOk()->getContent();

    preg_match_all('#<img[^>]*>#', $content, $matches);

    expect($matches[0])->each->toMatch('#alt="[^"]+"#');
});

test('the phone mock contributes no chrome to the page text', function (): void {
    // The converter ignores aria-hidden and role="img", so the only way the
    // fake status bar stays out of the Markdown is to not be a text node.
    $text = strip_tags((string) $this->get(route('home'))->assertOk()->getContent());

    expect($text)->not->toContain('9:41')
        ->and($text)->not->toContain('🥔')
        ->and($text)->not->toContain('🧀')
        ->and($text)->not->toContain('🧻');
});

test('each tracked example still carries its icon', function (): void {
    // Guards the JIT trap: a `content-['{{ $p['icon'] }}']` utility is never
    // scanned by Tailwind and would compile to nothing, silently dropping the
    // emoji. A custom property survives, so assert the property is emitted.
    $content = (string) $this->get(route('home'))->assertOk()->getContent();

    foreach (['🥔', '🧀', '🧻'] as $icon) {
        expect($content)->toContain("--icon: '" . $icon . "'");
    }

    // The property alone paints nothing. Without the utility that reads it the
    // emoji vanish, which is the exact failure the custom property avoids.
    expect(substr_count($content, 'before:content-[var(--icon)]'))->toBe(3)
        ->and(substr_count($content, 'before:content-[var(--label)]'))->toBe(2);
});

test('the shop list names every supported host without contradicting itself', function (): void {
    $hosts = SupportedShops::rows();

    $content = (string) $this->get(route('home'))->assertOk()->getContent();

    foreach ($hosts as $shop) {
        expect($content)->toContain($shop['host']);
    }

    // The overflow pill used to say "+4 more" directly under all the names,
    // which read as a contradiction once the page was flattened to Markdown.
    expect($content)->not->toContain(' more<')
        ->and($content)->toContain(__('and many other webshops'));
});

test('the privacy page explains that shared product images load from the shop', function (): void {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('loaded straight from the shop’s own servers', escape: false);
});

test('the FAQ section shows every question the page defines', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Which shops work?')
        ->assertSee('Etos, The Ordinary, Lookfantastic')
        ->assertSee('Pets Place, Medpets, Welkoop')
        ->assertSee('How often are prices checked?')
        ->assertSee('Is it free?')
        ->assertSee('Do I need an extension or app?')
        ->assertSee('Can I compare different pack sizes?')
        ->assertSee('Can I share a comparison?');
});

test('the FAQ JSON-LD matches the visible questions and has plain-text answers', function (): void {
    $content = $this->get(route('home'))->assertOk()->getContent();

    $found = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $content, $matches);

    expect($found)->toBe(1);

    $graph = json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);

    // The homepage ships one graph, so find the FAQ node by its type rather
    // than by position: adding a node must not move the goalposts.
    expect($graph)->toBeArray()
        ->and($graph['@context'] ?? null)->toBe('https://schema.org');

    assert(is_array($graph));
    $nodes = $graph['@graph'] ?? [];
    assert(is_array($nodes));

    $faqPage = collect($nodes)->firstWhere('@type', 'FAQPage');

    expect($faqPage)->toBeArray();
    assert(is_array($faqPage));

    $entities = $faqPage['mainEntity'] ?? null;
    expect($entities)->toBeArray();
    assert(is_array($entities));

    preg_match_all('#data-flux-accordion-heading[^>]*>\s*<span[^>]*>(.*?)</span>#s', (string) $content, $summaryMatches);
    $visibleQuestions = array_map(
        static fn (string $q): string => trim(html_entity_decode($q, ENT_QUOTES | ENT_HTML5)),
        $summaryMatches[1],
    );

    expect($entities)->toHaveSameSize($visibleQuestions)
        ->and($visibleQuestions)->not->toBeEmpty();

    foreach (array_values($entities) as $index => $question) {
        expect($question['@type'])->toBe('Question')
            ->and($question['name'])->toBe($visibleQuestions[$index] ?? null)
            ->and($question['acceptedAnswer']['@type'])->toBe('Answer')
            ->and($question['acceptedAnswer']['text'])->not->toMatch('/<[a-z][\s\S]*>/i');
    }
});

test('the "how often" FAQ answer reads the recheck interval from config', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 6);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('about every 6 hours');
});

test('the homepage speaks about repeat purchases, not only supermarkets', function (): void {
    // The product tracks anything that runs out: groceries, pet food,
    // filters. Copy that says "supermarket" only sells half of it.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Price alerts for the things you buy anyway')
        ->assertSee('vacuum filters')
        ->assertSee('cat food')
        ->assertSee('skincare');
});

test('shop pills carry the brand name a person would search for', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('title="Albert Heijn"', escape: false)
        ->assertSee('ah.nl');
});

test('a host nobody has named falls back to the host itself', function (): void {
    config()->set('site.supported_hosts', ['unnamed-shop.example']);
    config()->set('site.shop_names', []);

    $rows = SupportedShops::rows();

    expect($rows[0]['name'])->toBe('unnamed-shop.example');
});

test('the how-it-works steps are headings so the page has an outline', function (): void {
    $content = (string) $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($content, '<h3'))->toBeGreaterThanOrEqual(3)
        ->and($content)->not->toContain('<dt class="mt-4 text-base font-semibold">');
});

test('the free-plan answer quotes the limit the app actually enforces', function (): void {
    config()->set('plans.free.max_products', 7);

    $this->get(route('home'))->assertOk()->assertSee('your first 7 products');
});

test('the FAQ answers how to catch a lower price at another shop', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('How do I know when a product is cheaper somewhere else?');
});
