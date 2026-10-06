<?php declare(strict_types=1);

namespace App\Services\PriceSources;

use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\AxfoodApi\AxfoodApiSource;
use App\Services\BolApi\BolApiSource;
use App\Services\DmApi\DmApiSource;

/**
 * The shops read through an API instead of their web page; each source's
 * `supports()` names its hosts. A miss returns null, so the caller falls
 * back to its next source.
 */
final readonly class ApiPriceSources
{
    public function __construct(
        private AhApiSource $ahApi,
        private BolApiSource $bolApi,
        private DmApiSource $dmApi = new DmApiSource(),
        private AxfoodApiSource $axfoodApi = new AxfoodApiSource(),
    ) {}

    /**
     * @return array{0: ShopSnapshot, 1: string}|null  the reading and the reader's adapter key
     */
    public function read(string $host, string $url): ?array
    {
        $source = match (true) {
            $this->bolApi->supports($host) => [$this->bolApi, 'bol-api'],
            $this->ahApi->supports($host) => [$this->ahApi, 'ah-api'],
            $this->dmApi->supports($host) => [$this->dmApi, 'dm-api'],
            $this->axfoodApi->supports($host) => [$this->axfoodApi, 'axfood-api'],
            default => null,
        };

        $snapshot = $source === null ? null : $source[0]->resolve($url)->snapshot;

        return $snapshot instanceof ShopSnapshot ? [$snapshot, $source[1]] : null;
    }
}
