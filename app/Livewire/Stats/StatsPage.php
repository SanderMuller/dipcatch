<?php declare(strict_types=1);

namespace App\Livewire\Stats;

use App\Charts\SavingsByMonthSeries;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\PriceAdapters\BundleOffer;
use App\Support\BundlePriceLabel;
use App\Support\MoneyFormatter;
use App\Support\UnitWord;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The numbers behind the alerts, off the dashboard.
 *
 * The dashboard answers "what is happening now". A twelve-month chart answers
 * a question nobody asks on arrival, so it lives here instead.
 */
#[Title('Stats')]
final class StatsPage extends Component
{
    public function render(): View
    {
        $savings = new SavingsByMonthSeries($this->user());

        return view('livewire.stats.stats-page', [
            'savings' => $savings->hasData() ? $savings->fluxChart() : null,
            'recentAlerts' => $this->recentAlerts(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * The last alerts this account was sent, whether or not they are still
     * unread. The bell clears itself on open, so without this a drop that
     * already happened is unreachable.
     *
     * @return Collection<int, array{title: string, url: ?string, percent: ?string, amount: ?string, bundle: ?string, sentAt: ?string}>
     */
    private function recentAlerts(): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $this->user()->id)
            // Price alerts only. A billing incident and a test notification
            // also land in this table, and neither carries a product: listed
            // here they would render as an empty row under "Recent alerts".
            ->whereIn('type', [
                PriceDropNotification::class,
                TargetPriceNotification::class,
                UnitPriceTargetNotification::class,
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                /** @var array<string, mixed> $data */
                $data = $notification->data;

                $productId = is_string($data['product_id'] ?? null) ? $data['product_id'] : null;
                $currency = is_string($data['currency'] ?? null) ? $data['currency'] : 'EUR';
                $rawPercent = $data['drop_percent'] ?? null;
                $unit = is_string($data['comparison_unit'] ?? null) ? $data['comparison_unit'] : null;
                $amount = $data['drop_absolute'] ?? null;

                $percent = self::percentage($rawPercent);

                // Read through the same gate the bell uses, so one stored row
                // cannot claim a bundle on this page and be refused on that
                // one. `stored()` drops an offer that is not cheaper per item
                // than the single price beside it. The short condition, not
                // the full label: this row is one inline clause.
                $single = $data['single_item_price'] ?? null;
                $offer = BundleOffer::stored(
                    $data['bundle_quantity'] ?? null,
                    $data['bundle_total_price'] ?? null,
                    is_string($single) ? $single : null,
                );
                $bundle = $offer === null ? null : BundlePriceLabel::condition($offer, $currency);

                return [
                    'title' => is_string($data['title'] ?? null) ? $data['title'] : '—',
                    'url' => $productId === null ? null : route('app.products.show', $productId),
                    // Both bases named. The percentage was measured per kilo,
                    // litre or piece whenever the product has a comparison
                    // unit, and the money is off the pack — and is absent when
                    // the two packs differed, because then there is none.
                    'percent' => $percent === null ? null : trim($percent . ' ' . (UnitWord::forCode($unit) ?? '')),
                    'amount' => is_numeric($amount) ? MoneyFormatter::format((string) $amount, $currency) : null,
                    'bundle' => $bundle,
                    'sentAt' => $notification->created_at?->diffForHumans(),
                ];
            })
            ->values();
    }

    /** One place decides how a drop percentage reads, and its type. */
    private static function percentage(mixed $value): ?string
    {
        return is_numeric($value)
            ? number_format((float) $value, 1, '.', '') . '%'
            : null;
    }
}
