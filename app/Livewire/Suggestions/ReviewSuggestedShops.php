<?php declare(strict_types=1);

namespace App\Livewire\Suggestions;

use App\Models\Product;
use App\Models\User;
use App\Support\DashboardSuggestions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dashboard's review dialog for suggested shops. It opens on the
 * suggestion the user picked, then walks the rest of the dashboard's
 * suggestions: the same product's first, then the next product's. Each step
 * is an add-shop form in review mode, which reads the shop's page.
 */
final class ReviewSuggestedShops extends Component
{
    public const string MODAL = 'review-suggested-shops';

    /**
     * The suggestions still to review, the current one first, grouped by
     * product. Locked: each entry is a URL the form reads and a row it may
     * hide, so it comes from the server's own list only.
     *
     * @var list<array{product: string, host: string, shop: string, url: string, findingId: ?int, chain: ?string, externalId: ?string}>
     */
    #[Locked]
    public array $queue = [];

    #[Locked]
    public int $total = 0;

    #[Locked]
    public int $added = 0;

    #[Locked]
    public int $declined = 0;

    #[Locked]
    public int $skipped = 0;

    /** The chosen suggestion was no longer on the dashboard's list. */
    #[Locked]
    public bool $gone = false;

    /**
     * Opens the review on one dashboard suggestion. The order is the
     * dashboard's: products as their cards appear, shops as they appear in
     * each card, starting at the chosen one.
     */
    #[On('review-suggested-shop')]
    public function start(string $productId, string $host, DashboardSuggestions $suggestions): void
    {
        /** @var User $user */
        $user = auth()->user();

        $byProduct = [];

        foreach ($suggestions->forUser($user) as $row) {
            $byProduct[(string) $row['product']->id][] = [
                'product' => (string) $row['product']->id,
                'host' => $row['host'],
                'shop' => $row['shop'],
                'url' => $row['url'],
                'findingId' => $row['findingId'],
                'chain' => $row['chain'],
                'externalId' => $row['externalId'],
            ];
        }

        $chosen = $byProduct[$productId] ?? [];
        $first = array_find_key($chosen, static fn (array $item): bool => $item['host'] === $host);

        if ($first === null) {
            $this->reset();
            $this->gone = true;

            return;
        }

        unset($byProduct[$productId]);

        $this->queue = [...array_slice($chosen, $first), ...array_slice($chosen, 0, $first), ...array_merge(...array_values($byProduct))];
        $this->total = count($this->queue);
        $this->added = 0;
        $this->declined = 0;
        $this->skipped = 0;
        $this->gone = false;
    }

    #[On('shop-added')]
    public function shopAdded(): void
    {
        $this->advance('added');
    }

    #[On('suggestion-reviewed')]
    public function reviewed(string $outcome): void
    {
        $this->advance($outcome === 'declined' ? 'declined' : 'skipped');
    }

    /**
     * The dialog closed. The dashboard list draws again only when a shop was
     * added or a suggestion hidden: drawing it matches the catalogue again.
     */
    public function finish(): void
    {
        if ($this->added + $this->declined > 0) {
            $this->dispatch('suggested-shops-reviewed');
        }

        $this->reset();
    }

    public function render(): View
    {
        $current = $this->queue[0] ?? null;

        return view('livewire.suggestions.review-suggested-shops', [
            'current' => $current,
            'product' => $current === null ? null : $this->product($current['product']),
            'position' => $this->total - count($this->queue) + 1,
        ]);
    }

    private function advance(string $outcome): void
    {
        if ($this->queue === []) {
            return;
        }

        match ($outcome) {
            'added' => $this->added++,
            'declined' => $this->declined++,
            default => $this->skipped++,
        };

        array_shift($this->queue);

        // A product deleted or paused since the dialog opened has nothing to review.
        while ($this->queue !== [] && ! $this->product($this->queue[0]['product']) instanceof Product) {
            array_shift($this->queue);
        }
    }

    /** The owner's own active product only, whatever the queue names. */
    private function product(string $id): ?Product
    {
        return Product::query()
            ->with(['shops', 'cheapestShop'])
            ->whereKey($id)
            ->where('user_id', auth()->id())
            ->where('active', true)
            ->first();
    }
}
