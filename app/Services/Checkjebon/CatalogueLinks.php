<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Models\CheckjebonPrice;
use App\PriceAdapters\Hosts\HostUrl;
use App\Support\SupermarketChains;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Whether the supermarket list's product pages are still on the shops' sites.
 * The list keeps products a shop no longer sells online: about a third of
 * Dirk's sampled rows led to its "helaas" page (2026-10-02).
 */
final class CatalogueLinks
{
    /** Where a shop sends a product page it does not have (verified 2026-10-02). */
    private const array NOT_FOUND_PATHS = ['dirk.nl' => '/helaas'];

    /**
     * Chains whose missing pages answer 404, so one fetch tells (verified
     * 2026-10-02). Jumbo, PLUS, Lidl, Vomar and Hoogvliet answer 200 with a
     * page that says nothing, and AH and bol.com refuse a plain fetch.
     */
    public const array CHECKED_BY_STATUS = ['poiesz', 'spar'];

    public const string DIRK_SITEMAP = 'https://www.dirk.nl/products-sitemap.xml';

    /** Fewer products than this in Dirk's sitemap is a broken sitemap, not a smaller range. */
    private const int MIN_SITEMAP_PRODUCTS = 1000;

    private const float MAX_GONE_SHARE = 0.6;

    public static function isNotFound(string $finalUrl, int $status): bool
    {
        if (in_array($status, [404, 410], strict: true)) {
            return true;
        }

        $path = rtrim((string) parse_url($finalUrl, PHP_URL_PATH), '/');

        foreach (self::NOT_FOUND_PATHS as $host => $notFound) {
            if (HostUrl::matches($finalUrl, $host) && $path === $notFound) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list row a shop address points at, by the list's own link or by
     * the address a check stored for it.
     *
     * @return array{0: string, 1: string}|null  chain, external id
     */
    public static function rowFor(string $url): ?array
    {
        $stored = CatalogueLink::query()->where('url', $url)->first(['chain', 'external_id']);

        if ($stored instanceof CatalogueLink) {
            return [$stored->chain, $stored->external_id];
        }

        $host = UrlNormalizer::normalizeHost((string) parse_url($url, PHP_URL_HOST));

        foreach (CheckjebonChain::query()->get(['chain', 'base_url']) as $chain) {
            if (in_array($host, SupermarketChains::hosts($chain->chain, $chain->base_url), strict: true)) {
                return self::rowOnChain($chain, $url);
            }
        }

        return null;
    }

    /**
     * The row a page on the chain's own host points at, with or without
     * `www.`. Dirk names its product by the id at the end of any path, so
     * its real pages match as well as the list's placeholder ones.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function rowOnChain(CheckjebonChain $chain, string $url): ?array
    {
        $rows = CheckjebonPrice::query()->where('supermarket', $chain->chain);

        if ($chain->chain === 'dirk') {
            // Only a product page: another Dirk page that ends in a number
            // must not mark a product gone.
            $id = str_starts_with((string) parse_url($url, PHP_URL_PATH), '/boodschappen/') ? HostUrl::lastNumericSegment($url) : null;
            $rows->where('external_id', $id ?? '');
        } else {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $basePath = (string) parse_url($chain->base_url, PHP_URL_PATH);

            if (! str_starts_with($path, $basePath)) {
                return null;
            }

            $rows->where('link', substr($path, strlen($basePath)) . (is_string($query) ? "?{$query}" : ''));
        }

        $externalId = $rows->value('external_id');

        return is_string($externalId) ? [$chain->chain, $externalId] : null;
    }

    /**
     * Reads Dirk's product sitemap and records each Dirk list row as live,
     * with its real address, or gone. Null when the sitemap could not be
     * read, or looks broken; nothing is recorded then, and the reason is
     * logged.
     *
     * @return array{alive: int, gone: int}|null
     */
    public function importDirkSitemap(): ?array
    {
        $live = $this->dirkSitemap();

        if ($live === null) {
            return null;
        }

        $records = CheckjebonPrice::query()->where('supermarket', 'dirk')->get(['external_id'])
            ->map(static fn (CheckjebonPrice $row): array => ['chain' => 'dirk', 'external_id' => $row->external_id, 'alive' => isset($live[$row->external_id]), 'url' => $live[$row->external_id] ?? null, 'checked_at' => now()]);
        $gone = $records->where('alive', false)->count();

        // About a quarter was gone on 2026-10-02. Most of the list gone means
        // the sitemap changed shape, not that Dirk stopped selling online.
        if ($records->isNotEmpty() && $gone / $records->count() > self::MAX_GONE_SHARE) {
            Log::warning("Dirk's product sitemap leaves most of the list without a page; nothing was recorded.", ['gone' => $gone, 'rows' => $records->count()]);

            return null;
        }

        foreach ($records->chunk(1000) as $chunk) {
            DB::table('catalogue_links')->upsert($chunk->values()->all(), ['chain', 'external_id'], ['alive', 'url', 'checked_at']);
        }

        return ['alive' => $records->count() - $gone, 'gone' => $gone];
    }

    /**
     * @return array<int|string, string>|null  product id => page address
     */
    private function dirkSitemap(): ?array
    {
        try {
            $response = Http::withUserAgent(Config::string('dipcatch.fetcher.user_agent'))->timeout(60)->get(self::DIRK_SITEMAP);
        } catch (ConnectionException $e) {
            Log::warning("Dirk's product sitemap could not be reached.", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning("Dirk's product sitemap answered with an error.", ['status' => $response->status()]);

            return null;
        }

        preg_match_all('#<loc>(https://www\.dirk\.nl/boodschappen/[^<]*/(\d+))</loc>#', $response->body(), $matches, PREG_SET_ORDER);

        $live = [];

        foreach ($matches as [, $url, $id]) {
            try {
                $live[$id] = UrlNormalizer::normalize(html_entity_decode($url, ENT_QUOTES | ENT_XML1));
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        if (count($live) < self::MIN_SITEMAP_PRODUCTS) {
            Log::warning("Dirk's product sitemap listed too few products to trust.", ['products' => count($live)]);

            return null;
        }

        return $live;
    }
}
