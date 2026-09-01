<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\Models\CheckjebonPrice;
use App\PriceAdapters\ShopSnapshot;
use App\Support\UrlNormalizer;

/**
 * Dataset-backed price source for hosts the HTTP scraper cannot serve:
 * ah.nl (WAF-blocked) and boodschaapje.nl/Lidl (SPA shell). Resolves a
 * product URL against the local copy of checkjebon.nl's daily dataset
 * instead of fetching the page. dirk.nl is scraped directly — its pages
 * carry JSON-LD with the live promo price, while the dataset only holds
 * the regular price.
 *
 * The dataset carries no images and no stock flags: snapshots have a null
 * image and `inStock = true` always. Prices are EUR by definition.
 */
final readonly class CheckjebonSource
{
    /** @var array<string, string> Normalized host → dataset supermarket key. */
    private const array HOSTS = [
        'ah.nl' => 'ah',
        'boodschaapje.nl' => 'lidl',
    ];

    public function supports(string $host): bool
    {
        return $this->supermarketForHost($host) !== null;
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $host = self::hostOf($normalizedUrl);
        $supermarket = $host === null ? null : $this->supermarketForHost($host);

        if ($supermarket === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        $externalId = self::externalIdFromUrl($supermarket, $normalizedUrl);
        if ($externalId === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        $row = CheckjebonPrice::query()
            ->where('supermarket', $supermarket)
            ->where('external_id', $externalId)
            ->first();

        if ($row === null) {
            $reason = CheckjebonPrice::query()->where('supermarket', $supermarket)->exists()
                ? CheckjebonResult::REASON_NOT_IN_DATASET
                : CheckjebonResult::REASON_DATASET_EMPTY;

            return CheckjebonResult::miss($reason);
        }

        return CheckjebonResult::found(new ShopSnapshot(
            title: $row->name,
            imageUrl: null,
            price: (string) $row->price,
            currency: 'EUR',
            inStock: true,
            raw: [
                'source' => 'checkjebon',
                'refreshed_at' => $row->refreshed_at->toIso8601String(),
            ],
            packSize: $row->size,
            packSizeAuthoritative: true,
        ));
    }

    private function supermarketForHost(string $host): ?string
    {
        if (isset(self::HOSTS[$host])) {
            return self::HOSTS[$host];
        }

        foreach (self::HOSTS as $candidate => $supermarket) {
            if (str_ends_with($host, '.' . $candidate)) {
                return $supermarket;
            }
        }

        return null;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }

    /**
     * AH URLs carry a `wi<digits>` path segment; boodschaapje product
     * URLs end in the bare numeric product id.
     */
    private static function externalIdFromUrl(string $supermarket, string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if ($supermarket === 'ah') {
            foreach ($segments as $segment) {
                if (preg_match('/^wi\d+$/i', $segment) === 1) {
                    return strtolower($segment);
                }
            }

            return null;
        }

        $last = end($segments);

        return is_string($last) && ctype_digit($last) ? $last : null;
    }
}
