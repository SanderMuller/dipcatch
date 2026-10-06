<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Support\UrlNormalizer;

/**
 * A redirect from a product page to a page above it, which a shop sends when
 * it no longer sells the product. See {@see ShopFetcher::fetch()}.
 */
final readonly class ParentPageRedirect
{
    /**
     * A shop that drops a product often redirects its page to the category
     * above it, or to the home page. That page lists other products, and an
     * adapter would price one of them as this one (zooplus, verified
     * 2026-10-05). Reported as the 404 it stands for.
     *
     * @throws HttpError
     */
    public static function refuse(string $finalUrl, string $requested, bool $redirected): void
    {
        if ($redirected && self::dropsProduct($finalUrl, $requested)) {
            throw new HttpError(404, "Redirected to {$finalUrl}, a page above the one asked for: the shop no longer has it.");
        }
    }

    /**
     * Whether `$finalUrl` is on the same host as `$requested` and its path is
     * one of the path's parents that no longer names the product: the home
     * page, or a parent that dropped the product's number, as
     * `/shop/dogs/402593` landing on `/shop/dogs`. A page that moves deeper or
     * sideways is not one.
     */
    private static function dropsProduct(string $finalUrl, string $requested): bool
    {
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);
        $requestedHost = parse_url($requested, PHP_URL_HOST);

        if (! is_string($finalHost) || ! is_string($requestedHost)
            || UrlNormalizer::normalizeHost($finalHost) !== UrlNormalizer::normalizeHost($requestedHost)) {
            return false;
        }

        $segments = static fn (string $url): array => array_values(array_filter(
            explode('/', (string) parse_url($url, PHP_URL_PATH)),
            static fn (string $segment): bool => $segment !== '',
        ));

        $finalPath = $segments($finalUrl);
        $requestedPath = $segments($requested);

        if (count($finalPath) >= count($requestedPath) || $finalPath !== array_slice($requestedPath, 0, count($finalPath))) {
            return false;
        }

        if ($finalPath === []) {
            return true;
        }

        // A parent that still carries a product number is taken for the
        // product's own page. That lets a numbered category (`/c/dogs-1234`)
        // through, but a canonical redirect to `/p/coffee-123` keeps working.
        return array_any(array_slice($requestedPath, count($finalPath)), self::namesProduct(...))
            && ! array_any($finalPath, self::namesProduct(...));
    }

    /**
     * Whether a path segment carries a product number: three or more digits
     * (`/402593`), or a slug that ends in them (`…-salmon-57815.html` on
     * petsmart.ca, 2026-10-06). A short number (`/5`) is a size or a
     * variant, not the product.
     */
    private static function namesProduct(string $segment): bool
    {
        return preg_match('/^\d{3,}$|-\d{3,}(\.html?)?$/', $segment) === 1;
    }
}
