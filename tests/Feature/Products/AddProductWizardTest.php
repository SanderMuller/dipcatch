<?php declare(strict_types=1);

use App\Actions\Shops\ProbeBudget;
use App\Actions\Shops\ProbeShopUrl;
use App\Livewire\Products\AddProductWizard;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopFetcher\HostFetchMemory;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function fakeCreateFlowOffer(string $url = 'https://shop.example.com/p/1', string $price = '50.00', string $currency = 'EUR', string $title = 'Demo Item'): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: 'shop.example.com';
    $json = json_encode([
        '@type' => 'Product',
        'name' => $title,
        'image' => 'https://shop.example.com/img.jpg',
        'offers' => [
            '@type' => 'Offer',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        "https://{$host}/robots.txt" => Http::response('', 404),
        $url => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

test('probe success prefills title and image', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('title', 'Demo Item')
        ->assertSet('imageUrl', 'https://shop.example.com/img.jpg')
        ->assertSet('existingTrackedProduct', null);
});

test('the preview shows the photo large and links to the page it reads', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSeeHtml('href="https://shop.example.com/p/1" target="_blank" rel="noopener noreferrer"')
        ->assertSee('Open the page on shop.example.com')
        ->assertSeeHtml('data-test="preview-image"');
});

test('the prefilled title has the shop name and buying word taken off', function (): void {
    // Cleaning lives in the draft, so the web preview gets it as well as the
    // MCP tools. What the page calls itself is written for search engines.
    // "Example" is the shop's name in shop.example.com, and "kopen" the
    // buying word behind it.
    Http::fake(fakeCreateFlowOffer(title: 'Demo Item - 12 x 55 g kopen | Example'));
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('title', 'Demo Item - 12 x 55 g');
});

test('confirm creates product + shop + initial price check and recomputes cheapest', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->set('title', 'My Tracked Item')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        ->assertNoRedirect();

    $product = Product::query()->where('user_id', $user->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->title)->toBe('My Tracked Item')
        ->and($product->currency)->toBe('EUR')
        // No alert yet: the drop check uses the default.
        ->and($product->drop_threshold_pct)->toBeNull()
        ->and($product->drop_threshold_abs)->toBeNull()
        ->and($product->active)->toBeTrue();

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop)->not->toBeNull()
        ->and($shop->url)->toBe('https://shop.example.com/p/1')
        ->and((string) $shop->current_price)->toBe('50.00')
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);

    $product->refresh();
    expect($product->cheapest_shop_id)->toBe($shop->id)
        ->and((string) $product->cheapest_price)->toBe('50.00');
});

test('empty title blocks confirm and persists nothing', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->set('title', '')
        ->call('confirm')
        ->assertHasErrors(['title']);

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('an edited image url whose scheme is not http(s) blocks confirm', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->set('imageUrl', 'ftp://shop.example.com/img.jpg')
        ->call('confirm')
        ->assertHasErrors(['imageUrl']);

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('URL already tracked on another product of this user shows a warning but confirm still works', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $existingProduct = Product::factory()->create(['user_id' => $user->id, 'title' => 'Existing Tracker']);
    Shop::factory()->for($existingProduct)->create(['url' => 'https://shop.example.com/p/1']);
    $this->actingAs($user);

    $component = Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('existingTrackedProduct.title', 'Existing Tracker');

    $component->call('confirm')->assertHasNoErrors();

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('URL tracked only by another user shows no warning', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $otherProduct = Product::factory()->create();
    Shop::factory()->for($otherProduct)->create(['url' => 'https://shop.example.com/p/1']);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('existingTrackedProduct', null);
});

test('fetch-level failure shows the error state', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Server error', 500),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'temporary_failure')
        // The status reaches the sentence. Nothing pinned that: the template
        // falls back to '5xx', so a context key that stopped arriving would
        // still render a plausible line.
        ->assertSee('The shop is having a server problem (HTTP 500).');
});

test('an unservable shop explains itself instead of printing the raw error code', function (): void {
    Http::fake(); // any HTTP call would be an unexpected fetch — the check is host-based, before any fetch.
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://www.plus.nl/product/fanta-orange-fles-1500-ml-991700')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'shop_not_servable')
        ->assertSee('builds its prices in the browser')
        ->assertDontSee('shop_not_servable');

    Http::assertNothingSent();
});

test('extraction failure flips to manual selector and selectors create the product', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(
            '<html><head><title>Widget</title></head><body><span id="p">19.95</span></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'manual_selector')
        ->set('priceSelector', '#p')
        ->set('manualCurrency', 'EUR')
        ->call('probeWithSelectors')
        ->assertSet('state', 'preview')
        ->set('title', 'Selector Item')
        ->call('confirm')
        ->assertHasNoErrors();

    $product = Product::query()->where('user_id', $user->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->currency)->toBe('EUR');

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop->adapter_key)->toBe('user-selector')
        ->and($shop->price_selector)->toBe('#p');
});

