<?php declare(strict_types=1);

use App\Support\Favicon;
use App\Support\ShopPages;
use App\Support\SupportedShops;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Lang;

it('serves a page for every supported shop', function (): void {
    foreach (ShopPages::all() as $shop) {
        $this->get($shop->url())->assertOk()->assertSeeHtml($shop->name)->assertSeeHtml($shop->host);
    }
})->skip(fn (): bool => ShopPages::all() === [], 'No shops configured.');

it('refuses a host that is not supported', function (): void {
    $this->get('/shops/not-a-shop')->assertNotFound();
});

it('does not keep a landing page for an adapter TLD that is not marketed', function (): void {
    $this->get('/shops/amazon-de')->assertNotFound();
});

it('states the offer window only for the shops whose adapter reads one', function (): void {
    // Albert Heijn goes through the mobile API, which returns the Bonus
    // window; bol.com has no promotion parsing, so the page must not claim it.
    $this->get(route('shop', ['slug' => 'ah-nl']))->assertOk()->assertSeeHtml('including their end dates');

    $this->get(route('shop', ['slug' => 'bol-com']))->assertOk()->assertDontSeeHtml('including their end dates');
});

it('explains how Jumbo multi-buy prices work', function (): void {
    $this->get(route('shop', ['slug' => 'jumbo-com']))->assertOk()->assertSeeHtml('2 for €4')->assertSeeHtml('how many you need to buy')->assertDontSeeHtml('leave the shelf price alone');
});

it('links each shop page to the categories that name it, and no others', function (): void {
    $shop = ShopPages::find('ah-nl');

    // Asserted on the page object, not on the markup: the footer links every
    // category from every page, so the rendered HTML cannot tell the two
    // kinds of link apart.
    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['groceries', 'pet-food', 'coffee', 'beauty']);

    $this->get(route('shop', ['slug' => 'ah-nl']))->assertOk()->assertSeeHtml('What people track at Albert Heijn');
});

it('links Amazon UK and US shops to groceries, coffee and filters', function (string $slug): void {
    $shop = ShopPages::find($slug);

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['groceries', 'coffee', 'filters']);
})->with(['amazon-com', 'amazon-co-uk']);

it('links Amazon.nl to groceries, pet food, coffee, filters and beauty', function (): void {
    $shop = ShopPages::find('amazon-nl');

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['groceries', 'pet-food', 'coffee', 'filters', 'beauty']);
});

it('links Etos, The Ordinary, Lookfantastic, Cult Beauty, Ulta and Walmart to the beauty category only', function (string $slug): void {
    $shop = ShopPages::find($slug);

    $slugs = array_map(static fn ($useCase): string => $useCase->slug, $shop?->relatedUseCases() ?? []);

    expect($slugs)->toBe(['beauty']);
})->with(['etos-nl', 'theordinary-com', 'lookfantastic-com', 'cultbeauty-com', 'ulta-com', 'walmart-com']);

it('states that Dierapotheker reads the article number', function (): void {
    $this->get(route('shop', ['slug' => 'dierapotheker-nl']))->assertOk()->assertSeeHtml('article number');
});

it('lists every shop on the hub, with a link to each', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $response = $this->get(route('shops'))->assertOk()->assertSeeHtml('Every shop we know well')->assertSeeHtml('Paste a product link from almost any webshop and it works')->assertSeeHtml('Request a shop')->assertSeeHtml('mailto:hello@example.test?subject=');

    foreach (ShopPages::all() as $shop) {
        $response->assertSeeHtml(route('shop', ['slug' => $shop->slug]));
    }
});

it('carries a canonical, both hreflang alternates and the FAQ graph', function (): void {
    $content = (string) $this->get(route('shop', ['slug' => 'dirk-nl']))->assertOk()->getContent();

    expect($content)->toContain('<link rel="canonical" href="' . route('shop', ['slug' => 'dirk-nl']) . '">')
        ->toContain('hreflang="nl" href="' . route('shop', ['slug' => 'dirk-nl', 'lang' => 'nl']))
        ->toContain('"@type":"FAQPage"')
        ->toContain('"@type":"BreadcrumbList"');
});

