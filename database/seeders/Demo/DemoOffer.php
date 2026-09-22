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
        /**
         * The real address of a real page, when this offer has one.
         *
         * `$path` builds `https://www.{host}/{path}`, which is the right
         * shape for filler and wrong for anything else: a real offer needs
         * its query string (zooplus pins a variant with `?activeVariant=`)
         * and not every shop answers on `www.`. A recheck of an offer with
         * no real URL reads a 404 and marks itself dead, which is why the
         * curated products carry one.
         */
        public ?string $realUrl = null,
        /** The product photo the shop itself serves. */
        public ?string $imageUrl = null,
    ) {}

    public function url(): string
    {
        return $this->realUrl ?? 'https://www.' . $this->host . '/' . ltrim($this->path, '/');
    }

    /** True when this offer points at a page that exists. */
    public function isReal(): bool
    {
        return $this->realUrl !== null;
    }
}
