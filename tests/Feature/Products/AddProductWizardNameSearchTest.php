<?php declare(strict_types=1);

use App\Billing\Entitlements;
use App\Livewire\Products\AddProductWizard;
use App\Models\CheckjebonChain;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebSearch;
use App\Services\BolApi\BolCatalogClient;
use App\Services\ProductSearch\NameSearchVerdict;
use App\Services\ProductSearch\WebNameSearchStatus;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\TypeSafe\NameSearchQuestion;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config()->set('services.serper.key', 'test-key');
    config()->set('dipcatch.web_discovery.enabled', true);
    seedChains();
});

/** A readable product page at the URL, and the routes the probe asks for around it. */
function nameSearchShopPage(string $url = 'https://shop.example.com/p/1', int $status = 200): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Demo Pindakaas 650 g',
        'offers' => ['@type' => 'Offer', 'price' => '3.49', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        $url => Http::response(withJsonLd($json), $status, ['Content-Type' => 'text/html']),
    ];
}

function storedNameSearch(string $text, string $link = 'https://shop.example.com/p/1'): WebSearch
{
    return WebSearch::query()->create([
        'query_hash' => WebSearch::hashOf($text, 'nl'),
        'query' => WebSearch::normalise($text),
        'results' => [['title' => 'Demo Pindakaas 650 g', 'link' => $link, 'snippet' => 'Op voorraad', 'position' => 1]],
        'searched_at' => now(),
    ]);
}

function nameSearchOutboundCalls(): int
{
    return count(Http::recorded(static fn (Request $request): bool => $request->url() === SerperProvider::ENDPOINT
        || $request->url() === TypeSafeClient::ENDPOINT
        || str_contains($request->url(), 'bol.com')));
}

describe('a link', function (): void {
    test('is probed, whatever its case, spacing or trailing words', function (string $typed): void {
        Http::fake(nameSearchShopPage());
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', $typed)
            ->call('lookUp')
            ->assertSet('state', 'preview')
            ->assertSet('title', 'Demo Pindakaas 650 g')
            ->assertSet('nameResults', null);

        expect(nameSearchOutboundCalls())->toBe(0);
    })->with([
        'uppercase scheme' => 'HTTPS://shop.example.com/p/1',
        'whitespace around it' => "  https://shop.example.com/p/1 \n",
        'words after it' => 'https://shop.example.com/p/1 pindakaas van de aanbieding',
        'no scheme' => 'shop.example.com/p/1',
    ]);

    test('is probed when longer than a name may be', function (): void {
        $url = 'https://shop.example.com/p/' . str_repeat('a', 250);
        Http::fake(nameSearchShopPage($url));
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', $url)
            ->call('lookUp')
            ->assertHasNoErrors()
            ->assertSet('state', 'preview');
    });

    test('that is malformed gets the probe error, not a name search', function (): void {
        Http::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'https://')
            ->call('lookUp')
            ->assertSet('state', 'error')
            ->assertSet('nameResults', null);

        Http::assertNothingSent();
    });

    test('clears the results of an earlier name search', function (): void {
        Http::fake(nameSearchShopPage());
        seedRow('ah', 'Demo pindakaas', '650 g');
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas')
            ->call('lookUp')
            ->assertSet('nameResults.items.0.title', 'Demo pindakaas')
            ->set('query', 'https://shop.example.com/p/1')
            ->call('lookUp')
            ->assertSet('nameResults', null)
            ->assertSet('searchedQuery', '');
    });
});

