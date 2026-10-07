<?php declare(strict_types=1);

namespace App\Livewire\Products;

use App\Billing\HistoryWindow;
use App\Billing\ProPitch;
use App\Charts\PriceChangeAction;
use App\Charts\PriceChangeLog;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The lowest-price changes under the product chart, and what DipCatch did
 * about each. Pro only. Collapsed until opened, so a closed list does not
 * build the log.
 */
final class PriceChanges extends Component
{
    private const int PAGE = 10;

    public Product $product;

    #[Reactive]
    public string $range = '90';

    public bool $open = false;

    public int $limit = self::PAGE;

    public function mount(Product $product, string $range): void
    {
        $this->authorize('view', $product);

        $this->product = $product;
        $this->range = $range;
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function render(): View
    {
        $owner = $this->product->user;
        $allowed = $owner instanceof User && $owner->entitlements()->allowsPriceChanges();
        $rows = $this->open && $allowed ? $this->rows($owner) : [];

        return view('livewire.products.price-changes', [
            'allowed' => $allowed,
            'rows' => array_slice($rows, 0, $this->limit),
            'hasMore' => count($rows) > $this->limit,
            'timezone' => $owner instanceof User && $owner->timezone !== '' ? $owner->timezone : 'Europe/Amsterdam',
            'canBuyPro' => ProPitch::for($owner)?->canBuy === true,
        ]);
    }

    /**
     * @return list<array{at: CarbonImmutable, shop: ?string, from: ?string, to: ?string, unit: ?string, changePct: ?int, action: ?PriceChangeAction, actionShop: ?string}>
     */
    private function rows(User $owner): array
    {
        return new PriceChangeLog($this->product, HistoryWindow::start($owner->entitlements()->historyDays(), $this->range))->rows();
    }
}
