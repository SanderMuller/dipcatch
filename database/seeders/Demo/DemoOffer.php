<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * One offer in the demo catalog. A plain value object rather than an array
 * so the seeder reads typed data instead of guessing at array keys.
 */
final readonly class DemoOffer
{
    /**
     * @param  string  $state  `ok`, `failing`, `dead`, `out_of_stock` or `unknown_stock`
     * @param  string  $packUnit  `g`, `ml` or `piece`
     * @param  string|null  $promotion  `live` or `expired`
     */
    public function __construct(
        public string $host,
        public string $path,
        public float $price,
        public float $packQuantity,
        public string $packUnit,
        public string $state = 'ok',
        public bool $conditional = false,
        public ?string $promotion = null,
    ) {}

    public function url(): string
    {
        return 'https://www.' . $this->host . '/' . ltrim($this->path, '/');
    }
}
