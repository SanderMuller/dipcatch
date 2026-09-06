<?php declare(strict_types=1);

use Illuminate\Support\Facades\Config;

test('an assistant fetching llms.txt gets plain text it can read', function (): void {
    $response = $this->get('/llms.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and((string) $response->getContent())->toStartWith('# ' . Config::string('app.name'));
});

test('llms.txt sets no cookie, so it can be cached at the edge', function (): void {
    $response = $this->get('/llms.txt')->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600');
});

test('llms.txt opens with a summary an assistant can quote', function (): void {
    $content = (string) $this->get('/llms.txt')->assertOk()->getContent();

    expect($content)->toContain('> ')
        ->and($content)->toContain('price-alert service for the Netherlands');
});

test('llms.txt describes the product as more than groceries', function (): void {
    $content = (string) $this->get('/llms.txt')->assertOk()->getContent();

    expect($content)->toContain('pet food')
        ->and($content)->toContain('filters');
});

test('llms.txt links the pages worth reading, built from the routes themselves', function (): void {
    $this->get('/llms.txt')
        ->assertOk()
        ->assertSee(route('home'))
        ->assertSee(route('pricing'))
        ->assertSee(route('privacy'))
        ->assertSee(route('register'))
        ->assertSee(route('bot'));
});

test('the plan limits in llms.txt follow the config, not a typed-in number', function (): void {
    config()->set('plans.free.max_products', 33);
    config()->set('plans.free.max_shops_per_product', 9);

    $this->get('/llms.txt')->assertOk()->assertSee('Free: 33 products, 9 shops per product.');
});

test('an unlimited free plan reads as unlimited rather than as nothing', function (): void {
    config()->set('plans.free.max_products', null);

    $this->get('/llms.txt')->assertOk()->assertSee('Free: unlimited products');
});

test('the crawl rate in llms.txt follows the configured recheck interval', function (): void {
    config()->set('dipcatch.recheck.interval_hours', 18);

    $this->get('/llms.txt')->assertOk()->assertSee('every 18 hours');
});

test('llms.txt offers a contact address only when one is configured', function (): void {
    config()->set('site.contact_email', 'hoi@dipcatch.eu');
    $this->get('/llms.txt')->assertOk()->assertSee('Contact: hoi@dipcatch.eu');

    config()->set('site.contact_email', null);
    $this->get('/llms.txt')->assertOk()->assertDontSee('Contact:');
});

test('robots.txt and llms.txt agree on where the crawler explains itself', function (): void {
    $this->get('/llms.txt')->assertOk()->assertSee('DipCatchBot');

    expect((string) file_get_contents(public_path('robots.txt')))->toContain('/llms.txt');
});

test('llms.txt does not HTML-escape its values, because it is not HTML', function (): void {
    config()->set('site.contact_email', "o'brien@dipcatch.eu");

    $body = (string) $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain("o'brien@dipcatch.eu")
        ->and($body)->not->toContain('&#039;');
});

test('llms.txt uses the approved positioning wording', function (): void {
    $body = (string) $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain('the things you buy anyway')
        ->and($body)->not->toContain('things you buy again');
});
