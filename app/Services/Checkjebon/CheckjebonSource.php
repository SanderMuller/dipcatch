<?php declare(strict_types=1);

namespace App\Services\Checkjebon;

use App\Models\CheckjebonPrice;
use App\PriceAdapters\ShopSnapshot;

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
    public function supports(string $host): bool
    {
        return DatasetKey::supermarketForHost($host) !== null;
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $host = DatasetKey::hostOf($normalizedUrl);
        $supermarket = $host === null ? null : DatasetKey::supermarketForHost($host);

        if ($supermarket === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        $externalId = DatasetKey::fromUrl($supermarket, $normalizedUrl);
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
}
