<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Ranks the things a page offers by how precisely each one names the
 * request. The most precise wins, whatever order the document lists them
 * in. Two items at the top score identify nothing between them, so the
 * ranking reports a tie instead of a winner, and the caller asks.
 *
 * @template TItem
 */
final class PrecisionRanking
{
    /** @var TItem|null */
    private mixed $best = null;

    private int $bestPrecision = 0;

    private bool $tied = false;

    /** @var list<TItem> */
    private array $topItems = [];

    /**
     * @param  TItem  $item
     */
    public function offer(mixed $item, int $precision): void
    {
        if ($this->best === null || $precision > $this->bestPrecision) {
            $this->best = $item;
            $this->bestPrecision = $precision;
            $this->tied = false;
            $this->topItems = [$item];

            return;
        }

        if ($precision === $this->bestPrecision) {
            $this->tied = true;
            $this->topItems[] = $item;
        }
    }

    /** True when one item reached the top score alone. */
    public function identified(): bool
    {
        return $this->best !== null && ! $this->tied;
    }

    public function tied(): bool
    {
        return $this->tied;
    }

    /**
     * The item that named the request most precisely, tie or no tie.
     *
     * @return TItem|null
     */
    public function best(): mixed
    {
        return $this->best;
    }

    /**
     * The single most precise item, or null when none reached the top score
     * alone. This is the only accessor a caller can use without a null
     * check of its own, which is why it exists beside {@see self::best()}.
     *
     * @return TItem|null
     */
    public function winner(): mixed
    {
        return $this->identified() ? $this->best : null;
    }

    /** The score the top item reached. Zero when nothing was offered. */
    public function bestPrecision(): int
    {
        return $this->bestPrecision;
    }

    /**
     * Every item that reached the top score.
     *
     * @return list<TItem>
     */
    public function topItems(): array
    {
        return $this->topItems;
    }
}
