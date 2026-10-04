<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\ShoppingList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A listed product at one or two shops, the lower price at `$cheapHost`.
 *
 * @param  array<model-property<Product>, mixed>  $product
 */
function listedProduct(User $user, string $title, string $cheapHost, ?string $dearHost = null, array $product = []): Product
{
    $made = Product::factory()->for($user)->listed()->create(['title' => $title, 'currency' => 'EUR', ...$product]);
    Shop::factory()->for($made)->create(['url' => 'https://' . $cheapHost . '/p/' . Str::slug($title), 'current_price' => '1.00', 'currency' => 'EUR']);

    if ($dearHost !== null) {
        Shop::factory()->for($made)->create(['url' => 'https://' . $dearHost . '/p/' . Str::slug($title), 'current_price' => '2.00', 'currency' => 'EUR']);
    }

    $made->refresh()->recomputeCheapestShop();

    return $made->refresh();
}

/**
 * @return list<string>
 */
function shoppingListHosts(User $user): array
{
    return array_column(ShoppingList::forUser($user)->groups, 'host');
}

/**
 * @return list<string>
 */
function shoppingListTitles(User $user, string $host): array
{
    $group = array_find(ShoppingList::forUser($user)->groups, fn (array $group): bool => $group['host'] === $host);

    return array_map(fn (array $item): string => $item['product']->title, $group['items'] ?? []);
}

it('groups each listed product under its best-buy shop, the most to buy first', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    listedProduct($user, 'Tea', 'ah.nl', 'jumbo.com');
    listedProduct($user, 'Milk', 'jumbo.com', 'ah.nl');
    Product::factory()->for($user)->create(['title' => 'Not listed']);

    $list = ShoppingList::forUser($user);

    expect(array_column($list->groups, 'host'))->toBe(['ah.nl', 'jumbo.com'])
        ->and(array_column($list->groups, 'open'))->toBe([2, 1])
        ->and($list->openCount)->toBe(3)
        ->and($list->crossedOffCount)->toBe(0)
        ->and(shoppingListTitles($user, 'ah.nl'))->toBe(['Coffee', 'Tea']);
});

it('puts a product no shop sells now in the last group, even when that group is the largest', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl');

    foreach (['Sold out', 'Also sold out'] as $title) {
        $product = listedProduct($user, $title, 'zzz.example');
        $product->shops()->update(['current_in_stock' => false]);
    }

    $list = ShoppingList::forUser($user);
    $last = $list->groups[1];

    expect(array_column($list->groups, 'host'))->toBe(['ah.nl', ''])
        ->and($last['shop'])->toBeNull()
        ->and(array_column($last['items'], 'shop'))->toBe([null, null]);
});

it('keeps the no-shop group last on a tie too, where its empty host would sort first', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl');
    listedProduct($user, 'Sold out', 'ah.nl')->shops()->update(['current_in_stock' => false]);

    expect(shoppingListHosts($user))->toBe(['ah.nl', '']);
});

it('lists open items before crossed-off ones, each in the order they were added', function (): void {
    $user = User::factory()->create();
    $this->travelTo(now()->subMinutes(3));
    $first = listedProduct($user, 'First', 'ah.nl');
    $this->travelBack();
    $this->travelTo(now()->subMinutes(2));
    listedProduct($user, 'Second', 'ah.nl');
    $this->travelBack();
    listedProduct($user, 'Third', 'ah.nl');
    $first->setCrossedOff(crossedOff: true);

    expect(shoppingListTitles($user, 'ah.nl'))->toBe(['Second', 'Third', 'First']);
});

it('follows the best buy when it moves to another shop', function (): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');

    expect(shoppingListHosts($user))->toBe(['ah.nl']);

    $product->shops()->where('host', 'jumbo.com')->update(['current_price' => '0.50']);
    $product->refresh()->recomputeCheapestShop();

    expect(shoppingListHosts($user))->toBe(['jumbo.com']);
});

it('keeps a paused product on the list', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Paused coffee', 'ah.nl', product: ['active' => false]);

    expect(shoppingListTitles($user, 'ah.nl'))->toBe(['Paused coffee']);
});

it('leaves nothing on the list when a listed product is deleted', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl')->delete();

    expect(ShoppingList::forUser($user)->isEmpty())->toBeTrue();
});

it('only lists this account\'s products', function (): void {
    $user = User::factory()->create();
    listedProduct(User::factory()->create(), 'Someone else', 'ah.nl');

    expect(ShoppingList::forUser($user)->isEmpty())->toBeTrue();
});

it('changes the list without touching updated_at, and refreshes the model', function (Closure $write, bool $listed, bool $crossedOff): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl');
    $product->forceFill(['listed_at' => null])->saveQuietly();
    $updatedAt = Product::query()->whereKey($product->id)->value('updated_at');
    $this->travel(5)->minutes();

    $write($product->refresh());

    expect(Product::query()->whereKey($product->id)->value('updated_at'))->toEqual($updatedAt)
        ->and($product->isOnShoppingList())->toBe($listed)
        ->and($product->isCrossedOff())->toBe($crossedOff);
})->with([
    'add' => [fn (Product $product) => $product->addToShoppingList(), true, false],
    'add, then cross off' => [function (Product $product): void {
        $product->addToShoppingList();
        $product->setCrossedOff(crossedOff: true);
    }, true, true],
    'add, then remove' => [function (Product $product): void {
        $product->addToShoppingList();
        $product->removeFromShoppingList();
    }, false, false],
]);

