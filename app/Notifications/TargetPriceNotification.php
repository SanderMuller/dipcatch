<?php declare(strict_types=1);

namespace App\Notifications;

use App\Filament\App\Resources\Products\ProductResource;
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

    public readonly string $snapshotPrice;

    public readonly string $snapshotHost;

    public function __construct(
        public Product $product,
        Shop $shop,
        string $price,
    ) {
        // Pinned at dispatch: a recheck landing before the queue renders
        // must not swap the numbers under the message.
        $this->snapshotPrice = $price;
        $this->snapshotHost = $shop->host;

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
            ->data(['url' => ProductResource::getUrl('view', ['record' => $this->product])]);
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
            'target_price' => $this->product->target_price === null
                ? null
                : (string) $this->product->target_price,
            'host' => $this->snapshotHost,
            'view_url' => ProductResource::getUrl('view', ['record' => $this->product]),
        ];
    }

    private function body(): string
    {
        return $this->product->title
            . ' is ' . MoneyFormatter::format($this->snapshotPrice, $this->product->currency)
            . ' at ' . $this->snapshotHost;
    }
}
