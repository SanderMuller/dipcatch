<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropOutcome;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

function buildOutcome(): DropOutcome
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

function buildProductAt85(User $user): Product
{
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'title' => 'Acme Headphones',
        'image_url' => 'https://example.com/img.png',
    ]);

    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://bol.com/p/headphones',
        'current_price' => '85.00',
    ]);

    $product->forceFill([
        'cheapest_shop_id' => $shop->id,
        'cheapest_price' => '85.00',
    ])->save();

    return $product;
}

test('toMail uses the price-drop markdown view with the right subject + payload', function (): void {
    $user = User::factory()->create();
    $product = buildProductAt85($user);

    $eventId = (string) Str::uuid();
    $message = new PriceDropNotification($product, buildOutcome(), $eventId)->toMail($user);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->subject)->toBe('Price drop on Acme Headphones at bol.com: EUR 85.00')
        ->and($message->markdown)->toBe('notifications.price-drop');

    expect($message->viewData)->toMatchArray([
        'newPrice' => '85.00',
        'host' => 'bol.com',
        'offerUrl' => 'https://bol.com/p/headphones',
        'referencePrice' => '100.00',
        'referenceKind' => 'median_30d',
        'dropPercent' => '15.00',
        'dropAbsolute' => '15.00',
    ]);

    $viewUrl = $message->viewData['viewUrl'] ?? '';
    expect($viewUrl)->toBeString()
        ->and($viewUrl)->not->toBe('');
});

test('toMail markdown view renders end-to-end via the Markdown renderer', function (): void {
    $user = User::factory()->create();
    $product = buildProductAt85($user);

    $message = new PriceDropNotification($product, buildOutcome(), (string) Str::uuid())->toMail($user);
    $rendered = (string) app(Markdown::class)
        ->render((string) $message->markdown, $message->viewData);

    expect($rendered)
        ->toContain('Acme Headphones')
        ->toContain('EUR 85.00')
        ->toContain('15.00%');
});

test('toDatabase payload contains the keys the dashboard widget consumes', function (): void {
    $user = User::factory()->create();
    $product = buildProductAt85($user);
    $eventId = (string) Str::uuid();

    $payload = new PriceDropNotification($product, buildOutcome(), $eventId)->toDatabase($user);

    expect($payload)->toMatchArray([
        'price_drop_event_id' => $eventId,
        'product_id' => $product->id,
        'title' => 'Acme Headphones',
        'currency' => 'EUR',
        'new_price' => '85.00',
        'host' => 'bol.com',
        'offer_url' => 'https://bol.com/p/headphones',
        'reference_kind' => 'median_30d',
        'drop_percent' => '15.00',
        'drop_absolute' => '15.00',
    ])->and($payload['view_url'])->toBeString();
});

test('via() respects per-user channel toggles', function (): void {
    $product = Product::factory()->create();
    $outcome = buildOutcome();

    $emailOnly = User::factory()->create(['notify_via_email' => true, 'notify_via_filament' => false]);
    $bellOnly = User::factory()->create(['notify_via_email' => false, 'notify_via_filament' => true]);
    $silent = User::factory()->create(['notify_via_email' => false, 'notify_via_filament' => false]);

    $notification = new PriceDropNotification($product, $outcome, 'fake-id');

    expect($notification->via($emailOnly))->toBe(['mail'])
        ->and($notification->via($bellOnly))->toBe(['database'])
        ->and($notification->via($silent))->toBe([]);
});