test('abandoning after probe persists nothing', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->call('cancel')
        ->assertSet('state', 'idle');

    expect(Product::query()->count())->toBe(0)
        ->and(Shop::query()->count())->toBe(0);
});

test('create page renders the wizard, and the old manual page sends you to its by-hand mode', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/app/products/create')
        ->assertOk()
        ->assertSeeLivewire(AddProductWizard::class);

    $this->get('/app/products/create-manual?idea=coffee')
        ->assertRedirect(route('app.products.create', ['mode' => 'manual', 'idea' => 'coffee']));
});

test('extraction failure offers a shop request mailto', function (): void {
    config()->set('site.contact_email', 'hello@example.test');

    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'No-Offer Product',
    ], JSON_THROW_ON_ERROR);
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'extraction_failed')
        ->assertSee('Request a shop')
        ->assertSee(rawurlencode('https://shop.example.com/p/1'), escape: false);
});

/**
 * The probe-error partial's remaining interpolated arms. Each pulls a number
 * out of `$errorContext` — a failure count, a retry delay, an HTTP status —
 * and each falls back to a plausible-looking default when the key is absent:
 * '0', '~60', '5xx'. A key that stopped arriving would therefore still render
 * a sentence that reads fine and tells the shopper something untrue. Only
 * `probe_rate_limited`'s key was pinned anywhere, and that at the action
 * level, so these drive the real failure rather than setting the properties.
 */
test('a blocking shop names Cloudflare on the first refusal', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Forbidden', 403),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('errorCode', 'blocked')
        ->assertSee('This shop is blocking automated checks (Cloudflare/Akamai).')
        ->assertDontSee('blocked our last');
});

test('a persistently blocking shop counts the refusals instead', function (): void {
    // PERSISTENT_AFTER is 3, and this probe adds the fourth.
    foreach (range(1, 3) as $ignored) {
        app(HostFetchMemory::class)->record('shop.example.com', HostFetchMemory::KIND_BLOCKED);
    }

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Forbidden', 403),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('errorCode', 'blocked')
        ->assertSee('This shop has blocked our last 4 requests.')
        ->assertDontSee('Cloudflare');
});

test('a shop that has gone quiet for a while counts the silences', function (): void {
    foreach (range(1, 3) as $ignored) {
        app(HostFetchMemory::class)->record('shop.example.com', HostFetchMemory::KIND_SILENT);
    }

    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Server error', 500),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('errorCode', 'temporary_failure')
        // Apostrophes render as &#039;, so this asserts the half that carries
        // the interpolated count.
        ->assertSee('answered our last 4 requests')
        ->assertDontSee('server problem');
});

test('a 429 from the shop states the wait it asked for', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Slow down', 429, ['Retry-After' => '90']),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('errorCode', 'host_rate_limited')
        ->assertSee('This shop returned a rate-limit response (HTTP 429). Try again in 90 seconds.');
});

test('our own per-host throttle says so rather than blaming the shop', function (): void {
    // Spend the host bucket, so the fetch never leaves the building.
    $limit = config()->integer('dipcatch.fetcher.rate_limit_per_minute');
    foreach (range(1, $limit) as $ignored) {
        RateLimiter::hit(ShopFetcher::throttleKey('shop.example.com'));
    }

    Http::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('errorCode', 'local_throttle')
        ->assertSee('spacing out checks to this shop to be polite')
        // A real delay, not the template's '~60' fallback. The exact number
        // depends on where in the window the bucket was spent, so the tilde
        // is what separates "a value arrived" from "the key did not".
        ->assertDontSee('Try again in ~60 seconds');

    // Robots is consulted at ShopFetcher:91, before the throttle at :93, so a
    // robots request does go out. The page itself must not.
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://shop.example.com/p/1');
});

test('too many probes in a minute states the wait', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, ProbeBudget::PER_MINUTE) as $i) {
        app(ProbeShopUrl::class)(null, "https://shop.example.com/p/{$i}", $user);
    }

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/over')
        ->call('probe')
        ->assertSet('errorCode', 'probe_rate_limited')
        ->assertSee('You have checked too many links in the last minute.')
        ->assertDontSee('Try again in ~60 seconds');
});

