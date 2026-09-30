<?php declare(strict_types=1);

use App\Actions\Shops\ProbeOutcome;
use App\Enums\ProbeFailure;
use App\Filament\Admin\Resources\FailedShopPages\Pages\ListFailedShopPages;
use App\Livewire\Products\CreateProductFromUrl;
use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\CreateProductTool;
use App\Models\FailedShopPage;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
    Http::preventStrayRequests();
});

function blockedShopPage(): void
{
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Forbidden', 403),
    ]);
}

function probeFromUrl(User $user, string $url = 'https://shop.example.com/p/1'): void
{
    test()->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)->set('url', $url)->call('probe');
}

it('keeps a page it could not read, with why, and counts each try', function (): void {
    blockedShopPage();
    $user = User::factory()->create();

    probeFromUrl($user);
    probeFromUrl($user, 'https://SHOP.example.com/p/1');

    expect(FailedShopPage::query()->sole()->only(['url', 'host', 'failure', 'user_id', 'times']))->toBe([
        'url' => 'https://shop.example.com/p/1',
        'host' => 'shop.example.com',
        'failure' => ProbeFailure::Blocked,
        'user_id' => $user->id,
        'times' => 2,
    ]);
});

it('keeps a page no reader understands, with the reader\'s reason', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('<html><body><h1>Nothing to read here</h1></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    probeFromUrl(User::factory()->create());

    $page = FailedShopPage::query()->sole();

    expect($page->failure)->toBe(ProbeFailure::ExtractionFailed)
        ->and($page->reason)->not->toBeNull();
});

it('drops the page once someone reads it without help', function (): void {
    blockedShopPage();
    probeFromUrl(User::factory()->create());
    expect(FailedShopPage::query()->count())->toBe(1);

    Http::swap(new Factory());
    Http::fake(fakeJsonLdOffer());
    probeFromUrl(User::factory()->create());

    expect(FailedShopPage::query()->count())->toBe(0);
});

it('keeps nothing for a typo, our own pacing, a page already tracked, or several variants to choose from', function (): void {
    $user = User::factory()->create();
    probeFromUrl($user, 'not a web address');

    $url = 'https://shop.example.com/p/1';
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['url' => $url]);

    FailedShopPage::recordOutcome($url, ProbeOutcome::failed(ProbeFailure::ProbeRateLimited), $user);
    FailedShopPage::recordOutcome($url, ProbeOutcome::failed(ProbeFailure::HostRateLimited), $user);
    FailedShopPage::recordOutcome($url, ProbeOutcome::duplicate($shop), $user);
    FailedShopPage::recordOutcome($url, ProbeOutcome::ambiguous([], $url, 'shop.example.com'), $user);

    expect(FailedShopPage::query()->count())->toBe(0);
});

it('leaves the page alone when the person tried their own selectors', function (): void {
    blockedShopPage();
    probeFromUrl(User::factory()->create());
    $before = FailedShopPage::query()->sole()->only(['failure', 'reason', 'times']);

    $url = 'https://shop.example.com/p/1';
    FailedShopPage::recordOutcome($url, ProbeOutcome::extractionFailed('user_selector_no_match'), user: null, selectors: ['price' => '.price']);
    FailedShopPage::recordOutcome($url, ProbeOutcome::failed(ProbeFailure::Blocked), user: null, selectors: ['price' => '.price']);

    expect(FailedShopPage::query()->sole()->only(['failure', 'reason', 'times']))->toBe($before);
});

it('keeps a page an assistant could not create a product from', function (): void {
    blockedShopPage();

    DipCatchServer::actingAs(User::factory()->create())->tool(CreateProductTool::class, ['url' => 'https://shop.example.com/p/1']);

    expect(FailedShopPage::query()->sole()->failure)->toBe(ProbeFailure::Blocked);
});

it('keeps a page an assistant could not add either', function (): void {
    blockedShopPage();
    $me = User::factory()->create();
    $product = Product::factory()->for($me)->create();

    DipCatchServer::actingAs($me)->tool(AddShopTool::class, ['product_id' => (string) $product->id, 'url' => 'https://shop.example.com/p/1']);

    expect(FailedShopPage::query()->sole()->failure)->toBe(ProbeFailure::Blocked);
});

it('forgets a page six months after the last try', function (): void {
    FailedShopPage::query()->forceCreate(['url' => 'https://old.test/p', 'url_hash' => hash('sha256', 'old'), 'host' => 'old.test', 'failure' => 'blocked', 'times' => 1, 'first_failed_at' => now()->subYear(), 'last_failed_at' => now()->subMonths(7)]);
    FailedShopPage::query()->forceCreate(['url' => 'https://new.test/p', 'url_hash' => hash('sha256', 'new'), 'host' => 'new.test', 'failure' => 'blocked', 'times' => 1, 'first_failed_at' => now(), 'last_failed_at' => now()]);

    $this->artisan('model:prune', ['--model' => [FailedShopPage::class]]);

    expect(FailedShopPage::query()->pluck('host')->all())->toBe(['new.test']);
});

it('lists the pages for an admin, and keeps them from anyone else', function (): void {
    blockedShopPage();
    probeFromUrl(User::factory()->create());

    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListFailedShopPages::class)
        ->assertCanSeeTableRecords(FailedShopPage::query()->get())
        ->assertSee('shop.example.com');

    $this->actingAs(User::factory()->create());
    $this->get('/admin/failed-shop-pages')->assertForbidden();
});
