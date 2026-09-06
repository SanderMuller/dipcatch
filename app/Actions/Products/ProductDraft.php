<?php declare(strict_types=1);

namespace App\Actions\Products;

/**
 * The product half of a create. Both thresholds are separate `decimal(_, 2)`
 * columns and the URL-first web path requires both, so neither collapses into
 * one field. They are decimal strings, matching every other price in this
 * codebase — no floats, no cent integers.
 */
final readonly class ProductDraft
{
    public function __construct(
        public string $title,
        public ?string $imageUrl = null,
        public ?string $dropThresholdPct = null,
        public ?string $dropThresholdAbs = null,
    ) {}
}