function connectAssistantFor(User $user, bool $revoked = false): void
{
    $client = new Client();
    $client->forceFill([
        'id' => (string) Str::uuid(),
        'name' => 'Claude',
        'redirect_uris' => ['https://example.test/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ])->save();

    Token::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'scopes' => ['mcp:use'],
        'revoked' => $revoked,
        'expires_at' => now()->addDay(),
    ]);
}

test('the page offers Claude as another way to add products and shops', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->assertSeeHtml('data-test="assistant-hint"')
        ->assertSee('Add products and shops from Claude')
        ->assertSee('Connect Claude once')
        ->assertSeeHtml('href="' . route('app.connections') . '"');
});

test('with Claude connected, the hint says to ask it rather than to connect it', function (): void {
    $user = User::factory()->create();
    connectAssistantFor($user);
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->assertSee('Claude is connected.')
        ->assertDontSee('Connect Claude once');
});

test('a disconnected Claude counts as not connected', function (): void {
    $user = User::factory()->create();
    connectAssistantFor($user, revoked: true);
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->assertSee('Connect Claude once')
        ->assertDontSee('Claude is connected.');
});

test('the hint leaves once a product is looked up', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertDontSeeHtml('data-test="assistant-hint"');
});

test('a grant without the DipCatch tools scope, or an expired one, does not count as connected', function (): void {
    $user = User::factory()->create();
    connectAssistantFor($user);
    Token::query()->where('user_id', $user->getKey())->update(['scopes' => json_encode(['profile'])]);
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)->assertSee('Connect Claude once');

    Token::query()->where('user_id', $user->getKey())->update(['scopes' => json_encode(['mcp:use']), 'expires_at' => now()->subMinute()]);

    Livewire::test(AddProductWizard::class)->assertSee('Connect Claude once');
});

test('the hint names the assistant that is connected', function (): void {
    $user = User::factory()->create();
    connectAssistantFor($user);
    Client::query()->update(['name' => 'ChatGPT']);
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)
        ->assertSee('Add products and shops from ChatGPT')
        ->assertSee('ChatGPT is connected.');
});

test('a grant from a revoked client does not count as connected', function (): void {
    $user = User::factory()->create();
    connectAssistantFor($user);
    Client::query()->update(['revoked' => true]);
    $this->actingAs($user);

    Livewire::test(AddProductWizard::class)->assertSee('Connect Claude once');
});

test('the preview leads with the price per unit when the page states a pack size', function (): void {
    Http::fake(fakeCreateFlowOffer(price: '1.99', title: 'Chips naturel 370 g'));
    $this->actingAs(User::factory()->create());

    $text = (string) preg_replace('/\s+/', ' ', strip_tags(Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->html()));

    expect($text)->toContain('shop.example.com €5.38 /kg €1.99 for 370 g');
});

test('the preview leads with the pack price when the page states no size', function (): void {
    Http::fake(fakeCreateFlowOffer(price: '299.00', title: 'Camera'));
    $this->actingAs(User::factory()->create());

    Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSeeHtml('data-test="preview-price"')
        ->assertSee('€299.00')
        ->assertDontSeeHtml('data-test="preview-pack"');
});

describe('step 1 by hand', function (): void {
    it('creates a product the scraper cannot read, and moves on to the shops', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class, ['mode' => 'manual'])
            ->set('manualTitle', 'Local roastery beans')
            ->set('manualImageUrl', 'https://example.test/beans.jpg')
            ->set('currency', 'EUR')
            ->call('saveManual')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $product = Product::query()->where('user_id', $user->id)->sole();

        expect($product->title)->toBe('Local roastery beans')
            ->and($product->image_url)->toBe('https://example.test/beans.jpg')
            // No alert input yet: the default rule applies.
            ->and($product->drop_threshold_pct)->toBeNull();
    });

    it('accepts a currency typed in lower case', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class, ['mode' => 'manual'])
            ->set('manualTitle', 'Local roastery beans')
            ->set('currency', 'eur')
            ->call('saveManual')
            ->assertHasNoErrors();

        expect(Product::query()->where('user_id', $user->id)->sole()->currency)->toBe('EUR');
    });

    it('refuses a currency that is not an ISO 4217 code', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class, ['mode' => 'manual'])
            ->set('manualTitle', 'Local roastery beans')
            ->set('currency', 'ZZZ')
            ->call('saveManual')
            ->assertHasErrors('currency');

        expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    it('refuses an image url whose scheme is not http(s)', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class, ['mode' => 'manual'])
            ->set('manualTitle', 'Local roastery beans')
            ->set('manualImageUrl', 'ftp://example.test/beans.jpg')
            ->set('currency', 'EUR')
            ->call('saveManual')
            ->assertHasErrors('manualImageUrl');

        expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
    });

});

