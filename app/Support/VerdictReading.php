<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What {@see PriceVerdicts} reads from `price_checks`: either one day of a
 * shop's evidence at one seller and pack size, or the shop's newest reading of
 * any status. Plain rows, not models: a Pro shop holds about 360 readings in
 * 90 days, and the model casts made the verdict about 30 times slower.
 */
final readonly class VerdictReading
{
    /** Days are calendar days in Amsterdam, the boundary the promotion windows use. */
    public const string DAY_TIMEZONE = 'Europe/Amsterdam';

    /**
     * @param  int  $firstAt  The first `checked_at` in the row, as a Unix timestamp.
     * @param  int  $lastAt  The last one; the same as `$firstAt` for one reading.
     * @param  numeric-string|null  $price  The lowest price of the day, or the reading's price.
     * @param  string|null  $size  The pack size as "quantity unit", or null when the row stores none.
     */
    private function __construct(
        public int $firstAt,
        public int $lastAt,
        public ?string $price,
        public bool $isEvidence,
        public ?string $seller,
        public ?string $size,
        public ?string $day,
    ) {}

    /**
     * The shops' history in the window, from two queries whatever their
     * number: one row per shop, day, seller and pack size of evidence; and per
     * shop the newest reading of any status, with the pack size of the first
     * reading of any status from `$sizeCutoff` on. Readings from before a
     * shop's repoint are left out: they describe another offer.
     *
     * @param  list<string>  $shopIds
     * @return array{0: array<string, list<self>>, 1: array<string, self>, 2: array<string, string|null>}
     */
    public static function read(array $shopIds, CarbonImmutable $from, CarbonImmutable $sizeCutoff): array
    {
        $days = [];

        foreach (self::readings($shopIds, $from)
            ->verdictEvidence()
            ->toBase()
            ->selectRaw("shop_id, (checked_at at time zone '" . self::DAY_TIMEZONE . "')::date::text as day, min(price) as price, min(checked_at) as first_at, max(checked_at) as last_at, seller, pack_quantity, pack_unit")
            ->groupBy('shop_id', 'day', 'seller', 'pack_quantity', 'pack_unit')
            ->get() as $row) {
            if (is_string($row->shop_id)) {
                $days[$row->shop_id][] = self::fromDayRow($row);
            }
        }

        $newest = [];
        $sizesAfterCutoff = [];
        $placeholders = implode(', ', array_fill(0, count($shopIds), '?'));
        $afterRepoint = 'pc.checked_at >= ? and (s.repointed_at is null or pc.checked_at > s.repointed_at)';

        // One index lookup per shop, not a scan of its window: LATERAL with
        // LIMIT 1 walks (shop_id, checked_at) from the end it needs.
        $rows = DB::select(
            "select s.id as shop_id, n.price, n.status, n.in_stock, n.checked_at, n.seller, n.shelf_inherited, n.consumer_price_issue, n.pack_quantity, n.pack_unit,
                f.checked_at as first_checked_at, f.pack_quantity as first_pack_quantity, f.pack_unit as first_pack_unit
            from shops s
            left join lateral (
                select pc.* from price_checks pc where pc.shop_id = s.id and {$afterRepoint}
                order by pc.checked_at desc, pc.id desc limit 1
            ) n on true
            left join lateral (
                select pc.checked_at, pc.pack_quantity, pc.pack_unit from price_checks pc where pc.shop_id = s.id and {$afterRepoint}
                order by pc.checked_at asc, pc.id asc limit 1
            ) f on true
            where s.id in ({$placeholders})",
            // With the offset: a Carbon binding is sent without one, and the
            // database would read it in its own session time zone.
            [$from->toIso8601String(), $from->max($sizeCutoff)->toIso8601String(), ...$shopIds],
        );

        foreach ($rows as $row) {
            if (! is_object($row) || ! is_string($row->shop_id) || $row->checked_at === null) {
                continue;
            }

            $newest[$row->shop_id] = self::fromNewestRow($row);

            if ($row->first_checked_at !== null) {
                $sizesAfterCutoff[$row->shop_id] = self::sizeKey($row->first_pack_quantity, $row->first_pack_unit);
            }
        }

        return [$days, $newest, $sizesAfterCutoff];
    }

    /**
     * @param  list<string>  $shopIds
     * @return EloquentQueryBuilder<PriceCheck>
     */
    private static function readings(array $shopIds, CarbonImmutable $from): EloquentQueryBuilder
    {
        return PriceCheck::query()
            ->whereIn('shop_id', $shopIds)
            ->where('checked_at', '>=', $from)
            ->whereNotExists(fn (QueryBuilder $repointed): QueryBuilder => $repointed
                ->selectRaw('1')
                ->from('shops')
                ->whereColumn('shops.id', 'price_checks.shop_id')
                ->whereColumn('shops.repointed_at', '>=', 'price_checks.checked_at'));
    }

    /** A day of evidence, which the query already filtered with {@see PriceCheck::verdictEvidence()}. */
    private static function fromDayRow(object $row): self
    {
        return new self(
            firstAt: self::timestamp($row->first_at ?? null),
            lastAt: self::timestamp($row->last_at ?? null),
            price: self::price($row->price ?? null),
            isEvidence: true,
            seller: self::text($row->seller ?? null),
            size: self::sizeKey($row->pack_quantity ?? null, $row->pack_unit ?? null),
            day: self::text($row->day ?? null),
        );
    }

    /** The newest reading of any status, judged by the rules of {@see PriceCheck::verdictEvidence()}. */
    private static function fromNewestRow(object $row): self
    {
        $at = self::timestamp($row->checked_at ?? null);
        $price = self::price($row->price ?? null);

        return new self(
            firstAt: $at,
            lastAt: $at,
            price: $price,
            isEvidence: ($row->status ?? null) === ScrapeStatus::Ok->value
                && $price !== null
                && ($row->in_stock ?? null) !== false
                && ($row->shelf_inherited ?? null) !== true
                && ($row->consumer_price_issue ?? null) === null,
            seller: self::text($row->seller ?? null),
            size: self::sizeKey($row->pack_quantity ?? null, $row->pack_unit ?? null),
            day: null,
        );
    }

    /** One string per pack size, so "500", "500.0" and "500.00" grams are equal. */
    public static function sizeKey(mixed $quantity, mixed $unit): ?string
    {
        if ($quantity === null && $unit === null) {
            return null;
        }

        return (is_numeric($quantity) ? bcadd((string) $quantity, '0', 2) : '') . ' ' . (is_string($unit) ? $unit : '');
    }

    private static function timestamp(mixed $value): int
    {
        return is_string($value) ? (int) Carbon::parse($value)->getTimestamp() : 0;
    }

    /** @return numeric-string|null */
    private static function price(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
