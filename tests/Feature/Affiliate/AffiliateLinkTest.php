<?php declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Livewire\Products\ProductList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\AffiliateLink;
use Database\Seeders\AdminUserSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.bol.affiliate.site_id', '123456');
});

function bolProductPage(): string
{
    return 'https://www.bol.com/nl/nl/p/sensodyne-tandpasta-rapid-relief-75-ml/9200000077118993/';
}

it('wraps a bol.com page in the partner click link, with the site id and the page', function (): void {
    $link = AffiliateLink::for(bolProductPage(), User::factory()->make());

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($link)->toStartWith('https://partner.bol.com/click/click?')
        ->and($query)->toMatchArray(['s' => '123456', 't' => 'url', 'url' => bolProductPage()])
        ->and(AffiliateLink::rel(bolProductPage(), User::factory()->make()))->toBe('sponsored noopener noreferrer');
});

it('leaves a link plain for another shop, without a site id, and for an excluded account', function (string $url, string $siteId, bool $excluded): void {
    config()->set('services.bol.affiliate.site_id', $siteId);
    $viewer = User::factory()->make(['affiliate_links_excluded' => $excluded]);

    expect(AffiliateLink::for($url, $viewer))->toBe($url)
        ->and(AffiliateLink::rel($url, $viewer))->toBe('noopener noreferrer');
})->with([
    'another shop' => ['https://www.ah.nl/producten/product/wi1/x', '123456', false],
    'no site id' => [bolProductPage(), '', false],
    'excluded account' => [bolProductPage(), '123456', true],
]);

it('links a bol.com shop on a product card through the partner link, and says so in the footer', function (bool $excluded, bool $affiliate): void {
    $user = User::factory()->create(['affiliate_links_excluded' => $excluded]);
    $product = Product::factory()->for($user)->create(['title' => 'Sensodyne']);
    Shop::factory()->for($product)->create(['url' => bolProductPage(), 'host' => 'bol.com', 'current_price' => '8.81']);
    $product->refresh()->recomputeCheapestShop();
    $this->actingAs($user);

    livewire(ProductList::class)
        ->assertSeeHtml($affiliate ? 'href="https://partner.bol.com/click/click?' : 'href="' . bolProductPage() . '"');

    $page = $this->get(route('app.products.index'))->assertOk();

    $affiliate
        ? $page->assertSee('Links to bol.com are affiliate links')
        : $page->assertDontSee('Links to bol.com are affiliate links');
})->with([
    'an account' => [false, true],
    'an excluded account' => [true, false],
]);

it('keeps the bol.com link in an e-mail plain', function (): void {
    $shop = Shop::factory()->make(['url' => bolProductPage(), 'host' => 'bol.com']);

    $this->blade('<x-digest-shop-link :shop="$shop" />', ['shop' => $shop])
        ->assertSee('href="' . bolProductPage() . '"', false)
        ->assertDontSee('partner.bol.com', false);
});

it('lets an admin exclude an account from affiliate links, and include it again', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();

    livewire(ListUsers::class)->callAction(TestAction::make('affiliateLinks')->table($target));
    expect($target->refresh()->affiliate_links_excluded)->toBeTrue();

    livewire(ListUsers::class)->callAction(TestAction::make('affiliateLinks')->table($target));
    expect($target->refresh()->affiliate_links_excluded)->toBeFalse();
});

it('seeds the local admin account excluded from affiliate links', function (): void {
    config()->set('dipcatch.admin.email', 'root@dipcatch.test');
    config()->set('dipcatch.admin.password', 'super-secret');

    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', 'root@dipcatch.test')->sole()->affiliate_links_excluded)->toBeTrue();
});

it('puts the partner tag on an amazon.nl page itself, in place of any tag it had', function (): void {
    config()->set('services.amazon.associate_tag', 'dipcatch-21');

    $link = AffiliateLink::for('https://www.amazon.nl/dp/B0CX1234?th=1&tag=someone-21#reviews', User::factory()->make());

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($link)->toStartWith('https://www.amazon.nl/dp/B0CX1234?')
        ->and($link)->toEndWith('#reviews')
        ->and($query)->toBe(['th' => '1', 'tag' => 'dipcatch-21'])
        ->and(AffiliateLink::for('https://www.amazon.nl/dp/B0CX1234', User::factory()->make(['affiliate_links_excluded' => true])))->toBe('https://www.amazon.nl/dp/B0CX1234');
});

it('names each shop with affiliate links in the footer, with the statement each program asks for', function (): void {
    config()->set('services.amazon.associate_tag', 'dipcatch-21');
    $this->actingAs(User::factory()->create());

    $this->get(route('app.products.index'))
        ->assertOk()
        ->assertSee('Links to bol.com and Amazon are affiliate links')
        ->assertSee('DipCatch is not a bol.com site.')
        ->assertSee('As an Amazon Associate, DipCatch earns from qualifying purchases.');
});
