<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ShopHealth;
use App\Enums\ShopKind;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Shop data for product analysis, safe to hand to an outside tool such as an
 * AI assistant. Neither export carries an owner, an account id, a product
 * title or an admin note: titles and notes are free text a user writes.
 *
 * URLs lose their query string and fragment, because a query can carry an
 * affiliate, referral or session token. A shop that needs its query to name
 * the product loses that part; for evaluation the path is enough.
 */
final readonly class ShopDataExport
{
    /**
     * One row per host, with how many offers and accounts use it.
     *
     * @return iterable<int, list<string|int>>
     */
    public static function hosts(): iterable
    {
        yield ['host', 'tld', 'support', 'offers', 'distinct_urls', 'accounts', 'reference_links', 'health_ok', 'health_failing', 'health_dead', 'last_success_at'];

        $supported = self::hostList('site.supported_hosts');
        $unsupported = self::hostList('site.unsupported_hosts');

        $rows = Shop::query()
            ->join('products', 'products.id', '=', 'shops.product_id')
            ->toBase()
            ->select('shops.host')
            ->selectRaw('count(*) as offers')
            ->selectRaw('count(distinct shops.url_hash) as distinct_urls')
            ->selectRaw('count(distinct products.user_id) as accounts')
            ->selectRaw('sum(case when shops.kind = ? then 1 else 0 end) as reference_links', [ShopKind::Reference->value])
            ->selectRaw('sum(case when shops.health = ? then 1 else 0 end) as health_ok', [ShopHealth::Ok->value])
            ->selectRaw('sum(case when shops.health = ? then 1 else 0 end) as health_failing', [ShopHealth::Failing->value])
            ->selectRaw('sum(case when shops.health = ? then 1 else 0 end) as health_dead', [ShopHealth::Dead->value])
            ->selectRaw('max(shops.last_success_at) as last_success_at')
            ->groupBy('shops.host')
            ->orderByDesc('accounts')
            ->orderByDesc('offers')
            ->orderBy('shops.host')
            ->cursor();

        foreach ($rows as $row) {
            $values = (array) $row;
            $host = is_string($values['host'] ?? null) ? $values['host'] : '';

            yield [
                $host,
                str_contains($host, '.') ? substr($host, (int) strrpos($host, '.') + 1) : '',
                match (true) {
                    self::listed($host, $supported) => 'supported',
                    self::listed($host, $unsupported) => 'unsupported',
                    default => 'generic',
                },
                self::count($values, 'offers'),
                self::count($values, 'distinct_urls'),
                self::count($values, 'accounts'),
                self::count($values, 'reference_links'),
                self::count($values, 'health_ok'),
                self::count($values, 'health_failing'),
                self::count($values, 'health_dead'),
                self::timestamp($values['last_success_at'] ?? null),
            ];
        }
    }

    /**
     * One row per product page. Offers on the same page by different accounts
     * fold into one row with a count, and the most recently checked offer
     * speaks for the page.
     *
     * @return iterable<int, list<string|int>>
     */
    public static function urls(): iterable
    {
        yield ['host', 'url', 'offers', 'kind', 'health', 'last_status', 'unreadable_reason', 'consumer_price_issue', 'currency', 'last_success_at', 'last_checked_at'];

        $shops = Shop::query()
            ->select(['url_hash', 'host', 'url', 'kind', 'health', 'last_status', 'unreadable_reason', 'consumer_price_issue', 'currency', 'last_success_at', 'last_checked_at'])
            ->orderBy('url_hash')
            ->orderByRaw('last_checked_at desc nulls last')
            ->cursor();

        /** @var Shop|null $current */
        $current = null;
        $offers = 0;

        foreach ($shops as $shop) {
            if ($current !== null && $current->url_hash !== $shop->url_hash) {
                yield self::urlRow($current, $offers);
                $current = null;
            }

            if ($current === null) {
                $current = $shop;
                $offers = 0;
            }

            $offers++;
        }

        if ($current !== null) {
            yield self::urlRow($current, $offers);
        }
    }

    /**
     * Writes rows as CSV to the output stream, for a streamed download.
     *
     * @param  iterable<int, list<string|int>>  $rows
     */
    public static function writeCsv(iterable $rows): void
    {
        foreach ($rows as $row) {
            echo self::csvLine($row);
        }
    }

    /**
     * One row as a CSV line, newline included.
     *
     * @param  list<string|int>  $row
     */
    public static function csvLine(array $row): string
    {
        $buffer = fopen('php://memory', 'w+b');

        if ($buffer === false) {
            return '';
        }

        fputcsv($buffer, $row, escape: '');
        rewind($buffer);
        $line = (string) stream_get_contents($buffer);
        fclose($buffer);

        return $line;
    }

    /**
     * @return list<string|int>
     */
    private static function urlRow(Shop $shop, int $offers): array
    {
        return [
            (string) $shop->host,
            preg_replace('/[?#].*$/s', '', (string) $shop->url) ?? '',
            $offers,
            $shop->kind->value,
            $shop->health->value,
            $shop->last_status->value,
            (string) $shop->unreadable_reason,
            (string) $shop->consumer_price_issue?->value,
            (string) $shop->currency,
            self::timestamp($shop->last_success_at),
            self::timestamp($shop->last_checked_at),
        ];
    }

    /**
     * @return list<string>
     */
    private static function hostList(string $key): array
    {
        $hosts = config($key);

        return is_array($hosts) ? array_values(array_filter($hosts, is_string(...))) : [];
    }

    /**
     * A subdomain counts as its listed shop, the way host adapters match it.
     *
     * @param  list<string>  $hosts
     */
    private static function listed(string $host, array $hosts): bool
    {
        return array_any($hosts, static fn (string $listed): bool => $host === $listed || str_ends_with($host, '.' . $listed));
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function count(array $values, string $key): int
    {
        return is_numeric($values[$key] ?? null) ? (int) $values[$key] : 0;
    }

    private static function timestamp(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : '';
    }
}
