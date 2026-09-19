<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Enums\ProductCategory;

/**
 * One categorisation answer. `category` is null when the guards said no;
 * the scored fields are filled either way so a dry run can print them.
 */
final readonly class CategoryVerdict
{
    public function __construct(
        public ?ProductCategory $category,
        public ?ProductCategory $winner,
        public ?ProductCategory $runnerUp,
        public float $pathScore,
        public float $separation,
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    public function stored(): bool
    {
        return $this->category !== null;
    }
}
