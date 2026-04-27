<?php declare(strict_types=1);

namespace App\Actions\Products;

use Spatie\LaravelData\Data;

final class AutoDetectResult extends Data
{
    /**
     * @param  list<string>  $selectors
     */
    public function __construct(
        public array $selectors,
        public ?string $title = null,
        public ?string $imageUrl = null,
        public ?string $error = null,
    ) {}

    public static function failure(string $error): self
    {
        return new self(selectors: [], error: $error);
    }
}
