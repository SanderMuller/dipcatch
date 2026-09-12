<?php declare(strict_types=1);

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ShopDraft;
use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

function draft(string $url = 'https://ah.nl/p/1', string $price = '2.50'): ShopDraft
{
    return ShopDraft::fromSnapshot(
        snapshot: [
            'price' => $price,
            'currency' => 'EUR',
            'in_stock' => true,
            'title' => 'Coffee 500 g',
            'image_url' => 'https://ah.nl/img.png',
            'gtin' => '08710400123456',
        ],
        url: $url,
        adapterKey: 'ah',
    );
}

it('reads price, currency, image and gtin off a flattened snapshot', function (): void {
    $draft = draft();

    expect($draft->price)->toBe('2.50')
        ->and($draft->currency)->toBe('EUR')
        ->and($draft->imageUrl)->toBe('https://ah.nl/img.png')
        ->and($draft->gtin)->toBe('08710400123456')
        ->and($draft->inStock)->toBeTrue();
});

it('parses a pack size out of the title when the source is not authoritative', function (): void {
    // The web relies on this fallback, and it is one of the things a
    // ProbeOutcome-shaped signature would have dropped.
    expect(draft()->packSize?->quantity)->toBe(500.0)
        ->and(draft()->packSize?->unit)->toBe('g');
});

it('treats blank snapshot fields as absent rather than empty strings', function (): void {
    $draft = ShopDraft::fromSnapshot(
        snapshot: ['price' => '1.00', 'currency' => 'EUR', 'image_url' => '', 'gtin' => ''],
        url: 'https://ah.nl/p/2',
        adapterKey: 'ah',
    );

    expect($draft->imageUrl)->toBeNull()->and($draft->gtin)->toBeNull();
});

it('writes a shop, its first price check, and the cheapest recompute', function (): void {
    $product = Product::factory()->create();

    $shop = app(AttachShop::class)($product, draft());

    expect($shop->current_price)->not->toBeNull()
        ->and($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1)
        ->and($product->fresh()?->cheapest_shop_id)->toBe($shop->id);
});

it('passes the triggering check id to the recompute, so drop detection sees it', function (): void {
    $product = Product::factory()->create();

    app(AttachShop::class)($product, draft());

    $check = PriceCheck::query()->latest('id')->first();
    $segment = $product->cheapestHistory()->newestFirst()->first();

    expect($segment?->triggering_price_check_id)->toBe($check?->id);
});

it('creates a product and its first shop together', function (): void {
    $user = User::factory()->create();

    $product = app(CreateProductWithShop::class)(
        $user,
        new ProductDraft(title: 'Coffee', dropThresholdPct: '10.00', dropThresholdAbs: '0.50'),
        draft(),
    );

    expect($product->user_id)->toBe($user->id)
        ->and($product->title)->toBe('Coffee')
        ->and((string) $product->drop_threshold_pct)->toBe('10.00')
        ->and((string) $product->drop_threshold_abs)->toBe('0.50')
        ->and($product->shops()->count())->toBe(1);
});

it('refuses to attach a shop past the plan limit, and writes nothing', function (): void {
    $product = Product::factory()->create();
    $limit = app(PlanLimits::class)->remainingShops($product);

    expect($limit)->not->toBeNull();

    for ($i = 0; $i < (int) $limit; $i++) {
        app(AttachShop::class)($product, draft('https://ah.nl/p/' . $i));
    }

    $before = Shop::query()->count();

    expect(fn () => app(AttachShop::class)($product, draft('https://ah.nl/over-the-line')))
        ->toThrow(PlanLimitReached::class)
        ->and(Shop::query()->count())->toBe($before);
});

it('rolls the whole create back when the product limit is reached', function (): void {
    $user = User::factory()->create();
    $limit = (int) app(PlanLimits::class)->remainingProducts($user);

    for ($i = 0; $i < $limit; $i++) {
        Product::factory()->create(['user_id' => $user->id]);
    }

    $shopsBefore = Shop::query()->count();

    expect(fn () => app(CreateProductWithShop::class)(
        $user,
        new ProductDraft(title: 'One too many'),
        draft(),
    ))->toThrow(PlanLimitReached::class)
        ->and($user->products()->count())->toBe($limit)
        ->and(Shop::query()->count())->toBe($shopsBefore);
});
