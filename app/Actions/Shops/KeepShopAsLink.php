<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Enums\ScrapeStatus;
use App\Enums\ShopKind;
use App\Models\Product;
use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a shop DipCatch cannot read, as a link.
 *
 * Separate from {@see AttachShop} because it is the opposite case: that one
 * writes what a probe found, and this one is for when the probe found nothing
 * and the URL is worth keeping anyway. It shares nothing but the plan guard,
 * and sharing more would mean a draft with no snapshot behind it — a shape
 * every reader downstream would have to be taught to distrust.
 *
 * The row it writes has no price, no pack size and no currency of its own. It
 * is therefore out of both answers twice over: {@see Product::votingShops()}
 * bars the kind, and would bar it anyway for having no price.
 */
final readonly class KeepShopAsLink
{
    public function __construct(private PlanLimits $limits) {}

    /**
     * `$reason` is the probe failure that made this a link — the enum value,
     * so the retry can tell a wall worth trying again from one that will not
     * move, and a surface can say which it was.
     *
     * Counts against the shop limit like any other row. It occupies a place on
     * the product, shows on every surface that lists shops, and costs a fetch
     * each time it is retried. Letting it in free would also be the hard
     * direction to reverse: rows written under no limit cannot be brought back
     * under one without pushing somebody over it.
     *
     * @throws PlanLimitReached
     */
    public function __invoke(Product $product, string $url, ?string $reason = null): Shop
    {
        $normalized = UrlNormalizer::normalize($url);

        return DB::transaction(function () use ($product, $normalized, $reason): Shop {
            $this->limits->guardShop($product);

            return $product->shops()->create([
                'url' => $normalized,
                'kind' => ShopKind::Reference,
                'unreadable_reason' => $reason,
                // The currency the product compares in, so a later promotion
                // has something to check the first real read against.
                'currency' => $product->currency,
                'active' => true,
                // Never read, so it has never succeeded and never failed. A
                // failure count here would be judged by `dead_after` and the
                // row would switch itself off after ten retries.
                'last_status' => ScrapeStatus::Pending,
                'consecutive_failures' => 0,
            ]);
        });
    }
}
