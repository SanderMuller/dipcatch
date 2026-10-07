<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use App\Services\ShopDiscovery\KlarnaPageSearch;
use App\Services\ShopDiscovery\ShoppersCountry;
use Illuminate\Support\Facades\Http;

it('takes the country the owner picked, then the one their timezone lies in, then the fallback', function (string $timezone, ?string $country, string $expected): void {
    $user = User::factory()->make(['timezone' => $timezone, 'country' => $country]);

    expect(ShoppersCountry::of($user))->toBe($expected);
})->with([
    'picked' => ['Europe/Amsterdam', 'fr', 'fr'],
    'from the timezone' => ['Europe/Paris', null, 'fr'],
    'Amsterdam' => ['Europe/Amsterdam', null, 'nl'],
    'no country for UTC' => ['UTC', null, 'nl'],
    'an unknown pick' => ['Europe/Berlin', 'xx', 'de'],
]);

it('searches in the country\'s language, and in English where it knows none', function (): void {
    config()->set('dipcatch.web_discovery.language', 'nl');

    expect(ShoppersCountry::language('nl'))->toBe('nl')
        ->and(ShoppersCountry::language('fr'))->toBe('fr')
        ->and(ShoppersCountry::language('jp'))->toBe('en');
});

it('spends no search on a Klarna page in a country where DipCatch does not search Klarna', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $user = User::factory()->create(['country' => 'fr']);
    $product = Product::factory()->for($user)->create();

    expect(app(KlarnaPageSearch::class)->for($product))->toBe(KlarnaPageSearch::NONE);
    Http::assertNothingSent();
});

it('names the currency a country\'s shoppers pay in, where a product can be priced in it', function (): void {
    expect(ShoppersCountry::currency('nl'))->toBe('EUR')
        ->and(ShoppersCountry::currency('se'))->toBe('SEK')
        ->and(ShoppersCountry::currency('gb'))->toBe('GBP')
        ->and(ShoppersCountry::currency('bg'))->toBe('EUR')
        ->and(ShoppersCountry::currency('ke'))->toBeNull();
});
