<?php declare(strict_types=1);

namespace App\Services\PriceSources;

use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\BolApi\BolApiSource;

/**
 * The shops read through an API instead of their web page: ah.nl through
 * AH's mobile API (live, bonus-aware) and bol.com through bol's Catalog
 * API (the site blocks page reads at times). A miss returns null, so the
 * caller falls back to its next source.
 */
final readonly class ApiPriceSources
{
    public function __construct(
        private AhApiSource $ahApi,
        private BolApiSource $bolApi,
    ) {}

    /**
     * @return array{0: ShopSnapshot, 1: string}|null  the reading and the reader's adapter key
     */
    public function read(string $host, string $url): ?array
    {
        $source = match (true) {
            $this->bolApi->supports($host) => [$this->bolApi, 'bol-api'],
            $this->ahApi->supports($host) => [$this->ahApi, 'ah-api'],
            default => null,
        };

        $snapshot = $source === null ? null : $source[0]->resolve($url)->snapshot;

        return $snapshot instanceof ShopSnapshot ? [$snapshot, $source[1]] : null;
    }
}
