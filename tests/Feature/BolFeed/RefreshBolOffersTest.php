<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

it('keeps a row bol has no offer for today, and one it was rate limited on, and still refreshes the rest', function (): void {
    Sleep::fake();
    storedBolRow('5054563110503', '9.99');
    storedBolRow('8710448620013', '1.99');
    storedBolRow('8712100325328', '3.49');
    Http::fake([
        'login.bol.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 299]),
        // Out of stock: the product exists, with no offer.
        'api.bol.com/marketing/catalog/v1/products/5054563110503*' => Http::response(['ean' => '5054563110503', 'title' => 'Sensodyne', 'url' => 'https://www.bol.com/nl/nl/p/sensodyne/id-5054563110503/']),
        'api.bol.com/marketing/catalog/v1/products/8710448620013*' => Http::response(['title' => 'Too Many Requests'], 429),
        'api.bol.com/marketing/catalog/v1/products/8712100325328*' => Http::response(['ean' => '8712100325328', 'title' => 'Calvé', 'url' => 'https://www.bol.com/nl/nl/p/calve/id-8712100325328/', 'offer' => ['price' => 3.29]]),
    ]);

    $this->artisan('dipcatch:refresh-bol-offers')->assertSuccessful();

    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->orderBy('ean')->pluck('price', 'ean')->all())
        ->toBe(['5054563110503' => '9.99', '8710448620013' => '1.99', '8712100325328' => '3.29']);
});

it('never asks for, nor removes, a row whose barcode is longer than EAN-13', function (): void {
    storedBolRow('12345678901231', '4.99');
    Http::fake();

    $this->artisan('dipcatch:refresh-bol-offers')->assertSuccessful();

    Http::assertNothingSent();
    expect(CheckjebonPrice::query()->where('supermarket', 'bol')->count())->toBe(1);
});
