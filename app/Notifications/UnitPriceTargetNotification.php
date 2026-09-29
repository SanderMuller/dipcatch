<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\BundleOffer;
use App\Support\AlsoWorthChecking;
use App\Support\BundlePriceLabel;
use App\Support\MoneyFormatter;
use App\Support\PackLine;
use App\Support\PackSize;
use App\Support\UnitWord;
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
    use RestoresQueuedBundleSnapshot;

    public readonly string $snapshotHost;

    public readonly ?string $snapshotPrice;

    public readonly ?string $snapshotUnitLabel;

    public readonly ?string $snapshotSingleItemPrice;

    public readonly ?BundleOffer $snapshotBundle;

    /** The unit code, so the label follows the reader's language. */
    public readonly ?string $snapshotUnit;

    public readonly ?string $snapshotPackQuantity;

    public readonly ?string $snapshotPackUnit;

    public function __construct(
        public Product $product,
        Shop $shop,
        public readonly string $snapshotUnitPrice,
    ) {
        $this->snapshotHost = $shop->host;
        $this->snapshotPrice = $shop->current_price === null ? null : (string) $shop->current_price;
        $this->snapshotUnitLabel = $shop->unitPriceLabel();
        $packSize = $shop->packSize();
        $this->snapshotUnit = $packSize?->unit;
        $this->snapshotPackQuantity = $packSize === null ? null : (string) $packSize->quantity;
        $this->snapshotPackUnit = $packSize?->unit;
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
            ->title('Target price per kilo, litre or piece: ' . $this->product->title)
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
            'unit_price' => $this->snapshotUnitPrice,
            'unit_price_label' => $this->snapshotUnitLabel,
            'unit' => $this->snapshotUnit,
            'pack_quantity' => $this->snapshotPackQuantity,
            'pack_unit' => $this->snapshotPackUnit,
            'unit_price_target' => $this->product->unit_price_target === null
                ? null
                : (string) $this->product->unit_price_target,
            'new_price' => $this->snapshotPrice,
            'single_item_price' => $this->snapshotSingleItemPrice,
            'bundle_quantity' => $this->snapshotBundle?->quantity,
            'bundle_total_price' => $this->snapshotBundle?->totalPrice,
            'host' => $this->snapshotHost,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    private function body(): string
    {
        $label = $this->snapshotUnit === null ? $this->snapshotUnitLabel : UnitWord::labelFor($this->snapshotUnit);
        $unit = MoneyFormatter::unitPrice($this->snapshotUnitPrice, $this->product->currency)
            . ($label === null ? '' : ' ' . $label);
        $packSize = $this->snapshotPackQuantity === null || $this->snapshotPackUnit === null
            ? null
            : PackSize::of((float) $this->snapshotPackQuantity, $this->snapshotPackUnit);

        $bundle = BundlePriceLabel::suffix($this->snapshotBundle, $this->product->currency);
        $price = $this->snapshotPrice === null
            ? ''
            : ' (' . PackLine::format($this->snapshotPrice, $this->product->currency, $packSize) . $bundle . ')';

        return $this->product->title . ' is ' . $unit . $price . ' at ' . $this->snapshotHost;
    }
}
