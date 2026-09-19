<?php declare(strict_types=1);

namespace App\Livewire\Stats;

use App\Charts\SavingsByMonthSeries;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use App\Notifications\UnitPriceTargetNotification;
use App\Support\MoneyFormatter;
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
     * @return Collection<int, array{title: string, url: ?string, percent: ?string, amount: ?string, bundle: non-falsy-string|null, sentAt: ?string}>
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
                $amount = $data['drop_absolute'] ?? null;

                $percent = self::percentage($rawPercent);
                $bundle = null;

                if (is_int($data['bundle_quantity'] ?? null) && is_numeric($data['bundle_total_price'] ?? null)) {
                    $translatedBundle = __(':quantity for :total', [
                        'quantity' => $data['bundle_quantity'],
                        'total' => MoneyFormatter::format((string) $data['bundle_total_price'], $currency),
                    ]);

                    $bundle = is_string($translatedBundle) && $translatedBundle !== '' && $translatedBundle !== '0'
                        ? $translatedBundle
                        : null;
                }

                return [
                    'title' => is_string($data['title'] ?? null) ? $data['title'] : '—',
                    'url' => $productId === null ? null : route('app.products.show', $productId),
                    'percent' => $percent,
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
