<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\BundleOffer;
use App\Support\AlsoWorthChecking;
use App\Support\BundlePriceLabel;
use App\Support\HeadlinePrice;
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

    /** The firing shop's price per unit, beside the pack price that fired. */
    public readonly ?string $snapshotUnitPrice;

    public readonly ?string $snapshotUnit;

    public readonly ?string $snapshotPackQuantity;

    public readonly ?string $snapshotPackUnit;

    /** Another shop that is the better buy per unit, when there is one. */
    public readonly ?string $snapshotBetterValueHost;

    public readonly ?string $snapshotBetterValueUnitPrice;

    /** The target as it stood when it was reached, not when the push is rendered. */
    public readonly ?string $snapshotTargetPrice;

    public function __construct(
        public Product $product,
        Shop $shop,
        public readonly string $snapshotPrice,
    ) {
        $this->snapshotHost = $shop->host;
        $this->snapshotBundle = $shop->liveBundleOffer();
        $this->snapshotSingleItemPrice = $this->snapshotBundle === null ? null : $shop->singleItemPrice();

        // The target is a pack amount and fired on the lowest pack price, of
        // any size. Its price per unit goes beside it, and a shop that is the
        // better buy per unit is named: a small pack can be the cheapest to buy
        // and the dearest per kilo.
        $headline = HeadlinePrice::of($product);
        $packSize = $headline->packs->for($shop)->size ?? $shop->packSize();
        $betterValue = $headline->isPerUnit() && $headline->shop?->isNot($shop) === true ? $headline->shop : null;
        // A figure per unit only on a size the shop's own page states, while
        // the product compares per unit at all: an estimated size is not a fact.
        $comparable = $headline->isPerUnit() && $headline->packs->for($shop)?->canWin() === true;
        // The unit also names the better-value figure, so it is kept whenever
        // the product compares per unit.
        $this->snapshotUnit = $headline->unit;
        $this->snapshotUnitPrice = $comparable ? $headline->packs->unitPriceOf($shop) : null;
        $this->snapshotTargetPrice = $product->target_price === null ? null : (string) $product->target_price;
        $this->snapshotPackQuantity = $packSize === null ? null : (string) $packSize->quantity;
        $this->snapshotPackUnit = $packSize?->unit;
        $this->snapshotBetterValueHost = $betterValue?->host;
        $this->snapshotBetterValueUnitPrice = $betterValue === null ? null : $headline->packs->unitPriceOf($betterValue);

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
            'target_price' => $this->targetPrice(),
            'host' => $this->snapshotHost,
            'unit_price' => $this->snapshotUnitPrice,
            'unit' => $this->snapshotUnit,
            'pack_quantity' => $this->snapshotPackQuantity,
            'pack_unit' => $this->snapshotPackUnit,
            'better_value_host' => $this->snapshotBetterValueHost,
            'better_value_unit_price' => $this->snapshotBetterValueUnitPrice,
            'view_url' => route('app.products.show', $this->product),
        ];
    }

    /**
     * The pack price that reached the target leads, because that is the figure
     * the target is set in: "Lays is €1.69 at ah.nl · your target €1.75 ·
     * €8.45 /kg · better value €5.38 /kg at lidl.nl".
     */
    private function body(): string
    {
        $currency = $this->product->currency;
        $packSize = $this->snapshotPackQuantity === null || $this->snapshotPackUnit === null
            ? null
            : PackSize::of((float) $this->snapshotPackQuantity, $this->snapshotPackUnit);
        $label = UnitWord::labelFor($this->snapshotUnit);

        $body = $this->product->title
            . ' is ' . PackLine::format($this->snapshotPrice, $currency, $packSize)
            . BundlePriceLabel::suffix($this->snapshotBundle, $currency)
            . ' at ' . $this->snapshotHost;

        if ($this->targetPrice() !== null) {
            $body .= ' · your target ' . MoneyFormatter::format($this->targetPrice(), $currency);
        }

        if ($this->snapshotUnitPrice !== null && $label !== '') {
            $body .= ' · ' . MoneyFormatter::unitPrice($this->snapshotUnitPrice, $currency) . ' ' . $label;
        }

        if ($this->snapshotBetterValueHost !== null && $this->snapshotBetterValueUnitPrice !== null) {
            $body .= ' · better value ' . MoneyFormatter::unitPrice($this->snapshotBetterValueUnitPrice, $currency) . ' ' . $label
                . ' at ' . $this->snapshotBetterValueHost;
        }

        return $body;
    }

    /** A payload queued before the target was snapshotted reads the product's current one. */
    private function targetPrice(): ?string
    {
        return $this->snapshotTargetPrice ?? ($this->product->target_price === null ? null : (string) $this->product->target_price);
    }
}
