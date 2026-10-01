<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.bol.api.client_id', 'client-id');
    config()->set('services.bol.api.client_secret', 'client-secret');
    Http::preventStrayRequests();
});

function storedBolRow(string $ean, string $price): CheckjebonPrice
{
    return CheckjebonPrice::query()->create([
        'supermarket' => 'bol',
        'external_id' => "id-{$ean}",
        'name' => 'Old name',
        'price' => $price,
        'link' => "old/id-{$ean}/",
        'ean' => $ean,
        'refreshed_at' => now()->subDays(3),
    ]);
}

it('refreshes a stored bol.com price, and removes a product bol no longer sells', function (): void {
    storedBolRow('5054563110503', '9.99');
    storedBolRow('8710448620013', '1.99');
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        'api.bol.com/marketing/catalog/v1/products/5054563110503*' => Http::response([
            'ean' => '5054563110503',
            'title' => 'Sensodyne Tandpasta Rapid Relief 75ml',
            'url' => 'https://www.bol.com/nl/nl/p/sensodyne-tandpasta-rapid-relief-75-ml/id-5054563110503/',
            'offer' => ['price' => 8.81],
        ]),
        'api.bol.com/marketing/catalog/v1/products/8710448620013*' => Http::response(['title' => 'Not Found'], 404),
    ]);

    $this->artisan('dipcatch:refresh-bol-offers')->assertSuccessful();

    $row = CheckjebonPrice::query()->where('supermarket', 'bol')->sole();
    expect($row->only(['ean', 'price', 'name']))->toBe(['ean' => '5054563110503', 'price' => '8.81', 'name' => 'Sensodyne Tandpasta Rapid Relief 75ml'])
        ->and($row->refreshed_at->isToday())->toBeTrue();
});

it('stops without touching the rest when bol is down, so no row is lost to an outage', function (): void {
    storedBolRow('5054563110503', '9.99');
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        'api.bol.com/*' => Http::response('', 503),
    ]);

    $this->artisan('dipcatch:refresh-bol-offers')->assertFailed();

    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->value('price'))->toBe('9.99');
});

it('does nothing without API credentials', function (): void {
    config()->set('services.bol.api.client_id', '');

    $this->artisan('dipcatch:refresh-bol-offers')->assertSuccessful()->expectsOutputToContain('No bol.com API client id');
});
