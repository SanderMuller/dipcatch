<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropOutcome;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

function pushOutcome(): DropOutcome
{
    return new DropOutcome(
        belowThreshold: true,
        referencePrice: '100.00',
        referenceKind: 'median_30d',
        dropAbsolute: '15.00',
        dropPercent: '15.00',
        thresholdAbs: '5.00',
        thresholdPct: '10.00',
    );
}

/**
 * @return array<string, string|array<string, string>>
 */
function subscriptionPayload(string $endpoint = 'https://push.example.com/endpoint/abc'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => 'BJ_p256dh_public_key_base64url',
            'auth' => 'auth_secret_base64url',
        ],
        'contentEncoding' => 'aes128gcm',
    ];
}

test('POST /push/subscribe creates a push_subscriptions row for the authed user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('push.subscribe'), subscriptionPayload());

    $response->assertCreated()->assertJson(['ok' => true]);

    expect($user->pushSubscriptions()->count())->toBe(1);
    $sub = $user->pushSubscriptions()->firstOrFail();
    expect($sub->endpoint)->toBe('https://push.example.com/endpoint/abc')
        ->and($sub->public_key)->toBe('BJ_p256dh_public_key_base64url')
        ->and($sub->auth_token)->toBe('auth_secret_base64url')
        ->and($sub->content_encoding)->toBe('aes128gcm');
});

test('subscribe is auth-required', function (): void {
    $this->postJson(route('push.subscribe'), subscriptionPayload())->assertUnauthorized();
});

test('subscribe rejects missing keys', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.com/x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
});

test('subscribe rejects non-URL endpoints and endpoints longer than the column', function (): void {
    $user = User::factory()->create();

    $tooLong = 'https://push.example.com/' . str_repeat('a', 600);

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [
            'endpoint' => 'not-a-url',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [
            'endpoint' => $tooLong,
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);

    expect($user->pushSubscriptions()->count())->toBe(0);
});

test('DELETE /push/subscribe removes only the matching endpoint', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('push.subscribe'), subscriptionPayload('https://push.example.com/a'));
    $this->actingAs($user)->postJson(route('push.subscribe'), subscriptionPayload('https://push.example.com/b'));

    expect($user->pushSubscriptions()->count())->toBe(2);

    $this->actingAs($user)
        ->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example.com/a'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $user->refresh();
    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->first()?->endpoint)->toBe('https://push.example.com/b');
});

test('via() includes the WebPush channel only when toggle is on AND user has at least one subscription', function (): void {
    $product = Product::factory()->create();
    $outcome = pushOutcome();

    $togglesOnNoSub = User::factory()->create([
        'notify_via_email' => false, 'notify_via_filament' => false, 'notify_via_push' => true,
    ]);
    $togglesOffWithSub = User::factory()->create([
        'notify_via_email' => false, 'notify_via_filament' => false, 'notify_via_push' => false,
    ]);
    $togglesOnWithSub = User::factory()->create([
        'notify_via_email' => false, 'notify_via_filament' => false, 'notify_via_push' => true,
    ]);

    $togglesOffWithSub->updatePushSubscription('https://push.example.com/x', 'k', 'a');
    $togglesOnWithSub->updatePushSubscription('https://push.example.com/y', 'k', 'a');

    $notification = new PriceDropNotification($product, $outcome, 'fake-id');

    expect($notification->via($togglesOnNoSub))->toBe([])
        ->and($notification->via($togglesOffWithSub))->toBe([])
        ->and($notification->via($togglesOnWithSub))->toBe([WebPushChannel::class]);
});

test('toWebPush returns a WebPushMessage with title, body, icon and click url', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => 'Acme Headphones',
        'image_url' => 'https://example.com/img.png',
    ]);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/x',
        'current_price' => '85.00',
    ]);
    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '85.00',
    ])->save();

    $message = new PriceDropNotification($product, pushOutcome(), (string) Str::uuid())->toWebPush($user);

    $payload = $message->toArray();
    expect($payload['title'])->toBe('Price drop: Acme Headphones')
        ->and($payload['body'])->toBe('Acme Headphones is now €85.00 at bol.com')
        ->and($payload['icon'])->toBe('https://example.com/img.png')
        ->and($payload['data'])->toMatchArray(['url' => $payload['data']['url']]);

    expect($payload['data']['url'])->toBeString()->and($payload['data']['url'])->not->toBe('');
});
