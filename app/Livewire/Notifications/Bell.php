<?php declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\User;
use App\Notifications\TargetPriceNotification;
use App\Support\AlsoWorthChecking;
use App\Support\BundlePriceLabel;
use App\Support\MoneyFormatter;
use App\Support\Numeric;
use App\Support\PackLine;
use App\Support\PackSize;
use App\Support\UnitWord;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The in-app notification bell.
 *
 * Built rather than ported: Filament's panel bell lists only rows whose payload
 * carries `data->format = 'filament'`, and this app writes none, so the bell has
 * never displayed a price drop despite the settings page offering it. See the
 * spec's Findings.
 *
 * Payloads differ per notification type — a price drop carries `view_url`,
 * `new_price` and `host`, a billing incident carries `kind` and `body` and no
 * link at all — so this renders the common denominator and treats every other
 * key as optional.
 */
final class Bell extends Component
{
    /** How many rows the dropdown shows. Older ones live on the notifications page. */
    private const int LIMIT = 10;

    public function markAsRead(string $id): void
    {
        $this->notificationQuery()
            ->where('id', $id)
            ->get()
            ->each(fn (DatabaseNotification $notification) => $notification->markAsRead());
    }

    public function markAllAsRead(): void
    {
        $this->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.notifications.bell', [
            'unreadCount' => $this->unreadCount(),
            'items' => $this->items(),
        ]);
    }

    private function unreadCount(): int
    {
        return $this->user()?->unreadNotifications()->count() ?? 0;
    }

    /**
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    private function items(): Collection
    {
        return $this->notificationQuery()
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'unread' => $notification->read_at === null,
                'at' => $notification->created_at,
                ...$this->present($notification),
            ]);
    }

    /**
     * The link shops a payload carried, if any — written by every alert since
     * the reference-shop change, and absent from anything sent before it.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{host: string, url: string}>
     */
    private static function shops(array $data): array
    {
        $shops = $data['also_check'] ?? null;

        if (! is_array($shops)) {
            return [];
        }

        $rows = [];

        foreach ($shops as $shop) {
            if (is_array($shop) && is_string($shop['host'] ?? null) && is_string($shop['url'] ?? null)) {
                $rows[] = ['host' => $shop['host'], 'url' => $shop['url']];
            }
        }

        return $rows;
    }

    /**
     * The fields the dropdown renders, defaulting anything a given
     * notification type does not carry.
     *
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'title' => $this->text($data, 'title') ?? 'Notification',
            'body' => $this->text($data, 'body'),
            'url' => $this->text($data, 'view_url'),
            'host' => $this->text($data, 'host'),
            'price' => $this->text($data, 'new_price'),
            ...self::unitFigures($data, leadsWithPack: $notification->type === TargetPriceNotification::class),
            'currency' => $this->text($data, 'currency'),
            'singleItemPrice' => $this->text($data, 'single_item_price'),
            // One label for the whole bundle line. This applies the same
            // cheaper-than test the payload was written under, so a row that
            // fails it renders no bundle line at all.
            'bundleLabel' => BundlePriceLabel::forAlert($data),
            // The shops DipCatch cannot read, named where the reader is about
            // to open a tab anyway.
            'alsoCheck' => AlsoWorthChecking::line(self::shops($data)),
        ];
    }

    /**
     * The per-unit side of an alert, from whatever the payload stored.
     *
     * A drop names its unit price as `new_unit_price` beside `comparison_unit`;
     * a unit-target alert as `unit_price` beside `unit`, or beside the label it
     * stored before it stored the code. A target-price alert fired on a pack
     * amount, so it leads with the pack price and puts the unit price beside it.
     *
     * @param  array<string, mixed>  $data
     * @return array{unitFigure: ?string, leadsWithUnit: bool, pack: ?string, betterValue: ?string, change: ?string, target: ?string}
     */
    private static function unitFigures(array $data, bool $leadsWithPack): array
    {
        $text = static fn (string $key): ?string => is_scalar($data[$key] ?? null) && (string) $data[$key] !== '' ? (string) $data[$key] : null;
        $currency = $text('currency') ?? 'EUR';
        $unitCode = $text('comparison_unit') ?? $text('unit');
        $unitPrice = $text('new_unit_price') ?? $text('unit_price');
        $label = $unitCode === null ? $text('unit_price_label') : UnitWord::labelFor($unitCode);
        $unitFigure = $unitPrice === null || $label === null ? null : MoneyFormatter::unitPrice($unitPrice, $currency) . ' ' . $label;
        $quantity = $text('pack_quantity');
        $packUnit = $text('pack_unit');
        $size = $quantity === null || $packUnit === null ? null : PackSize::of((float) $quantity, $packUnit);
        $betterHost = $text('better_value_host');
        $betterPrice = $text('better_value_unit_price');
        $betterValue = $betterHost === null || $betterPrice === null || $label === null
            ? null
            : __('Better value: :price at :host', ['price' => MoneyFormatter::unitPrice($betterPrice, $currency) . ' ' . $label, 'host' => $betterHost]);

        $percent = $text('drop_percent');
        $referenceUnit = $text('reference_unit_price');
        $unitWord = UnitWord::forCode($unitCode);
        $change = $percent === null || ! is_numeric($percent) ? null : '↓ ' . Numeric::trimmed(number_format((float) $percent, 1, '.', '')) . '%'
            . ($unitWord === null ? '' : ' ' . $unitWord)
            . ($referenceUnit === null || $label === null ? '' : ' · ' . __('was') . ' ' . MoneyFormatter::unitPrice($referenceUnit, $currency) . ' ' . $label);
        $target = $leadsWithPack && $text('target_price') !== null
            ? __('Your target: :price', ['price' => MoneyFormatter::format($text('target_price'), $currency)])
            : null;

        return [
            'change' => $change,
            'target' => is_string($target) ? $target : null,
            'unitFigure' => $unitFigure,
            'leadsWithUnit' => $unitFigure !== null && ! $leadsWithPack,
            'pack' => $text('new_price') === null ? null : PackLine::format($text('new_price'), $currency, $size),
            'betterValue' => is_string($betterValue) ? $betterValue : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    private function notificationQuery(): MorphMany
    {
        $user = $this->user();

        abort_if($user === null, 403);

        return $user->notifications();
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