it('renders the Dutch page in Dutch', function (): void {
    $this->get(route('shop', ['slug' => 'ah-nl', 'lang' => 'nl']))->assertOk()->assertSeeHtml('Prijsalarm voor Albert Heijn')->assertDontSeeHtml('Price alerts for Albert Heijn');
});

it('says a shop page is not the only shop that works', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $this->get(route('shop', ['slug' => 'ah-nl']))->assertOk()->assertSeeHtml('Does DipCatch only work at Albert Heijn?')->assertSeeHtml('Request a shop')->assertSeeHtml('mailto:hello@example.test?subject=')->assertSeeHtml(rawurlencode('Request ah.nl on DipCatch'));
});

it('reaches the shops hub from the homepage', function (): void {
    $this->get('/')->assertOk()->assertSeeHtml(route('shops'));
});

test('the old poiesz address redirects to the corrected one', function (): void {
    // The marketed host was corrected from `poiesz.nl`, which the chain does
    // not use, to the one its webshop answers on. The old page was in the
    // sitemap, so it must not 404.
    $this->get('/shops/poiesz-nl')
        ->assertRedirect('/shops/poiesz-supermarkten-nl')
        ->assertStatus(301);

    $this->get('/shops/poiesz-supermarkten-nl')->assertOk()->assertSee('Poiesz');
});

test('the poiesz redirect keeps the language query', function (): void {
    $this->get('/shops/poiesz-nl?lang=nl')
        ->assertRedirect('/shops/poiesz-supermarkten-nl?lang=nl');
});

/**
 * A translator that refuses to translate. `__()` goes through
 * `app('translator')->get()`, so anything that builds page copy throws here
 * and names itself.
 */
function refusingTranslator(): Translator
{
    return new class implements Translator {
        public function get($key, array $replace = [], $locale = null): string
        {
            throw new RuntimeException('Built page copy: ' . $key);
        }

        public function choice($key, $number, array $replace = [], $locale = null): string
        {
            throw new RuntimeException('Built page copy: ' . $key);
        }

        public function getLocale(): string
        {
            return 'en';
        }

        public function setLocale($locale): void {}
    };
}

it('matches shop routes without building any page copy', function (): void {
    // `slugPattern()` runs at route registration, before the locale
    // middleware. `UseCases::slugPattern()` documents this and reads config;
    // this one built all 27 pages, about 16 `__()` lookups each.
    $expected = ShopPages::slugPattern();

    Lang::swap(refusingTranslator());

    expect(ShopPages::slugPattern())->toBe($expected)
        ->and(ShopPages::slugs())->not->toBeEmpty();
});

it('rejects an unknown slug without building any page copy', function (): void {
    Lang::swap(refusingTranslator());

    expect(ShopPages::find('not-a-shop'))->toBeNull();
});

it('carries a slug on the identity row, for a host nobody has named', function (): void {
    // The slug belongs to the host, not to the copy. Every list that only
    // links shops reads it from here instead of building 27 pages.
    config()->set('site.supported_hosts', ['ah.nl', 'unnamed.example']);
    config()->set('site.shop_names', ['ah.nl' => 'Albert Heijn']);

    Lang::swap(refusingTranslator());

    expect(SupportedShops::rows())->toBe([
        ['host' => 'ah.nl', 'favicon' => Favicon::url('ah.nl', 32), 'name' => 'Albert Heijn', 'slug' => 'ah-nl'],
        ['host' => 'unnamed.example', 'favicon' => Favicon::url('unnamed.example', 32), 'name' => 'unnamed.example', 'slug' => 'unnamed-example'],
    ])
        ->and(ShopPages::slugs())->toBe(['ah-nl', 'unnamed-example']);
});