describe('the steps', function (): void {
    it('resumes on the step and product in the URL', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Coffee beans']);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
            ->test(AddProductWizard::class)
            ->assertSet('step', 3)
            ->assertSet('title', 'Coffee beans')
            ->assertSeeHtml('data-test="wizard-alerts"');
    });

    it('starts on step 1 when there is no product yet', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['step' => 3])
            ->test(AddProductWizard::class)
            ->assertSet('step', 1);
    });

    it('clamps a step out of range', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 9])
            ->test(AddProductWizard::class)
            ->assertSet('step', 3);
    });

    it('answers 404 for a product that is not this account\'s to finish', function (string $id): void {
        $other = Product::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('app.products.create', ['product' => $id === 'foreign' ? (string) $other->id : $id, 'step' => 2]))
            ->assertNotFound();
    })->with([
        'another account\'s product' => ['foreign'],
        'a malformed id' => ['not-a-uuid'],
        'an unknown id' => ['01929a8e-0000-7000-8000-000000000000'],
    ]);

    it('refuses a product id set from the browser', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class)->set('productId', (string) $product->id);
    })->throws(CannotUpdateLockedPropertyException::class);

    it('does not leave step 1 without a product', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->call('goToStep', 2)
            ->assertSet('step', 1);
    });

    it('switches between a shop link and filling it in by hand', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->call('switchMode', 'manual')
            ->assertSet('mode', 'manual')
            ->assertSeeHtml('data-test="manual-product-form"')
            ->call('switchMode', 'url')
            ->assertSet('mode', 'url')
            ->assertSeeHtml('id="create-product-url"');
    });

    it('keeps the name and photo editable after the save, with the edit page\'s side effects', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Old name', 'tracking_idea' => 'coffee_tea']);
        Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1'])
            ->forceFill(['image_url' => 'https://ah.nl/img.jpg'])->save();
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 1])
            ->test(AddProductWizard::class)
            ->assertSeeHtml('data-test="product-details-form"')
            ->set('title', 'New name')
            ->call('useShopImage', 'https://ah.nl/img.jpg')
            ->assertSet('imageUrl', 'https://ah.nl/img.jpg')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $product->refresh();

        expect($product->title)->toBe('New name')
            ->and($product->image_url)->toBe('https://ah.nl/img.jpg')
            // Jev read the old name; the idea it picked from it goes.
            ->and($product->tracking_idea)->toBeNull();
    });

    it('refuses an empty name on the edit view', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Kept']);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 1])
            ->test(AddProductWizard::class)
            ->set('title', '')
            ->call('saveDetails')
            ->assertHasErrors('title');

        expect($product->refresh()->title)->toBe('Kept');
    });

    it('creates no second product once step 1 saved one', function (): void {
        Http::fake(fakeCreateFlowOffer());
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddProductWizard::class)
            ->set('url', 'https://shop.example.com/p/1')
            ->call('probe')
            ->call('confirm')
            ->assertSet('step', 2)
            ->call('confirm')
            ->set('manualTitle', 'Another')
            ->call('saveManual');

        expect(Product::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('says the product is tracked when the person is done', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Coffee beans']);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
            ->test(AddProductWizard::class)
            ->call('finish')
            ->assertNotified('Product created')
            ->assertRedirect(route('app.products.show', $product));
    });
});

