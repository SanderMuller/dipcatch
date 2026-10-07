<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Actions\Shops\CheckOutcome;
use App\Enums\ScrapeStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\ShopMatchCheck;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\PackSize;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;

/**
 * Asks Jev whether a page sells the pack its two sizes describe — 20 pieces
 * that weigh 560 g — when the second size is in doubt: it lands far from the
 * field's price per unit, or a target waits on a conversion that this shop's
 * item size disagrees with. Only for an account with the AI shop check.
 *
 * A sure yes lets a second size past the plausibility guard. On an item size
 * outside the band it blocks the conversion for good instead, because the
 * shops then sell different items. A sure no keeps it out for good, whatever
 * the prices do later. An answer in between decides nothing, and the size
 * stays in doubt. Once Jev answers about a pair it is not asked again; a pair
 * that changes is a new question.
 *
 * Unique until it has run, not until it starts like {@see ConfirmPackSize}:
 * a second read of the page while Jev is still answering must not pay for
 * the same question twice.
 */
#[Tries(1)]
#[Timeout(75)]
final class ConfirmAltPackSize implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Jev's chance under which the pair counts as wrong.
     *
     * Far below {@see ConfirmPackSize::CONFIRM_FROM}, because a title rarely
     * states both sizes. Asked live on 2026-10-07 about Iglo fish fingers,
     * Jev gave the right pairs 0.54 to 0.73, a wrong weight 0.32 to 0.51,
     * and a wrong count 0.02 to 0.05. Rejecting under 0.8 would throw out
     * the right pairs for good.
     */
    public const float REJECT_BELOW = 0.2;

    /** @param  string  $url  The page the title was read from: an answer about it is not stored for another. */
    /** @param  string  $pairKey  The two sizes the title was read beside: a newer pair is not judged on this title. */
    public function __construct(public string $shopId, public string $pageTitle, public string $url, public string $pairKey) {}

    /** Queued after a price check that read the page, which is when its title is at hand. */
    /** @param  string|null  $pairKey  The two sizes the read stored beside this title; see {@see pairKey()}. */
    public static function afterRead(string $shopId, string $readUrl, CheckOutcome $outcome, ?string $pairKey): void
    {
        if ($pairKey === null || $outcome->status !== ScrapeStatus::Ok || $outcome->snapshot === null || ! TypeSafeClient::configured()) {
            return;
        }

        $shop = Shop::query()->whereNotNull('alt_pack_quantity')->where('url', $readUrl)->with('product.shops', 'product.user')->find($shopId);
        $owner = $shop?->product?->user;
        $title = trim($outcome->snapshot->title);

        if (! $shop instanceof Shop || $title === '' || ! $owner instanceof User || ! $owner->wantsShopChecks()) {
            return;
        }

        $product = $shop->product;

        // Another read can store a newer pair between this read's write and
        // here: the title belongs to the pair this read stored, or to none.
        if ($product instanceof Product && self::pairKey($shop) === $pairKey && $pairKey !== $shop->alt_pack_check_key && self::inDoubt($shop, $product)) {
            dispatch(new self((string) $shop->id, mb_substr($title, 0, 255), (string) $shop->url, self::pairKey($shop)));
        }
    }

    /** Longer than the job's timeout, so a job lost from the queue frees the shop again. */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return "confirm-alt-pack-size:{$this->shopId}";
    }

    public function handle(ShopMatchCheck $check): void
    {
        $shop = Shop::query()->with('product.shops', 'product.user')->find($this->shopId);
        $product = $shop?->product;
        $primary = $shop?->packSize();
        $alt = $shop?->altPackSize();

        if (! $shop instanceof Shop || ! $product instanceof Product || $shop->url !== $this->url
            || ! $primary instanceof PackSize || ! $alt instanceof PackSize || ! $check->applies($product)) {
            return;
        }

        $key = self::pairKey($shop);

        if ($key !== $this->pairKey || $key === $shop->alt_pack_check_key || ! self::inDoubt($shop, $product)) {
            return;
        }

        // Both sizes in one label: the question is whether the page sells
        // this pack, and either half alone describes a different one.
        $answers = $check->ask($product, ShopCheckPurpose::PackSize, ['page' => ShopMatchCheck::candidate(
            shop: (string) $shop->host,
            title: $this->pageTitle,
            packSize: null,
            price: "{$shop->current_price} {$shop->currency}",
            gtin: $shop->gtin,
        )], quick: false, exactPackSize: ShopMatchCheck::packLabel($primary) . ' (' . ShopMatchCheck::packLabel($alt) . ')');

        // No answer (the budget spent, Jev down): asked again on a later check.
        if (! array_key_exists('page', $answers)) {
            return;
        }

        $confirmed = match (true) {
            $answers['page'] >= ConfirmPackSize::CONFIRM_FROM => true,
            $answers['page'] < self::REJECT_BELOW => false,
            default => null,
        };

        // Shop row first, as a price check locks it, and in one transaction
        // with the recompute. Either answer moves derived state: a yes lets
        // the size in, a no can remove the shop that blocked a conversion.
        DB::transaction(function () use ($product, $key, $confirmed): void {
            $locked = Shop::query()->lockForUpdate()->whereKey($this->shopId)->where('url', $this->url)->first();

            // A check that changed either size while Jev answered makes this
            // an answer about some other pack; a sibling job that already
            // answered this pair has the last word.
            if (! $locked instanceof Shop || self::pairKey($locked) !== $key || $locked->alt_pack_check_key === $key) {
                return;
            }

            $locked->forceFill([
                'alt_pack_check_key' => $key,
                'alt_pack_confirmed' => $confirmed,
                ...($confirmed === true ? ['alt_pack_since' => now()] : []),
            ])->save();

            $product->refresh()->recomputeCheapestShop(sizesChanged: true);
            app(DetectUnitPriceTarget::class)($product->refresh());
        });
    }

    private static function inDoubt(Shop $shop, Product $product): bool
    {
        $packs = $product->comparablePacks();

        if ($packs->altInDoubt($shop)) {
            return true;
        }

        $from = $product->unit_price_target_unit;
        $to = $packs->unit();

        return $product->isUnitTargetSuspended() && $from !== null && $to !== null
            && in_array((string) $shop->id, $packs->itemSizeOutliers($from, $to), strict: true);
    }

    /**
     * The page and its two sizes, in either order: a shop that swaps which
     * one it leads with still sells the pack Jev was asked about.
     */
    public static function pairKey(Shop $shop): string
    {
        $sizes = array_map(
            fn (?PackSize $size): string => "{$size?->quantity}|{$size?->unit}",
            [$shop->packSize(), $shop->altPackSize()],
        );
        sort($sizes);

        return hash('sha256', $shop->url . '|' . implode('|', $sizes));
    }
}
