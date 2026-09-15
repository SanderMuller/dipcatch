<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Mutable accumulator for the weakest-candidate Product / Shop /
 * ProductGroup encountered during JSON-LD entity scanning.
 */
final class JsonLdSearchState
{
    /** @var array<string, mixed>|null */
    public ?array $product = null;

    /** @var array<string, mixed>|null */
    public ?array $shop = null;

    /** @var array<string, mixed>|null */
    public ?array $productGroup = null;

    /** @var list<VariantCandidate> Built from `hasVariant` entries; surfaces the chooser when >1 and none URL-match. */
    public array $variants = [];

    /**
     * The entities that identified the request, ranked by how precisely
     * each one names it.
     *
     * @var PrecisionRanking<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private readonly PrecisionRanking $ranking;

    public function __construct()
    {
        $this->ranking = new PrecisionRanking();
    }

    /**
     * True once some entity or offer answered to the caller's `variant_key`.
     * A key that matches nothing must be reported, never quietly replaced by
     * a URL match on the page itself.
     */
    public bool $keyMatched = false;

    /**
     * A Product whose URL names the page but not a variant of it. Held
     * rather than returned, so the entity that comes later in the document
     * still gets its turn — otherwise document order picks the price.
     *
     * @var array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public ?array $namesPageOnly = null;

    /**
     * Final fallback after the scripts loop: if no Product was found but a
     * ProductGroup was, use the group's top-level AggregateOffer (lowPrice)
     * as the offer so we still return something usable.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    /**
     * Offer an entity that identifies the request. The most precise one
     * wins, whatever order the document lists them in.
     *
     * @param  array{0: array<string, mixed>, 1: array<string, mixed>}  $match
     */
    public function offer(array $match, int $precision): void
    {
        $this->ranking->offer($match, $precision);
    }

    /** True when one entity identified the request more precisely than any other. */
    public function identified(): bool
    {
        return $this->ranking->identified();
    }

    /** True when two entities tied on precision, so neither identifies anything. */
    public function tied(): bool
    {
        return $this->ranking->tied();
    }

    /**
     * Every entity that reached the top precision. More than one means the
     * page does not say which of them the request asked for, so they are
     * what the chooser offers.
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public function topMatches(): array
    {
        return $this->ranking->topItems();
    }

    /**
     * What the scan settled on: the entity that identified the request,
     * else the weakest usable Product / ProductGroup pair — a group's own
     * AggregateOffer stands in when no variant supplied an offer.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public function fallback(): array
    {
        $identified = $this->ranking->best() ?? $this->namesPageOnly;

        if ($identified !== null) {
            return [$identified[0], $identified[1]];
        }

        $product = $this->product ?? $this->productGroup;
        $shop = $this->shop;

        if ($shop === null && $this->productGroup !== null && isset($this->productGroup['offers'])) {
            $shop = JsonLdEntities::pickOfferFromProduct($this->productGroup['offers']);
        }

        return [$product, $shop];
    }
}
