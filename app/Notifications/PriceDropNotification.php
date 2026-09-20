<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PriceNormalizer;
use App\Services\Drops\DropOutcome;
use App\Support\BundlePriceLabel;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Real-time channels for a price drop: Filament in-app bell + web push.
 * Email is NOT a real-time channel anymore — it batches into the daily
 * digest dispatched by SendDailyDigest.
 */
final class PriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RestoresQueuedBundleSnapshot;

    /**
     * Snapshot of host/url/price at dispatch time. Pinned here so a recompute
     * that lands between fire and queue-render doesn't swap the payload to a
     * different shop or price than the `price_drop_events` row anchored to
     * this dispatch.
     */
    public readonly string $snapshotPrice;

    public readonly ?string $snapshotHost;

    public readonly ?string $snapshotOfferUrl;

    public readonly ?string $snapshotSingleItemPrice;

    public readonly ?BundleOffer $snapshotBundle;

    public function __construct(
        public Product $product,
        public DropOutcome $outcome,
        public string $priceDropEventId,
        ?PriceCheck $triggeringCheck = null,
    ) {
        // The shop the drop was measured on, which is the best-value winner
        // once the product has a comparison unit. Naming the lowest-outlay shop
        // instead would put a different host and a different price in the alert
        // from the ones in the `price_drop_events` row behind it.
        $unit = $outcome->comparisonUnit;
        $cheapest = $unit === null ? $product->cheapestShop : $product->bestValueShopRelation;
        $winningPrice = $product->winningPackPrice($unit);
        $useTriggeringCheck = $triggeringCheck !== null
            && $this->checkRepresentsCurrentPricing($triggeringCheck, $product, $cheapest);
        $this->snapshotPrice = $winningPrice ?? '0.00';
        $this->snapshotHost = is_string($cheapest?->host) && $cheapest->host !== '' ? $cheapest->host : null;
        $this->snapshotOfferUrl = is_string($cheapest?->url) && $cheapest->url !== '' ? $cheapest->url : null;
        $this->snapshotBundle = $useTriggeringCheck ? $triggeringCheck->bundleOffer() : $cheapest?->liveBundleOffer();
        $this->snapshotSingleItemPrice = $this->snapshotBundle === null
            ? null
            : ($useTriggeringCheck ? $triggeringCheck->singleItemPrice() : $cheapest?->singleItemPrice());

        // Defence in depth. `DetectDrop` already sends after its transaction
        // commits, so this is here for any future caller that notifies inside
        // one: a rollback must not leave a queued job pointing at a
        // PriceDropEvent that does not exist.
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if ($notifiable->notify_via_filament) {
            $channels[] = 'database';
        }
        if ($notifiable->notify_via_push && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $priceLine = MoneyFormatter::format($this->snapshotPrice, $this->product->currency);
        $body = $this->product->title . ' is now ' . $priceLine
            . BundlePriceLabel::suffix($this->snapshotBundle, $this->product->currency)
            . ($this->snapshotHost !== null ? ' at ' . $this->snapshotHost : '');

        return new WebPushMessage()
            ->title('Price drop: ' . $this->product->title)
            ->body($body)
            ->icon($this->product->safeImageUrl() ?? '/favicon.svg')
            ->data(['url' => route('app.products.show', $this->product)]);
    }

    /**
     * Database-channel payload — read by the Filament bell + the dashboard's
     * RecentNotificationsTableWidget. Keys must match what those widgets render.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'price_drop_event_id' => $this->priceDropEventId,
            'product_id' => $this->product->id,
            'title' => $this->product->title,
            'image_url' => $this->product->image_url,
            'currency' => $this->product->currency,
            'new_price' => $this->snapshotPrice,
            'single_item_price' => $this->snapshotSingleItemPrice,
            'bundle_quantity' => $this->snapshotBundle?->quantity,
            'bundle_total_price' => $this->snapshotBundle?->totalPrice,
            'host' => $this->snapshotHost,
            'offer_url' => $this->snapshotOfferUrl,
            'reference_price' => $this->outcome->referencePrice,
            'reference_kind' => $this->outcome->referenceKind,
            'drop_percent' => $this->outcome->dropPercent,
            // Null when the reference and the winner sell different amounts.
            // There is no money figure there — the pack difference would be a
            // saving nobody made, and on a move to a bigger pack a negative one.
            'drop_absolute' => $this->outcome->dropAbsolute,
            // Both bases named rather than left to be inferred: the percentage
            // was computed from these when `comparison_unit` is set.
            'reference_unit_price' => $this->outcome->referenceUnitPrice,
            'new_unit_price' => $this->outcome->newUnitPrice,
            'comparison_unit' => $this->outcome->comparisonUnit,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    private function checkRepresentsCurrentPricing(PriceCheck $check, Product $product, ?Shop $shop): bool
    {
        // Against the winning shop's own pack price, not the product's
        // lowest-outlay one: on a product spanning pack sizes those are two
        // different numbers, and the check read the winner's.
        $winningPrice = $product->winningPackPrice($product->best_value_shop_id === null ? null : $product->best_value_pack_unit);

        if ($shop === null || $check->shop_id !== $shop->id || $check->price === null || $winningPrice === null) {
            return false;
        }

        $checkPrice = PriceNormalizer::fromMixed($check->price);
        $currentPrice = PriceNormalizer::fromMixed($winningPrice);

        if ($checkPrice === null || $currentPrice === null
            || bccomp(Numeric::str($checkPrice), Numeric::str($currentPrice), 2) !== 0) {
            return false;
        }

        $checkBundle = $check->bundleOffer();
        $shopBundle = $shop->liveBundleOffer();

        return $checkBundle?->quantity === $shopBundle?->quantity
            && $checkBundle?->totalPrice === $shopBundle?->totalPrice
            && $check->singleItemPrice() === $shop->singleItemPrice();
    }
}
