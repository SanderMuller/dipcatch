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
 * "Lay's Naturel is €5.38 /kg at lidl.nl" — the product reached the price
 * per unit the shopper asked about.
 *
 * Says the unit price first, because that is what was asked for, and the
 * pack price second, because that is what gets paid.
 */
final class UnitPriceTargetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $snapshotUnitPrice;

    public readonly string $snapshotHost;

    public readonly ?string $snapshotPrice;

    public readonly ?string $snapshotUnitLabel;

    public function __construct(
        public Product $product,
        Shop $shop,
        string $unitPrice,
    ) {
        // Pinned at dispatch: a recheck landing before the queue renders
        // must not swap the numbers under the message.
        $this->snapshotUnitPrice = $unitPrice;
        $this->snapshotHost = $shop->host;
        $this->snapshotPrice = $shop->current_price === null ? null : (string) $shop->current_price;
        $this->snapshotUnitLabel = $shop->unitPriceLabel();

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
            'unit_price' => $this->snapshotUnitPrice,
            'unit_price_label' => $this->snapshotUnitLabel,
            'unit_price_target' => $this->product->unit_price_target === null
                ? null
                : (string) $this->product->unit_price_target,
            'new_price' => $this->snapshotPrice,
            'host' => $this->snapshotHost,
            'view_url' => ProductResource::getUrl('view', ['record' => $this->product]),
        ];
    }

    private function body(): string
    {
        $unit = MoneyFormatter::format($this->snapshotUnitPrice, $this->product->currency)
            . ($this->snapshotUnitLabel === null ? '' : ' ' . $this->snapshotUnitLabel);

        $price = $this->snapshotPrice === null
            ? ''
            : ' (' . MoneyFormatter::format($this->snapshotPrice, $this->product->currency) . ')';

        return $this->product->title . ' is ' . $unit . $price . ' at ' . $this->snapshotHost;
    }
}