it('clears the crossed-off items without touching updated_at', function (): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl');
    $product->setCrossedOff(crossedOff: true);
    $updatedAt = Product::query()->whereKey($product->id)->value('updated_at');
    $this->travel(5)->minutes();

    Product::clearCrossedOffFor($user);

    expect(Product::query()->whereKey($product->id)->value('updated_at'))->toEqual($updatedAt)
        ->and($product->refresh()->isOnShoppingList())->toBeFalse();
});

it('re-adding a crossed-off product un-crosses it', function (): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl');
    $product->setCrossedOff(crossedOff: true);

    $product->addToShoppingList();

    expect($product->isOnShoppingList())->toBeTrue()
        ->and($product->isCrossedOff())->toBeFalse();
});

it('does not bring back a product another tab removed when it is crossed off', function (): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl');
    $stale = $product->replicate()->setRawAttributes($product->getAttributes());
    $product->removeFromShoppingList();

    $stale->setCrossedOff(crossedOff: true);

    expect($product->refresh()->listed_at)->toBeNull()
        ->and($product->list_checked_at)->toBeNull();
});

it('clears only this account\'s crossed-off items', function (): void {
    $user = User::factory()->create();
    $crossed = listedProduct($user, 'Crossed', 'ah.nl');
    $crossed->setCrossedOff(crossedOff: true);
    $open = listedProduct($user, 'Open', 'ah.nl');
    $other = listedProduct(User::factory()->create(), 'Other account', 'ah.nl');
    $other->setCrossedOff(crossedOff: true);

    expect(Product::clearCrossedOffFor($user))->toBe(1)
        ->and($crossed->refresh()->isOnShoppingList())->toBeFalse()
        ->and($open->refresh()->isOnShoppingList())->toBeTrue()
        ->and($other->refresh()->isCrossedOff())->toBeTrue();
});

it('reads the list in the same number of queries however long it is', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'One', 'ah.nl', 'jumbo.com');

    DB::enableQueryLog();
    ShoppingList::forUser($user);
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(1, 12) as $i) {
        listedProduct($user, 'Product ' . $i, $i % 2 === 0 ? 'ah.nl' : 'lidl.nl', 'jumbo.com');
    }

    DB::flushQueryLog();
    ShoppingList::forUser($user);

    expect(DB::getQueryLog())->toHaveCount($few)->toHaveCount(3);
});

it('moves a product to its next best shop when its best shop is skipped', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    listedProduct($user, 'Tea', 'lidl.nl', 'jumbo.com');
    listedProduct($user, 'Milk', 'jumbo.com', 'ah.nl');

    $list = ShoppingList::forUser($user, skip: ['lidl.nl']);

    expect(array_column($list->groups, 'host'))->toBe(['jumbo.com', 'ah.nl'])
        ->and(array_map(fn (array $item): string => $item['product']->title, $list->groups[0]['items']))->toBe(['Tea', 'Milk'])
        ->and(array_map(fn (array $item): string => $item['headline']->text(), $list->groups[0]['items']))->toBe(['€2.00', '€1.00']);
});

it('puts a product only skipped shops sell in its own group, naming where it is sold', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl');
    listedProduct($user, 'Tea', 'lidl.nl');
    listedProduct($user, 'Sold out', 'ah.nl')->shops()->update(['current_in_stock' => false]);

    $list = ShoppingList::forUser($user, skip: ['lidl.nl']);

    expect(array_map(fn (array $group): array => [$group['host'], $group['skipped']], $list->groups))->toBe([['ah.nl', false], ['', true], ['', false]])
        ->and($list->groups[1]['items'][0]['skippedBest']?->host)->toBe('lidl.nl')
        ->and($list->groups[1]['items'][0]['shop'])->toBeNull()
        ->and($list->groups[2]['items'][0]['skippedBest'])->toBeNull();
});

it('offers every shop that sells an open item, skipped ones included, most items first', function (): void {
    $user = User::factory()->create();
    listedProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    listedProduct($user, 'Tea', 'lidl.nl', 'jumbo.com');
    $crossed = listedProduct($user, 'Bread', 'plus.nl');
    $crossed->setCrossedOff(crossedOff: true);

    expect(ShoppingList::forUser($user, skip: ['jumbo.com'])->shops)->toBe([
        ['host' => 'jumbo.com', 'items' => 2],
        ['host' => 'ah.nl', 'items' => 1],
        ['host' => 'lidl.nl', 'items' => 1],
    ]);
});

it('leaves the loaded product\'s shops alone when it skips one', function (): void {
    $user = User::factory()->create();
    $product = listedProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    $product->load('shops');

    $item = ShoppingList::item($product, ['ah.nl']);

    expect($item['shop']?->host)->toBe('jumbo.com')
        ->and($product->shops)->toHaveCount(2);
});
