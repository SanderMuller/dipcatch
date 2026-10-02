<?php declare(strict_types=1);

use App\Enums\ApiService;
use App\Enums\PromotionDepthBand;
use App\Filament\Admin\Widgets\ApiUsagePurposesWidget;
use App\Filament\Admin\Widgets\ApiUsageWidget;
use App\Models\ApiUsageDay;
use App\Models\User;
use App\Services\BolApi\BolCatalogClient;
use App\Services\ShopDiscovery\SerperProvider;
use App\Services\ShopDiscovery\WebSearches;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('services.serper.key', 'test-key');
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
});

function usageToday(ApiService $service, string $purpose): ?ApiUsageDay
{
    return ApiUsageDay::query()->where('day', now()->toDateString())->where('service', $service)->where('purpose', $purpose)->first();
}

it('counts a Jev call with its tokens, per purpose and day', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([
        'answers' => [TypeSafeClient::PROMOTION_DEPTH_QUESTION => ['probabilities' => [PromotionDepthBand::Deep->value => 0.9]]],
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 30],
    ])]);

    app(TypeSafeClient::class)->promotionDepth(['title' => 'Kattenvoer']);
    app(TypeSafeClient::class)->promotionDepth(['title' => 'Kattenvoer']);

    $usage = usageToday(ApiService::TypeSafe, ShopCheckPurpose::AlertSuggestion->value);

    expect($usage?->calls)->toBe(2)
        ->and($usage?->failures)->toBe(0)
        ->and($usage?->input_tokens)->toBe(2400)
        ->and($usage?->output_tokens)->toBe(60);
});

it('counts a failed Jev call as a call and a failure', function (): void {
    Http::fake([TypeSafeClient::ENDPOINT => Http::response([], 500)]);

    expect(fn () => app(TypeSafeClient::class)->promotionDepth(['title' => 'Kattenvoer']))->toThrow(TypeSafeRequestFailed::class);

    expect(usageToday(ApiService::TypeSafe, ShopCheckPurpose::AlertSuggestion->value))
        ->calls->toBe(1)
        ->failures->toBe(1);
});

it('counts a Jev check a daily cap refused, without a call', function (): void {
    config()->set('dipcatch.shop_checks.daily_limit_per_user', 1);
    $user = User::factory()->create();

    expect(app(CategorisationBudget::class)->allowsShopCheck($user, ShopCheckPurpose::AddShop))->toBeTrue()
        ->and(app(CategorisationBudget::class)->allowsShopCheck($user, ShopCheckPurpose::AddShop))->toBeFalse();

    expect(usageToday(ApiService::TypeSafe, ShopCheckPurpose::AddShop->value))
        ->calls->toBe(0)
        ->refusals->toBe(1);
});

it('counts a Serper search, and a search the daily cap refused', function (): void {
    config()->set('dipcatch.web_discovery.daily_search_limit', 1);
    Http::fake([SerperProvider::ENDPOINT => Http::response(['organic' => [['title' => 'Kattenvoer', 'link' => 'https://shop.test/p/1']]])]);

    app(WebSearches::class)->forQuery('kattenvoer 2 kg');
    app(WebSearches::class)->forQuery('hondenvoer 4 kg');

    expect(usageToday(ApiService::Serper, SerperProvider::PURPOSE))
        ->calls->toBe(1)
        ->refusals->toBe(1);
});

it('counts a bol.com lookup by kind', function (): void {
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        BolCatalogClient::BASE_URL . '/products/search*' => Http::response(['results' => []]),
    ]);

    app(BolCatalogClient::class)->search('kattenvoer');

    expect(usageToday(ApiService::Bol, 'search'))->calls->toBe(1);
});

it('shows today and the last 7 days on the admin dashboard', function (): void {
    ApiUsageDay::query()->create(['day' => now()->toDateString(), 'service' => ApiService::TypeSafe, 'purpose' => 'categorise', 'calls' => 12, 'failures' => 2, 'refusals' => 3, 'input_tokens' => 9000, 'output_tokens' => 1000]);
    ApiUsageDay::query()->create(['day' => now()->subDays(3)->toDateString(), 'service' => ApiService::TypeSafe, 'purpose' => 'categorise', 'calls' => 5]);
    ApiUsageDay::query()->create(['day' => now()->subDays(10)->toDateString(), 'service' => ApiService::TypeSafe, 'purpose' => 'categorise', 'calls' => 100]);

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel('admin');

    livewire(ApiUsageWidget::class)
        ->assertSee('Jev (TypeSafe) today')
        ->assertSee('2 failed')
        ->assertSee('3 refused by a cap')
        ->assertSee('10K tokens')
        ->assertSee('last 7 days: 17')
        ->assertSee('Serper today');

    livewire(ApiUsagePurposesWidget::class)
        ->assertSee('categorise')
        ->assertSee('Jev (TypeSafe)');
});

it('drops a count it cannot write without breaking the caller, inside a transaction too', function (): void {
    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        // Longer than the purpose column holds: the write fails.
        ApiUsageDay::call(ApiService::TypeSafe, str_repeat('x', 60));

        $user->forceFill(['name' => 'Still saved'])->save();
    });

    expect($user->refresh()->name)->toBe('Still saved')
        ->and(ApiUsageDay::query()->count())->toBe(0);
});

it('counts a categorisation a daily cap refused under categorise', function (): void {
    config()->set('dipcatch.categories.daily_limit_per_user', 1);
    $user = User::factory()->create();

    app(CategorisationBudget::class)->allows($user);
    app(CategorisationBudget::class)->allows($user);

    expect(usageToday(ApiService::TypeSafe, CategorisationBudget::CATEGORISE))->refusals->toBe(1);
});

it('counts an unreachable Serper as a failed call', function (): void {
    Http::fake([SerperProvider::ENDPOINT => fn () => throw new ConnectionException('timed out')]);

    expect(app(WebSearches::class)->forQuery('kattenvoer 2 kg'))->toBeNull();

    expect(usageToday(ApiService::Serper, SerperProvider::PURPOSE))
        ->calls->toBe(1)
        ->failures->toBe(1);
});

it('counts a bol.com re-login after a revoked token, and a missing product as no failure', function (): void {
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        BolCatalogClient::BASE_URL . '/products/search*' => Http::sequence()->push([], 401)->push(['results' => []]),
        BolCatalogClient::BASE_URL . '/products/*' => Http::response([], 404),
    ]);

    app(BolCatalogClient::class)->search('kattenvoer');
    app(BolCatalogClient::class)->findByEan('8710400000001');

    expect(usageToday(ApiService::Bol, 'search'))->calls->toBe(2)->failures->toBe(1)
        ->and(usageToday(ApiService::Bol, 'by-ean'))->calls->toBe(1)->failures->toBe(0)
        ->and(usageToday(ApiService::Bol, 'login'))->calls->toBe(2)->failures->toBe(0);
});
