<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Models\Invitation;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;

test('user has dipcatch columns with sensible defaults', function (): void {
    $user = User::factory()->create();

    expect($user->is_admin)->toBeFalse()
        ->and($user->default_currency)->toBe('EUR')
        ->and($user->notify_via_email)->toBeTrue()
        ->and($user->notify_via_filament)->toBeTrue()
        ->and($user->notify_via_push)->toBeFalse();
});

test('product factory builds a valid product with cast columns', function (): void {
    $product = Product::factory()->create();

    expect($product->id)->toBeString()
        ->and($product->fallback_selectors)->toBeArray()
        ->and($product->active)->toBeTrue()
        ->and($product->needs_js)->toBeFalse()
        ->and($product->last_status)->toBe(ScrapeStatus::Ok)
        ->and($product->user)->toBeInstanceOf(User::class);
});

test('product hasMany price checks', function (): void {
    $product = Product::factory()->create();
    PriceCheck::factory()->count(3)->for($product)->create();

    expect($product->priceChecks)->toHaveCount(3)
        ->and($product->priceChecks->first())->toBeInstanceOf(PriceCheck::class);
});

test('price check status casts to enum', function (): void {
    $check = PriceCheck::factory()->failed(ScrapeStatus::HttpError)->create();

    expect($check->status)->toBe(ScrapeStatus::HttpError)
        ->and($check->price)->toBeNull();
});

test('invitation factory states work and helpers report state', function (): void {
    $fresh = Invitation::factory()->create();
    $expired = Invitation::factory()->expired()->create();
    $redeemed = Invitation::factory()->redeemed()->create();

    expect($fresh->isExpired())->toBeFalse()
        ->and($fresh->isRedeemed())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($redeemed->isRedeemed())->toBeTrue()
        ->and($fresh->inviter)->toBeInstanceOf(User::class);
});

test('price drop event links product, user, and price check', function (): void {
    $event = PriceDropEvent::factory()->create();

    expect($event->id)->toBeString()
        ->and($event->product)->toBeInstanceOf(Product::class)
        ->and($event->user)->toBeInstanceOf(User::class)
        ->and($event->priceCheck)->toBeInstanceOf(PriceCheck::class)
        ->and($event->reference_kind)->toBe('median_30d');
});

test('cascade delete on user wipes products', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(2)->for($user)->create();

    $user->delete();

    expect(Product::query()->count())->toBe(0);
});

test('cascade delete on product wipes its price checks and drop events', function (): void {
    $product = Product::factory()->create();
    $checks = PriceCheck::factory()->count(2)->for($product)->create();
    PriceDropEvent::factory()->state([
        'product_id' => $product->id,
        'user_id' => $product->user_id,
        'price_check_id' => $checks->first()->id,
    ])->create();

    $product->delete();

    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(PriceDropEvent::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('ScrapeStatus enum has all seven cases with stable string values', function (): void {
    expect(ScrapeStatus::Ok->value)->toBe('ok')
        ->and(ScrapeStatus::EmptyMatch->value)->toBe('empty_match')
        ->and(ScrapeStatus::HttpError->value)->toBe('http_error')
        ->and(ScrapeStatus::ParseError->value)->toBe('parse_error')
        ->and(ScrapeStatus::Throttled->value)->toBe('throttled')
        ->and(ScrapeStatus::RobotsBlocked->value)->toBe('robots_blocked')
        ->and(ScrapeStatus::NeedsJs->value)->toBe('needs_js');
});
