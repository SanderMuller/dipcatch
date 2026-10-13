<?php declare(strict_types=1);

use App\Enums\SocialProvider;
use App\Models\Invitation;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;

test('invitation factory states work and helpers report state', function (): void {
    $fresh = Invitation::factory()->create();
    $expired = Invitation::factory()->expired()->create();
    $redeemed = Invitation::factory()->redeemed()->create();

    expect($fresh->isExpired())->toBeFalse()
        ->and($fresh->isRedeemed())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($redeemed->isRedeemed())->toBeTrue();
});

test('cascade delete on user wipes products', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(2)->for($user)->create();

    $user->delete();

    expect(Product::query()->count())->toBe(0);
});

test('cascade delete on product wipes its offers and their checks', function (): void {
    $product = Product::factory()->create();
    $shop = Shop::factory()->for($product)->create();
    PriceCheck::factory()->count(2)->for($shop)->create();

    $product->delete();

    expect(Shop::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(0);
});

test('social_accounts refuses two users claiming the same provider account', function (): void {
    // The index is the real race guard: `lockForUpdate` locks nothing on a row
    // that does not exist yet on Postgres, so two concurrent first-time
    // callbacks can both reach the insert.
    SocialAccount::factory()->create([
        'provider' => SocialProvider::Google,
        'provider_id' => 'shared-google-sub',
    ]);

    expect(fn (): SocialAccount => SocialAccount::factory()->create([
        'provider' => SocialProvider::Google,
        'provider_id' => 'shared-google-sub',
    ]))->toThrow(QueryException::class);
});

test('social_accounts refuses one user linking the same provider twice', function (): void {
    $user = User::factory()->create();

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
    ]);

    expect(fn (): SocialAccount => SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => SocialProvider::Google,
    ]))->toThrow(QueryException::class);
});

it('never takes is_admin from mass assignment', function (): void {
    $user = User::create(['name' => 'Someone', 'email' => 'someone@example.test', 'password' => 'secret-password', 'is_admin' => true]);

    expect($user->refresh()->is_admin)->toBeFalse();

    $user->update(['is_admin' => true]);

    expect($user->refresh()->is_admin)->toBeFalse();
});
