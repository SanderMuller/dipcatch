<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Shops\CheckOutcome;
use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Enums\ScrapeStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\PackSize;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Asks Jev whether a page that states no pack size sells the size the
 * product's other shops agree on, from the page's own title and price. Only
 * for an account with the AI shop check, and only where the borrowed size
 * is in doubt: marked estimated, or looking wrong for the price. A sure yes
 * is stored as the page's confirmed size, which may then win and alert. The
 * same page and size are asked about once, whatever the answer.
 */
#[Tries(1)]
#[Timeout(75)]
final class ConfirmPackSize implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Jev's chance from which the borrowed size counts as the page's own. */
    public const float CONFIRM_FROM = 0.8;

    public function __construct(public string $shopId, public string $pageTitle) {}

    /** Queued after a price check that read the page, which is when its title is at hand. */
    public static function afterRead(string $shopId, CheckOutcome $outcome): void
    {
        $shop = Shop::query()->with('product.user')->find($shopId);

        if ($outcome->status === ScrapeStatus::Ok && $outcome->snapshot !== null && $shop instanceof Shop) {
            self::dispatchFor($shop, $outcome->snapshot->title);
        }
    }

    public static function dispatchFor(Shop $shop, ?string $pageTitle): void
    {
        $owner = $shop->product?->user;

        if ($pageTitle === null || trim($pageTitle) === '' || $shop->pack_quantity !== null
            || ! $owner instanceof User || ! $owner->wantsShopChecks() || ! TypeSafeClient::configured()) {
            return;
        }

        dispatch(new self((string) $shop->id, mb_substr(trim($pageTitle), 0, 255)));
    }

    public function uniqueId(): string
    {
        return "confirm-pack-size:{$this->shopId}";
    }

    public function handle(ShopMatchCheck $check): void
    {
        $shop = Shop::query()->with('product.shops', 'product.user')->find($this->shopId);
        $product = $shop?->product;

        if (! $shop instanceof Shop || ! $product instanceof Product || $shop->pack_quantity !== null || ! $check->applies($product)) {
            return;
        }

        $size = self::sizeInDoubt($shop, $product);
        $key = $size === null ? null : hash('sha256', "{$shop->url}|{$size->quantity}|{$size->unit}");

        if ($size === null || $key === $shop->pack_check_key) {
            return;
        }

        $answers = $check->ask($product, ShopCheckPurpose::PackSize, ['page' => ShopMatchCheck::candidate(
            shop: (string) $shop->host,
            title: $this->pageTitle,
            packSize: ShopMatchCheck::packLabel($size),
            price: "{$shop->current_price} {$shop->currency}",
            gtin: $shop->gtin,
        )], quick: false);

        // No answer (the budget spent, Jev down): asked again on a later check.
        if (! array_key_exists('page', $answers)) {
            return;
        }

        $confirmed = $answers['page'] >= self::CONFIRM_FROM;

        $shop->forceFill([
            'pack_check_key' => $key,
            'pack_checked_at' => now(),
            'confirmed_pack_quantity' => $confirmed ? $size->quantity : null,
            'confirmed_pack_unit' => $confirmed ? $size->unit : null,
        ])->save();

        if ($confirmed) {
            $product->refresh()->recomputeCheapestShop();
        }
    }

    /**
     * The size the page borrows from its siblings, when that is in doubt:
     * estimated, or excluded as looking wrong. Null when the page needs no
     * answer, or the siblings agree on no size.
     */
    private static function sizeInDoubt(Shop $shop, Product $product): ?PackSize
    {
        $packs = $product->comparablePacks();
        $pack = $packs->for($shop);

        if ($pack === null || $pack->provenance === PackProvenance::Stated || $pack->provenance === PackProvenance::Confirmed) {
            return null;
        }

        return $pack->provenance === PackProvenance::Inferred || $pack->exclusion === PackExclusion::SizeImplausible
            ? $packs->sharedSize()
            : null;
    }
}
