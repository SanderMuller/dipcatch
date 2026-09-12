<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\User;
use App\Services\Drops\DropOutcome;
use App\Support\MoneyFormatter;
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

    public readonly ?int $snapshotBundleQuantity;

    public readonly ?string $snapshotBundleTotalPrice;

    public function __construct(
        public Product $product,
        public DropOutcome $outcome,
        public string $priceDropEventId,
        ?PriceCheck $triggeringCheck = null,
    ) {
        $cheapest = $triggeringCheck !== null ? $triggeringCheck->shop : $product->cheapestShop;
        $this->snapshotPrice = $triggeringCheck?->price === null
            ? ($product->cheapest_price === null ? '0.00' : (string) $product->cheapest_price)
            : (string) $triggeringCheck->price;
        $this->snapshotHost = is_string($cheapest?->host) && $cheapest->host !== '' ? $cheapest->host : null;
        $this->snapshotOfferUrl = is_string($cheapest?->url) && $cheapest->url !== '' ? $cheapest->url : null;
        $bundle = $triggeringCheck !== null ? $triggeringCheck->bundleOffer() : $cheapest?->liveBundleOffer();
        $this->snapshotSingleItemPrice = $bundle === null
            ? null
            : ($triggeringCheck?->singleItemPrice() ?? $cheapest?->singleItemPrice());
        $this->snapshotBundleQuantity = $bundle?->quantity;
        $this->snapshotBundleTotalPrice = $bundle?->totalPrice;

        // Defer queue dispatch until the surrounding DB transaction commits
        // so a rollback inside DetectDrop cannot leave a queued job pointing
        // at a non-existent PriceDropEvent.
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
            . $this->bundleSuffix()
            . ($this->snapshotHost !== null ? ' at ' . $this->snapshotHost : '');

        return new WebPushMessage()
            ->title('Price drop: ' . $this->product->title)
            ->body($body)
            ->icon($this->product->image_url ?? '/favicon.svg')
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
            'bundle_quantity' => $this->snapshotBundleQuantity,
            'bundle_total_price' => $this->snapshotBundleTotalPrice,
            'host' => $this->snapshotHost,
            'offer_url' => $this->snapshotOfferUrl,
            'reference_price' => $this->outcome->referencePrice,
            'reference_kind' => $this->outcome->referenceKind,
            'drop_percent' => $this->outcome->dropPercent,
            'drop_absolute' => $this->outcome->dropAbsolute,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    private function bundleSuffix(): string
    {
        if ($this->snapshotBundleQuantity === null || $this->snapshotBundleTotalPrice === null) {
            return '';
        }

        return ' · ' . $this->snapshotBundleQuantity . ' for '
            . MoneyFormatter::format($this->snapshotBundleTotalPrice, $this->product->currency);
    }
}
