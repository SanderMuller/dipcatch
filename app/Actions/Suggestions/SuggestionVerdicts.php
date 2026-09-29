<?php declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopSuggestionVerdict;
use App\Services\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Config;

/**
 * What Jev said about one product's suggested rows, applied to the name
 * match. With the shop check on, a row that matched only loosely shows once
 * Jev confirms it, and a row Jev rejects is dropped however well it matched.
 * Rows Jev has not answered for yet are collected for the next check.
 */
final class SuggestionVerdicts
{
    /** @var list<UncheckedSuggestion> */
    private array $unchecked = [];

    /**
     * @param  array<string, array{fingerprint: string, chance: float}>  $verdicts  Keyed `chain|external_id`.
     */
    private function __construct(
        private readonly bool $checks,
        private readonly float $nameMatchFloor,
        private readonly string $evidence,
        private readonly array $verdicts,
    ) {}

    public static function off(float $nameMatchFloor): self
    {
        return new self(false, $nameMatchFloor, '', []);
    }

    public static function for(Product $product, float $nameMatchFloor): self
    {
        $verdicts = [];

        foreach (ShopSuggestionVerdict::query()->where('product_id', $product->id)->get(['chain', 'external_id', 'fingerprint', 'same_chance']) as $verdict) {
            $verdicts["{$verdict->chain}|{$verdict->external_id}"] = ['fingerprint' => $verdict->fingerprint, 'chance' => $verdict->same_chance];
        }

        return new self(true, $nameMatchFloor, self::evidence($product), $verdicts);
    }

    /**
     * What Jev is shown for one row, so a changed title, tracked pack or row
     * voids its answer.
     */
    public static function fingerprint(string $evidence, string $rowName, ?string $rowSize): string
    {
        return hash('sha256', mb_strtolower("{$evidence}|{$rowName}|{$rowSize}"));
    }

    /**
     * The product side of the fingerprint: its title, tracked packs, and each
     * tracked shop's page and barcode. Not the prices, which move daily.
     */
    public static function evidence(Product $product): string
    {
        $shops = $product->shops
            ->map(static fn (Shop $shop): string => "{$shop->url}#{$shop->gtin}")
            ->sort()
            ->implode(',');

        return $product->title . '|' . implode(',', TypeSafeClient::trackedPackSizes($product)) . '|' . $shops;
    }

    /** The lowest name-match score worth looking at. */
    public function floor(): float
    {
        return $this->checks ? Config::float('dipcatch.shop_checks.loose_match_from') : $this->nameMatchFloor;
    }

    /** Whether the row may show. A row Jev has not answered for is noted for the next check. */
    public function admits(CheckjebonChain $chain, CheckjebonPrice $row, float $score): bool
    {
        $chance = $this->chanceFor($row);

        if ($this->checks && $chance === null) {
            $this->unchecked[] = new UncheckedSuggestion($chain->label, $row->supermarket, $row->external_id, $row->name, $row->size, number_format((float) $row->price, 2, '.', ''), $score);
        }

        if ($chance !== null && $chance < Config::float('dipcatch.shop_checks.reject_below')) {
            return false;
        }

        return $score >= $this->nameMatchFloor || ($chance !== null && $chance >= Config::float('dipcatch.shop_checks.accept_from'));
    }

    /**
     * The rows `admits()` saw that Jev has no answer for yet.
     *
     * @return list<UncheckedSuggestion>
     */
    public function unchecked(): array
    {
        return $this->unchecked;
    }

    /** Whether Jev confirmed the row sells the same product and pack. */
    public function confirmed(CheckjebonPrice $row): bool
    {
        $chance = $this->chanceFor($row);

        return $chance !== null && $chance >= Config::float('dipcatch.shop_checks.accept_from');
    }

    private function chanceFor(CheckjebonPrice $row): ?float
    {
        $verdict = $this->verdicts["{$row->supermarket}|{$row->external_id}"] ?? null;

        if ($verdict === null || $verdict['fingerprint'] !== self::fingerprint($this->evidence, $row->name, $row->size)) {
            return null;
        }

        return $verdict['chance'];
    }
}
