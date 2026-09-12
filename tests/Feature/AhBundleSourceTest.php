<?php declare(strict_types=1);

use App\Services\AhApi\AhApiSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-09-12 12:00:00 Europe/Amsterdam');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('captured AH bundle contracts produce tracked effective prices', function (string $fixture, string $url, string $single, string $tracked): void {
    fakeAhBundleApi($fixture);

    $result = app(AhApiSource::class)->resolve($url);

    expect($result->snapshot?->price)->toBe($single)
        ->and($result->snapshot?->trackedPrice())->toBe($tracked)
        ->and($result->snapshot?->bundleOfferAuthoritative)->toBeTrue()
        ->and($result->snapshot?->promotionWindowAuthoritative)->toBeTrue();
})->with([
    ['ah-fixed-total.json', 'https://www.ah.nl/producten/product/wi597752', '6.49', '6.00'],
    ['ah-later-item.json', 'https://www.ah.nl/producten/product/wi62661', '1.39', '1.05'],
]);

test('explicit no-offer card authoritatively clears bundle state', function (): void {
    fakeAhBundleApi('ah-no-offer.json');

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi54074')->snapshot;

    expect($snapshot?->price)->toBe('0.79')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue()
        ->and($snapshot?->promotionWindow)->toBeNull();
});

test('tiered AH wording stays disabled without captured source contract', function (): void {
    fakeAhPayload([
        'productCard' => [
            'title' => 'Future tier sample',
            'priceBeforeBonus' => 10,
            'isBonus' => true,
            'isVirtualBundle' => false,
            'bonusMechanism' => '2 stuks 20%, 3 stuks 30%, 4 stuks 40% korting',
            'discountLabels' => [['code' => 'UNKNOWN_TIER', 'count' => 4]],
        ],
    ]);

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi1')->snapshot;

    expect($snapshot?->price)->toBe('10')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue();
});

test('direct Bonus prices stay scalar', function (): void {
    fakeAhPayload([
        'productCard' => [
            'title' => 'Direct price sample',
            'currentPrice' => 2.5,
            'priceBeforeBonus' => 3,
            'isBonus' => true,
            'isVirtualBundle' => false,
            'bonusMechanism' => 'VOOR 2.50',
            'discountLabels' => [],
        ],
    ]);

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi2')->snapshot;

    expect($snapshot?->price)->toBe('2.5')
        ->and($snapshot?->bundleOffer)->toBeNull();
});

test('conditional campaigns cannot drive tracked bundle prices', function (array $campaign): void {
    fakeAhPayload([
        'productCard' => array_merge([
            'title' => 'Conditional sample',
            'priceBeforeBonus' => 3,
            'isBonus' => true,
            'isVirtualBundle' => false,
            'bonusMechanism' => '2 VOOR 4.00',
            'discountType' => 'AH',
            'promotionType' => 'NATIONAL',
            'segmentType' => 'AH',
            'discountLabels' => [['code' => 'DISCOUNT_X_FOR_Y', 'count' => 2]],
        ], $campaign),
    ]);

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi3')->snapshot;

    expect($snapshot?->price)->toBe('3')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeTrue();
})->with([
    'personal promotion' => [['promotionType' => 'PERSONAL']],
    'coupon segment' => [['segmentType' => 'COUPON']],
    'premium discount' => [['discountType' => 'PREMIUM']],
    'virtual app bundle' => [['isVirtualBundle' => true]],
]);

test('partial product card preserves bundle authority state', function (): void {
    fakeAhPayload(['productCard' => ['title' => 'Partial', 'priceBeforeBonus' => 3]]);

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi4')->snapshot;

    expect($snapshot?->price)->toBe('3')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->bundleOfferAuthoritative)->toBeFalse()
        ->and($snapshot?->promotionWindowAuthoritative)->toBeFalse();
});

test('invalid promotion dates cannot create an undated AH bundle', function (): void {
    $payload = jsonObject(File::get(base_path('tests/Fixtures/bundle-prices/ah-fixed-total.json')));
    $productCard = $payload['productCard'] ?? null;

    if (! is_array($productCard)) {
        throw new UnexpectedValueException('AH fixture must contain a product card.');
    }

    $productCard['bonusEndDate'] = 'not-a-date';
    $payload['productCard'] = $productCard;
    fakeAhPayload($payload);

    $snapshot = app(AhApiSource::class)->resolve('https://www.ah.nl/producten/product/wi597752')->snapshot;

    expect($snapshot?->trackedPrice())->toBe('6.49')
        ->and($snapshot?->bundleOffer)->toBeNull()
        ->and($snapshot?->raw['bundle_diagnostic'] ?? null)->toBe('invalid_promotion_window');
});

function fakeAhBundleApi(string $fixture): void
{
    $payload = jsonObject(File::get(base_path('tests/Fixtures/bundle-prices/' . $fixture)));

    fakeAhPayload($payload);
}

/** @return array<string, mixed> */
function jsonObject(string $json): array
{
    $decoded = json_decode(
        $json,
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($decoded)) {
        throw new UnexpectedValueException('AH fixture must contain a JSON object.');
    }

    $object = [];

    foreach ($decoded as $key => $value) {
        if (! is_string($key)) {
            throw new UnexpectedValueException('AH fixture must contain string keys.');
        }

        $object[$key] = $value;
    }

    return $object;
}

/**
 * @param  array<string, mixed>  $payload
 */
function fakeAhPayload(array $payload): void
{
    Http::fake([
        'https://api.ah.nl/mobile-auth/v1/auth/token/anonymous' => Http::response([
            'access_token' => 'test-token',
            'expires_in' => 7200,
        ]),
        'https://api.ah.nl/mobile-services/product/detail/v4/fir/*' => Http::response($payload),
    ]);
}
