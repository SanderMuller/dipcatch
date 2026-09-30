<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Models\Product;
use App\Models\WebSearch;
use App\Models\WebShopFinding;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class WebResultFilter
{
    /**
     * @return list<array{url: string, url_hash: string, host: string, title: string, snippet: string}>
     */
    public static function keep(Product $product, WebSearch $search): array
    {
        $product->loadMissing('shops');
        $tracked = $product->shops->pluck('host')->all();
        $dismissed = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->whereNotNull('dismissed_at')
            ->pluck('url_hash')
            ->all();

        $kept = [];

        foreach ($search->results as $result) {
            try {
                $normalized = UrlNormalizer::normalize($result['link']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $host = UrlNormalizer::normalizeHost((string) parse_url($normalized, PHP_URL_HOST));
            $hash = UrlNormalizer::hash($normalized);

            // Results come best first, so the first one per host is its best.
            if ($host === '' || isset($kept[$host]) || in_array($host, $tracked, strict: true) || self::isNotAShop($host) || in_array($hash, $dismissed, strict: true)) {
                continue;
            }

            $kept[$host] = ['url' => $result['link'], 'url_hash' => $hash, 'host' => $host, 'title' => $result['title'], 'snippet' => $result['snippet']];
        }

        return array_values($kept);
    }

    public static function isNotAShop(string $host): bool
    {
        /** @var list<string> $blocked */
        $blocked = Config::array('dipcatch.web_discovery.not_a_shop');

        return array_any($blocked, static fn (string $entry): bool => $host === $entry || str_ends_with($host, ".{$entry}"));
    }
}