/** Step 3, after step 1 from a link, so the product has a price and a reference. */
function wizardAtAlerts(string $price = '50.00', string $title = 'Demo Item'): Testable
{
    Http::fake(fakeCreateFlowOffer(price: $price, title: $title));

    return Livewire::test(AddProductWizard::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm')
        ->call('goToStep', 3);
}

describe('step 3, the alert', function (): void {
    it('explains that tracking goes on whatever the alert says', function (): void {
        $this->actingAs(User::factory()->create());

        wizardAtAlerts()->assertSee('We check every shop and keep the full price history, whatever you set here.');
    });

    it('suggests the default thresholds from the reference the drop check uses', function (): void {
        $this->actingAs(User::factory()->create());

        // 50.00 sits in the 25–100 tier: 10% or 7.00.
        wizardAtAlerts()
            ->assertSeeHtml('placeholder="10.00"')
            ->assertSeeHtml('placeholder="7.00"');
    });

    it('stores the alert a person sets', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        wizardAtAlerts()
            ->set('dropThresholdPct', '12.5')
            ->set('targetPrice', '40')
            ->call('saveAlerts')
            ->assertHasNoErrors()
            ->assertRedirect();

        $product = Product::query()->where('user_id', $user->id)->sole();

        expect((string) $product->drop_threshold_pct)->toBe('12.50')
            ->and($product->drop_threshold_abs)->toBeNull()
            ->and((string) $product->target_price)->toBe('40.00');
    });

    it('refuses a threshold of zero, as the edit page does', function (string $field): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        wizardAtAlerts()
            ->set($field, '0')
            ->call('saveAlerts')
            ->assertHasErrors($field)
            ->assertNoRedirect();

        expect(Product::query()->where('user_id', $user->id)->sole()->{$field === 'dropThresholdPct' ? 'drop_threshold_pct' : 'drop_threshold_abs'})->toBeNull();
    })->with(['dropThresholdPct', 'dropThresholdAbs']);

    it('offers a price per unit when a shop states a pack size, and stores it', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        wizardAtAlerts(price: '4.00', title: 'Crisps 500 g')
            ->assertSeeHtml('data-test="unit-target"')
            ->assertSee('same price per kilo')
            ->set('unitPriceTarget', '6')
            ->call('saveAlerts')
            ->assertHasNoErrors();

        expect((string) Product::query()->where('user_id', $user->id)->sole()->unit_price_target)->toBe('6.0000');
    });

    it('asks for a price per unit without the pack picker when no shop states a size', function (): void {
        $this->actingAs(User::factory()->create());

        wizardAtAlerts()
            ->assertDontSeeHtml('data-test="unit-target"')
            ->assertSee('Target price per kilo, litre or piece');
    });

    it('saves nothing when the person keeps the defaults', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        wizardAtAlerts()
            ->set('dropThresholdPct', '30')
            ->call('finish')
            ->assertRedirect();

        expect(Product::query()->where('user_id', $user->id)->sole()->drop_threshold_pct)->toBeNull();
    });

    it('keeps the alert a reopened product already has', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create([
            'drop_threshold_pct' => '20.00', 'drop_threshold_abs' => '1.50',
            'target_price' => '3.00', 'unit_price_target' => '0.5000',
        ]);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
            ->test(AddProductWizard::class)
            ->assertSet('unitPriceTarget', '0.5')
            ->call('saveAlerts')
            ->assertHasNoErrors();

        $product->refresh();

        expect((string) $product->drop_threshold_pct)->toBe('20.00')
            ->and((string) $product->drop_threshold_abs)->toBe('1.50')
            ->and((string) $product->target_price)->toBe('3.00')
            ->and((string) $product->unit_price_target)->toBe('0.5000');
    });

    it('says the default starts later for a product with no price yet', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 3])
            ->test(AddProductWizard::class)
            ->assertSee('The default alert starts once we have read a price.')
            ->call('saveAlerts')
            ->assertHasNoErrors()
            ->assertRedirect(route('app.products.show', $product));
    });
});

describe('guards', function (): void {
    it('refuses a step set from the browser', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)->set('step', 2);
    })->throws(CannotUpdateLockedPropertyException::class);

    it('redirects an old by-hand link whose idea is not a string', function (): void {
        $this->actingAs(User::factory()->create());

        $this->get('/app/products/create-manual?idea[]=coffee')
            ->assertRedirect(route('app.products.create', ['mode' => 'manual']));
    });

    it('takes only a photo a shop reported', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['image_url' => null]);
        Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1'])
            ->forceFill(['image_url' => 'https://ah.nl/pack.jpg'])->save();
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 1])
            ->test(AddProductWizard::class)
            ->call('useShopImage', 'https://elsewhere.test/other.jpg')
            ->assertSet('imageUrl', '')
            ->call('useShopImage', 'https://ah.nl/pack.jpg')
            ->assertSet('imageUrl', 'https://ah.nl/pack.jpg');
    });

    it('saves the name when the person leaves step 1 by the step list', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Old name']);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 1])
            ->test(AddProductWizard::class)
            ->set('title', 'New name')
            ->call('goToStep', 3)
            ->assertSet('step', 3);

        expect($product->refresh()->title)->toBe('New name');
    });

    it('stays on step 1 when the name it would save is empty', function (): void {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['title' => 'Kept']);
        $this->actingAs($user);

        Livewire::withQueryParams(['product' => (string) $product->id, 'step' => 1])
            ->test(AddProductWizard::class)
            ->set('title', '')
            ->call('goToStep', 2)
            ->assertHasErrors('title')
            ->assertSet('step', 1);
    });
});
