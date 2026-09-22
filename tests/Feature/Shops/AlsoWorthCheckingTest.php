<?php declare(strict_types=1);

use App\Actions\Shops\KeepShopAsLink;
use App\Livewire\Notifications\Bell;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Support\AlsoWorthChecking;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * An alert is the one moment the reader is going to open a tab anyway, and
 * the shops DipCatch cannot read are often the largest retailers — the ones
 * running the biggest promotions.
 */
test('the line names the link shops and nothing else', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);

    app(KeepShopAsLink::class)($product, 'https://www.koffiehenk.nl/dolce-gusto-lungo-xl');
    app(KeepShopAsLink::class)($product, 'https://www.bol.com/nl/nl/p/lungo/9200000000000001/');
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1']);

    $shops = AlsoWorthChecking::of($product);

    expect(array_column($shops, 'host'))->toBe(['bol.com', 'koffiehenk.nl'])
        ->and(AlsoWorthChecking::line($shops))
        ->toBe('Also worth checking by hand: bol.com, koffiehenk.nl');
});

test('a product with no link shops adds no line', function (): void {
    // So a caller can append it without first deciding whether to.
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1']);

    expect(AlsoWorthChecking::line(AlsoWorthChecking::of($product)))->toBeNull();
});

test('a paused link is not suggested', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    app(KeepShopAsLink::class)($product, 'https://www.bol.com/nl/nl/p/x/1/')
        ->forceFill(['active' => false])->save();

    expect(AlsoWorthChecking::of($product))->toBeEmpty();
});

test('an alert carries the link shops', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    $product = Product::factory()->for($user)->create(['currency' => 'EUR']);
    app(KeepShopAsLink::class)($product, 'https://www.koffiehenk.nl/dolce-gusto-lungo-xl');

    $shop = Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/1']);
    $shop->forceFill(['currency' => 'EUR', 'current_price' => '2.00'])->save();
    $product->refresh()->recomputeCheapestShop();

    $payload = new TargetPriceNotification($product->refresh(), $shop, '2.00')->toDatabase($user);

    expect($payload['also_check'])->toBe([
        ['host' => 'koffiehenk.nl', 'url' => 'https://www.koffiehenk.nl/dolce-gusto-lungo-xl'],
    ]);
});

test('the bell prints the line, and says nothing when there is none', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => ['title' => 'Lungo XL', 'also_check' => [['host' => 'bol.com', 'url' => 'https://www.bol.com/x']]],
    ]);

    $this->actingAs($user);

    livewire(Bell::class)->assertSee('Also worth checking by hand: bol.com');
});

test('an alert sent before this change still renders', function (): void {
    // Every notification already in the database has no `also_check` key.
    $user = User::factory()->create(['notify_via_filament' => true]);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => ['title' => 'Older alert'],
    ]);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSee('Older alert')
        ->assertDontSee('Also worth checking');
});