describe('a name', function (): void {
    test('that is too short or too long gets a field error and searches nothing', function (string $typed): void {
        Http::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', $typed)
            ->call('lookUp')
            ->assertHasErrors('query')
            ->assertSet('nameResults', null)
            ->assertSet('state', 'idle');

        Http::assertNothingSent();
    })->with([
        'two characters' => 'ab',
        'two characters with spaces' => '  ab  ',
        'only search operators' => 'site:ah.nl',
        'over 200 characters' => trim(str_repeat('pindakaas ', 21)),
    ]);

    test('searches the catalogue and bol.com for the text without its search operators', function (): void {
        config()->set('services.bol.api.client_id', 'client-id');
        config()->set('services.bol.api.client_secret', 'client-secret');
        CheckjebonChain::query()->create(['chain' => 'bol', 'label' => 'bol.com', 'base_url' => 'https://www.bol.com/nl/nl/p/', 'refreshed_at' => now()]);
        fakeBolApi([BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => [
            bolProductJson('8710400000017', 'Demo Melk halfvol 1 l', '9300000000001', 1.29),
        ]])]);
        seedRow('ah', 'AH Halfvolle melk', '1 l', '1.19');
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'melk site:ah.nl')
            ->call('lookUp')
            ->assertHasNoErrors()
            ->assertSet('searchedQuery', 'melk site:ah.nl')
            ->assertSet('nameResults.items.0.title', 'AH Halfvolle melk')
            ->assertSet('nameResults.items.1.title', 'Demo Melk halfvol 1 l')
            ->assertSet('status', '2 products found.')
            ->assertSee('Results for “melk site:ah.nl”')
            ->assertSee('€1.19')
            ->assertSeeHtml('wire:click="pickResult(1)"');

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/products/search') && $request['search-term'] === 'melk');
    });

    test('with nothing found offers the web search and the form filled with the name', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'Demo Pindakaas 650 g')
            ->call('lookUp')
            ->assertSet('nameResults.items', [])
            ->assertSet('status', 'Nothing found for this name.')
            ->assertSeeHtml('data-test="name-results-empty"')
            ->assertSeeHtml('data-test="search-the-web"')
            ->assertDontSeeHtml('data-test="assistant-hint"')
            ->call('switchMode', 'manual')
            ->assertSet('mode', 'manual')
            ->assertSet('manualTitle', 'Demo Pindakaas 650 g');
    });

    test('keeps the hand form empty when nothing was searched', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'typed but not searched')
            ->call('switchMode', 'manual')
            ->assertSet('manualTitle', '');
    });

    test('over the per-minute limit says so and keeps the earlier results', function (): void {
        Http::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        seedRow('ah', 'Demo pindakaas', '650 g');

        $wizard = Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas')
            ->call('lookUp');

        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit("name-search:local:{$user->id}", 60);
        }

        $wizard->set('query', 'demo hagelslag')
            ->call('lookUp')
            ->assertHasErrors('query')
            ->assertSee('Too many searches. Wait a minute and try again.')
            ->assertSet('searchedQuery', 'demo pindakaas');
    });

    test('keeps its results locked', function (): void {
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('nameResults', ['items' => [], 'bolFailed' => false, 'fullMatch' => false, 'throttled' => false]);
    })->throws(CannotUpdateLockedPropertyException::class);
});

describe('the web search', function (): void {
    test('stays off for text edited since the search, without searching', function (): void {
        Http::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->set('query', 'demo hagelslag 400 g')
            ->call('searchTheWeb')
            ->assertSet('webStatus', null)
            ->assertSee('Look up the new text first, then search the web for it.');

        Http::assertNothingSent();
    });

    test('shows the gate hint and stays off for a vague name', function (): void {
        Http::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'melk')
            ->call('lookUp')
            ->call('searchTheWeb')
            ->assertSet('webVerdict', NameSearchVerdict::TooVague)
            ->assertSee('Add the brand or the size, and search again.')
            ->assertSeeHtml('wire:click="searchTheWeb" disabled="disabled"');

        Http::assertNothingSent();
    });

    test('asks Jev once for a text, also when the search is clicked again', function (): void {
        config()->set('services.typesafe.key', 'test-key');
        $user = User::factory()->create(['shop_checks' => true]);
        subscribeUser($user);
        Http::fake([
            SerperProvider::ENDPOINT => Http::response([], 500),
            TypeSafeClient::ENDPOINT => Http::response(['answers' => [TypeSafeClient::NAME_SEARCH_QUESTION => ['probabilities' => [
                NameSearchQuestion::TOO_VAGUE => 0.1,
                NameSearchQuestion::SPECIFIC_PRODUCT => 0.9,
            ]]]]),
        ]);
        $this->actingAs($user->refresh());

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->call('searchTheWeb')
            ->assertSet('webStatus', WebNameSearchStatus::Failed)
            ->assertSet('webVerdict', NameSearchVerdict::Specific)
            ->assertSee('The web search failed. Try again, or paste a link.')
            ->call('searchTheWeb')
            ->assertSet('webStatus', WebNameSearchStatus::Failed);

        expect(count(Http::recorded(static fn (Request $request): bool => $request->url() === TypeSafeClient::ENDPOINT)))->toBe(1)
            ->and(count(Http::recorded(static fn (Request $request): bool => $request->url() === SerperProvider::ENDPOINT)))->toBe(2);
    });

    test('is off with the budget text once today’s searches are used', function (): void {
        Http::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($i = 0; $i < Entitlements::for($user)->webSearchesPerDay(); $i++) {
            RateLimiter::hit("name-search:web:{$user->id}:" . now($user->timezone)->toDateString(), 86_400);
        }

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->assertSet('webCapSpent', true)
            ->assertSee('You have used today’s web searches. Paste a link, or try again tomorrow.')
            ->assertSeeHtml('wire:click="searchTheWeb" disabled="disabled"');
    });

    test('stays on at the budget for a text searched before, and lists it free', function (): void {
        Http::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        storedNameSearch('demo pindakaas 650 g');

        for ($i = 0; $i < Entitlements::for($user)->webSearchesPerDay(); $i++) {
            RateLimiter::hit("name-search:web:{$user->id}:" . now($user->timezone)->toDateString(), 86_400);
        }

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->assertSet('webStored', true)
            ->assertDontSeeHtml('wire:click="searchTheWeb" disabled="disabled"')
            ->call('searchTheWeb')
            ->assertSet('webStatus', WebNameSearchStatus::Found)
            ->assertSet('webResults.0.url', 'https://shop.example.com/p/1')
            ->assertSee('From the web')
            ->assertDontSeeHtml('data-test="search-the-web"');

        Http::assertNothingSent();
    });
});

