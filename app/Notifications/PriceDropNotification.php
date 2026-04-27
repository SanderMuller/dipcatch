<?php declare(strict_types=1);

namespace App\Notifications;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Drops\DropOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Channels (mail / database / web push) are wired up in the notifications.md
 * spec. This stub exists so drop-detection can dispatch the notification.
 */
final class PriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Product $product,
        public DropOutcome $outcome,
        public string $priceDropEventId,
    ) {
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

        if ($notifiable->notify_via_email) {
            $channels[] = 'mail';
        }
        if ($notifiable->notify_via_filament) {
            $channels[] = 'database';
        }
        if ($notifiable->notify_via_push && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $newPrice = (string) ($this->product->last_price ?? '0.00');
        $price = $this->product->currency . ' ' . $newPrice;

        return new MailMessage()
            ->subject('Price drop on ' . $this->product->title . ': ' . $price)
            ->markdown('notifications.price-drop', [
                'product' => $this->product,
                'newPrice' => $newPrice,
                'referencePrice' => $this->outcome->referencePrice,
                'referenceKind' => $this->outcome->referenceKind,
                'dropPercent' => $this->outcome->dropPercent,
                'dropAbsolute' => $this->outcome->dropAbsolute,
                'viewUrl' => ProductResource::getUrl('view', ['record' => $this->product]),
            ]);
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $newPrice = $this->product->currency . ' ' . ($this->product->last_price ?? '0.00');

        return new WebPushMessage()
            ->title('Price drop: ' . $this->product->title)
            ->body($this->product->title . ' is now ' . $newPrice)
            ->icon($this->product->image_url ?? '/favicon.svg')
            ->data(['url' => ProductResource::getUrl('view', ['record' => $this->product])]);
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
            'new_price' => $this->product->last_price,
            'reference_price' => $this->outcome->referencePrice,
            'reference_kind' => $this->outcome->referenceKind,
            'drop_percent' => $this->outcome->dropPercent,
            'drop_absolute' => $this->outcome->dropAbsolute,
            'view_url' => ProductResource::getUrl('view', ['record' => $this->product]),
        ];
    }
}
