<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\BundleOffer;
use App\Support\AlsoWorthChecking;
use App\Support\BundlePriceLabel;
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
    use RestoresQueuedBundleSnapshot;

    public readonly string $snapshotHost;

    public readonly ?string $snapshotSingleItemPrice;

    public readonly ?BundleOffer $snapshotBundle;

    public function __construct(
        public Product $product,
        Shop $shop,
        public readonly string $snapshotPrice,
    ) {
        $this->snapshotHost = $shop->host;
        $this->snapshotBundle = $shop->liveBundleOffer();
        $this->snapshotSingleItemPrice = $this->snapshotBundle === null ? null : $shop->singleItemPrice();

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
            ->icon($this->product->safeImageUrl() ?? '/favicon.svg')
            ->data(['url' => route('app.products.show', $this->product)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            // The shops this product holds as links. An alert is the moment
            // the reader opens a tab anyway, and these are the ones DipCatch
            // cannot read — often the largest retailers, running the biggest
            // promotions. Hosts, never prices: a link holds no figure.
            'also_check' => AlsoWorthChecking::of($this->product),
            'title' => $this->product->title,
            'image_url' => $this->product->image_url,
            'currency' => $this->product->currency,
            'new_price' => $this->snapshotPrice,
            'single_item_price' => $this->snapshotSingleItemPrice,
            'bundle_quantity' => $this->snapshotBundle?->quantity,
            'bundle_total_price' => $this->snapshotBundle?->totalPrice,
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
            . BundlePriceLabel::suffix($this->snapshotBundle, $this->product->currency)
            . ' at ' . $this->snapshotHost;
    }
}
