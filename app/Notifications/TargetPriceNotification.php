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
 * "Sanimed Skin Sensitive is €17.05 at dierenapotheek.nl" — the product
 * reached the price the shopper asked about.
 */
final class TargetPriceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $snapshotHost;

    public readonly ?string $snapshotSingleItemPrice;

    public readonly ?int $snapshotBundleQuantity;

    public readonly ?string $snapshotBundleTotalPrice;

    public function __construct(
        public Product $product,
        Shop $shop,
        public readonly string $snapshotPrice,
    ) {
        $this->snapshotHost = $shop->host;
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
            ->title('Target price: ' . $this->product->title)
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
            'new_price' => $this->snapshotPrice,
            'single_item_price' => $this->snapshotSingleItemPrice,
            'bundle_quantity' => $this->snapshotBundleQuantity,
            'bundle_total_price' => $this->snapshotBundleTotalPrice,
            'target_price' => $this->product->target_price === null
                ? null
                : (string) $this->product->target_price,
            'host' => $this->snapshotHost,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    private function body(): string
    {
        return $this->product->title
            . ' is ' . MoneyFormatter::format($this->snapshotPrice, $this->product->currency)
            . $this->bundleSuffix()
            . ' at ' . $this->snapshotHost;
    }

    private function bundleSuffix(): string
    {
        if ($this->snapshotBundleQuantity === null || $this->snapshotBundleTotalPrice === null) {
            return '';
        }

        return ' · ' . __(':quantity for :total', [
            'quantity' => $this->snapshotBundleQuantity,
            'total' => MoneyFormatter::format($this->snapshotBundleTotalPrice, $this->product->currency),
        ]);
    }
}
