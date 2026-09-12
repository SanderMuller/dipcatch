<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Lay's Naturel is €5.38 /kg at lidl.nl" — the product reached the price
 * per unit the shopper asked about.
 *
 * Says the unit price first, because that is what was asked for, and the
 * pack price second, because that is what gets paid.
 */
final class UnitPriceTargetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $snapshotHost;

    public readonly ?string $snapshotPrice;

    public readonly ?string $snapshotUnitLabel;

    public readonly ?string $snapshotSingleItemPrice;

    public readonly ?int $snapshotBundleQuantity;

    public readonly ?string $snapshotBundleTotalPrice;

    public function __construct(
        public Product $product,
        Shop $shop,
        public readonly string $snapshotUnitPrice,
    ) {
        $this->snapshotHost = $shop->host;
        $this->snapshotPrice = $shop->current_price === null ? null : (string) $shop->current_price;
        $this->snapshotUnitLabel = $shop->unitPriceLabel();
        $bundle = $shop->liveBundleOffer();
        $this->snapshotSingleItemPrice = $bundle === null ? null : $shop->singleItemPrice();
        $this->snapshotBundleQuantity = $bundle?->quantity;
        $this->snapshotBundleTotalPrice = $bundle?->totalPrice;

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
        return new WebPushMessage()
            ->title('Unit price target: ' . $this->product->title)
            ->body($this->body())
            ->icon($this->product->image_url ?? '/favicon.svg')
            ->data(['url' => route('app.products.show', $this->product)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'title' => $this->product->title,
            'image_url' => $this->product->image_url,
            'currency' => $this->product->currency,
            'unit_price' => $this->snapshotUnitPrice,
            'unit_price_label' => $this->snapshotUnitLabel,
            'unit_price_target' => $this->product->unit_price_target === null
                ? null
                : (string) $this->product->unit_price_target,
            'new_price' => $this->snapshotPrice,
            'single_item_price' => $this->snapshotSingleItemPrice,
            'bundle_quantity' => $this->snapshotBundleQuantity,
            'bundle_total_price' => $this->snapshotBundleTotalPrice,
            'host' => $this->snapshotHost,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    private function body(): string
    {
        $unit = MoneyFormatter::format($this->snapshotUnitPrice, $this->product->currency)
            . ($this->snapshotUnitLabel === null ? '' : ' ' . $this->snapshotUnitLabel);

        $price = $this->snapshotPrice === null
            ? ''
            : ' (' . MoneyFormatter::format($this->snapshotPrice, $this->product->currency) . ')';

        $bundle = $this->snapshotBundleQuantity === null || $this->snapshotBundleTotalPrice === null
            ? ''
            : ' · ' . $this->snapshotBundleQuantity . ' for '
                . MoneyFormatter::format($this->snapshotBundleTotalPrice, $this->product->currency);

        return $this->product->title . ' is ' . $unit . $price . $bundle . ' at ' . $this->snapshotHost;
    }
}
