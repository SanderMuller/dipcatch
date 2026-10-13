<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What {@see PriceVerdicts} reads from `price_checks`: either one day of a
 * shop's evidence at one seller and pack size, or the shop's newest reading of
 * any status. Plain rows, not models: a Pro shop holds about 360 readings in
 * 90 days, and the model casts made the verdict about 30 times slower.
 */
final readonly class VerdictReading
{
    /** Days are calendar days in Amsterdam, the boundary the promotion windows use. */
    private const string DAY_TIMEZONE = 'Europe/Amsterdam';

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
     * number: one row per shop, day, seller and pack size of evidence, and
     * each shop's newest reading of any status. Readings from before a shop's
     * repoint are left out: they describe another offer.
     *
     * @param  list<string>  $shopIds
     * @return array{0: array<string, list<self>>, 1: array<string, self>}
     */
    public static function read(array $shopIds, CarbonImmutable $from): array
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

        foreach (self::readings($shopIds, $from)
            ->toBase()
            ->distinct(['shop_id'])
            ->select(['shop_id', 'price', 'status', 'in_stock', 'checked_at', 'seller', 'shelf_inherited', 'consumer_price_issue', 'pack_quantity', 'pack_unit'])
            ->orderBy('shop_id')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->get() as $row) {
            if (is_string($row->shop_id)) {
                $newest[$row->shop_id] = self::fromNewestRow($row);
            }
        }

        return [$days, $newest];
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
    public static function fromDayRow(object $row): self
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
    public static function fromNewestRow(object $row): self
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
        return is_string($value) ? (int) strtotime($value) : 0;
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