describe('a pick', function (): void {
    test('probes the listed link and keeps the typed name', function (): void {
        Http::fake(nameSearchShopPage());
        $user = User::factory()->create();
        $this->actingAs($user);
        storedNameSearch('demo pindakaas 650 g');
        $tracked = Product::factory()->for($user)->create(['title' => 'Pindakaas']);
        Shop::factory()->for($tracked)->create(['url' => 'https://shop.example.com/p/1', 'url_hash' => UrlNormalizer::hash('https://shop.example.com/p/1')]);

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->call('searchTheWeb')
            ->call('pickResult', 0)
            ->assertSet('url', 'https://shop.example.com/p/1')
            ->assertSet('state', 'preview')
            ->assertSet('query', 'demo pindakaas 650 g')
            ->assertSet('existingTrackedProduct.title', 'Pindakaas');
    });

    test('that the page refuses keeps the results listed', function (): void {
        Http::fake(nameSearchShopPage(status: 500));
        $this->actingAs(User::factory()->create());
        storedNameSearch('demo pindakaas 650 g');

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas 650 g')
            ->call('lookUp')
            ->call('searchTheWeb')
            ->call('pickResult', 0)
            ->assertSet('state', 'error')
            ->assertSee('We could not open that page')
            ->assertSeeHtml('data-test="name-result"');
    });

    test('with an index outside the list does nothing', function (): void {
        Http::fake();
        $this->actingAs(User::factory()->create());
        seedRow('ah', 'Demo pindakaas', '650 g');

        Livewire::test(AddProductWizard::class)
            ->set('query', 'demo pindakaas')
            ->call('lookUp')
            ->call('pickResult', 5)
            ->call('pickResult', -1)
            ->assertSet('url', '')
            ->assertSet('state', 'idle');

        Http::assertNothingSent();
    });
});

test('a new search replaces the results, the web results and the gate verdict', function (): void {
    Http::fake();
    $this->actingAs(User::factory()->create());
    storedNameSearch('demo pindakaas 650 g');

    Livewire::test(AddProductWizard::class)
        ->set('query', 'melk')
        ->call('lookUp')
        ->call('searchTheWeb')
        ->assertSet('webVerdict', NameSearchVerdict::TooVague)
        ->set('query', 'demo pindakaas 650 g')
        ->call('lookUp')
        ->assertSet('webVerdict', null)
        ->call('searchTheWeb')
        ->assertSet('webStatus', WebNameSearchStatus::Found)
        ->set('query', 'demo hagelslag 400 g')
        ->call('lookUp')
        ->assertSet('searchedQuery', 'demo hagelslag 400 g')
        ->assertSet('webStatus', null)
        ->assertSet('webResults', [])
        ->assertDontSee('Add the brand or the size, and search again.');
});
