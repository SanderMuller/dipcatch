<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use App\Support\Gtin;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * bol.com offers as rows of the suggestion catalogue (`checkjebon_prices`,
 * chain `bol`), written by the nightly feed import and by the live lookup
 * when a product is added. One shape for both, so a row written by one is
 * updated, not doubled, by the other.
 */
final class BolCatalogRows
{
    public const string CHAIN = 'bol';

    public const string BASE_URL = 'https://www.bol.com/nl/nl/p/';

    /**
     * The row for one offer, or null when its page is not a bol.com product
     * page the chain's base URL can rebuild.
     *
     * @return array{supermarket: string, external_id: string, name: string, price: string, size: null, link: string, ean: ?string, refreshed_at: DateTimeInterface}|null
     */
    public static function row(string $productId, string $ean, string $title, string $url, string $price, DateTimeInterface $refreshedAt): ?array
    {
        if (! str_starts_with($url, self::BASE_URL) || $productId === '') {
            return null;
        }

        return [
            'supermarket' => self::CHAIN,
            'external_id' => mb_substr($productId, 0, 255),
            'name' => mb_substr($title, 0, 255),
            'price' => $price,
            'size' => null,
            'link' => substr($url, strlen(self::BASE_URL)),
            // Leading zeros stripped, as SuggestShops::gtinsOf() compares.
            'ean' => ltrim(Gtin::normalize($ean) ?? '', '0') ?: null,
            'refreshed_at' => $refreshedAt,
        ];
    }

    /**
     * Writes the rows, then the chain row: a chain row always has prices
     * under it, as the checkjebon import keeps it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function store(array $rows, DateTimeInterface $refreshedAt): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('checkjebon_prices')->upsert($rows, ['supermarket', 'external_id'], ['name', 'price', 'size', 'link', 'ean', 'refreshed_at']);

        DB::table('checkjebon_chains')->upsert(
            [['chain' => self::CHAIN, 'label' => 'bol.com', 'base_url' => self::BASE_URL, 'refreshed_at' => $refreshedAt]],
            ['chain'],
            ['label', 'base_url', 'refreshed_at'],
        );

        return count($rows);
    }
}
