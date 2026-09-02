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
     * True once an entity identified the requested page or variant. The
     * variants collected on the way there are not an open question.
     */
    public bool $matched = false;

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
    public function fallback(): array
    {
        if ($this->namesPageOnly !== null) {
            return $this->namesPageOnly;
        }

        $product = $this->product ?? $this->productGroup;
        $shop = $this->shop;

        if ($shop === null && $this->productGroup !== null && isset($this->productGroup['offers'])) {
            $shop = JsonLdEntities::pickOfferFromProduct($this->productGroup['offers']);
        }

        return [$product, $shop];
    }
}
