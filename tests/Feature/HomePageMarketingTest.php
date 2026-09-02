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
        ->assertSee('Supermarket price alerts for the Netherlands');
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
    config()->set('site.contact_email', null);
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
        ->assertSee('Same product, every supermarket, one alert.')
        ->assertSee('compares them on price per kilo');
});

test('the phone mock shows grocery examples from supported shops only', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Lay’s Naturel 200 g', escape: false)
        ->assertSee('Beemster Extra Belegen 48+ 150 g', escape: false)
        ->assertSee('Page toiletpapier 24 rollen')
        ->assertSee('ah.nl')
        ->assertSee('dirk.nl')
        ->assertSee('jumbo.com')
        ->assertDontSee('mediamarkt.nl')
        ->assertDontSee('amazon.com');
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

test('the mobile shop chips fall back to a count of the remaining hosts', function (): void {
    $hosts = SupportedShops::rows();

    expect(count($hosts))->toBeGreaterThan(8);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('+' . (count($hosts) - 8) . ' more');
});

test('the privacy page explains that shared product images load from the shop', function (): void {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('loaded straight from the shop’s own servers', escape: false);
});

test('the FAQ section shows six questions', function (): void {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Which shops work?')
        ->assertSee('How often are prices checked?')
        ->assertSee('Is it free?')
        ->assertSee('Do I need an extension or app?')
        ->assertSee('Can I compare different pack sizes?')
        ->assertSee('Can I share a comparison?');
});

test('the FAQ JSON-LD matches the six visible questions and has plain-text answers', function (): void {
    $content = $this->get(route('home'))->assertOk()->getContent();

    $found = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $content, $matches);

    expect($found)->toBe(1);

    $jsonLd = json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);

    expect($jsonLd)->toBeArray()
        ->and($jsonLd['@type'] ?? null)->toBe('FAQPage');

    $entities = $jsonLd['mainEntity'] ?? null;
    expect($entities)->toBeArray()->toHaveCount(6);
    assert(is_array($entities));

    preg_match_all('#<summary[^>]*>\s*<span>(.*?)</span>#s', (string) $content, $summaryMatches);
    $visibleQuestions = array_map(
        static fn (string $q): string => trim(html_entity_decode($q, ENT_QUOTES | ENT_HTML5)),
        $summaryMatches[1],
    );

    expect($visibleQuestions)->toHaveCount(6);

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
