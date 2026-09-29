<?php declare(strict_types=1);

use App\Actions\Shops\ProbeBudget;
use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

/**
 * Changing a shop's address fetches the new page, so it spends from the same
 * per-account budget as adding a shop. Without it a script could make the
 * server fetch without limit, one host at a time.
 */
it('refuses a new shop URL once the account has used its fetch budget, and fetches nothing', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();
    $shop = Shop::factory()->for($product)->create(['url' => 'https://shop.example.com/p/1', 'current_price' => '5.00']);

    foreach (range(1, ProbeBudget::PER_MINUTE) as $ignored) {
        RateLimiter::hit('dipcatch:probe:user:' . $user->id);
    }

    Http::fake();
    $this->actingAs($user);

    livewire(ProductShow::class, ['product' => $product])
        ->call('saveShopUrl', $shop->id, 'https://shop.example.com/p/2')
        ->assertSet('shopMessage', fn (string $message): bool => str_starts_with($message, 'You have checked too many links in the last minute.'));

    Http::assertNothingSent();
    expect($shop->refresh()->url)->toBe('https://shop.example.com/p/1');
});
