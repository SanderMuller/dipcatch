<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Widgets;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\HistoryWindow;
use App\Billing\Plan;
use App\Charts\PriceHistorySeries;
use App\Models\Product;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class PriceHistoryChart extends ChartWidget
{
    protected ?string $heading = 'Cheapest price history';

    protected int|string|array $columnSpan = 'full';

    // Enough to read the shape of the line without pushing the shops off
    // the page.
    protected ?string $maxHeight = '260px';

    public ?Product $record = null;

    public ?string $filter = '90';

    /**
     * The ranges this account may read. The menu tells the truth, but it is
     * not the enforcement point — `$filter` is public, so its value arrives
     * from the client. `windowStart()` clamps whatever turns up.
     *
     * @return array<int|string, bool|float|int|string>|null
     */
    protected function getFilters(): ?array
    {
        return HistoryWindow::filters($this->historyDays());
    }

    /**
     * Says why the long ranges are missing, and offers the way to them only
     * when there is something to buy.
     */
    public function getDescription(): string|Htmlable|null
    {
        $maxDays = $this->historyDays();

        if ($maxDays === null) {
            return null;
        }

        $reason = "Your plan shows the last {$maxDays} days. Pro shows the full history.";

        if (! BillingGate::isOpen()) {
            return $reason;
        }

        // Rendered through Filament's own link component: hand-written
        // `fi-link` classes carry none of the colour custom properties, so
        // the link came out looking like the sentence around it.
        return new HtmlString(view('filament.partials.history-depth-notice', [
            'reason' => $reason,
            'billingUrl' => url('/app/billing'),
        ])->render());
    }

    /**
     * Null when the account may read everything.
     *
     * An unknown owner falls back to the free ceiling rather than to
     * unlimited: a gate that opens when it cannot identify who is asking is
     * not a gate.
     */
    private function historyDays(): ?int
    {
        $user = $this->record?->user;

        return $user === null
            ? Entitlements::of(Plan::Free)->historyDays()
            : $user->entitlements()->historyDays();
    }

    protected function getData(): array
    {
        return $this->computeData();
    }

    /**
     * A thin seam over {@see PriceHistorySeries}. The computation moved out of
     * this panel so it survives the panel's deletion; the widget only hosts it.
     *
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    public function computeData(): array
    {
        $product = $this->record;

        if (! $product instanceof Product) {
            return ['datasets' => [], 'labels' => []];
        }

        return (new PriceHistorySeries($product, $this->filter))->data();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): RawJs
    {
        return PriceHistoryChartOptions::forProduct($this->record);
    }
}
