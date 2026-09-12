<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\PromotionWindow;
use Carbon\CarbonImmutable;

/**
 * Aldi prices its whole assortment in campaign periods, and the payload
 * keeps a period's price after it closes. This decides which price is
 * current and states the period behind it.
 */
final readonly class AldiOffer
{
    /**
     * The price, but only while its window is open. The payload keeps the
     * last campaign's price after it ends, so reporting it would present an
     * expired price as today's.
     *
     * @param  array<mixed, mixed>  $product
     */
    public static function price(array $product): ?string
    {
        $current = $product['currentPrice'] ?? null;

        if (! is_array($current)) {
            return null;
        }

        $from = self::bound($current, 'validFrom');
        $until = self::bound($current, 'validUntil');
        $now = CarbonImmutable::now();

        // Each bound is judged on its own: a record carrying only an end
        // date still says when the price stops being current. A bound the
        // payload states but this adapter cannot read may be hiding an
        // expiry, so it refuses the price instead of assuming the window
        // is open.
        if ($from === false || $until === false) {
            return null;
        }

        if ($from instanceof CarbonImmutable && $now->lessThan($from)) {
            return null;
        }

        if ($until instanceof CarbonImmutable && $now->greaterThan($until)) {
            return null;
        }

        return PriceNormalizer::fromMixed($current['priceValue'] ?? null);
    }

    /**
     * A validity bound: the parsed instant, null when the payload omits it,
     * or false when it states one this adapter cannot read.
     *
     * @param  array<mixed, mixed>  $price
     */
    private static function bound(array $price, string $key): CarbonImmutable|false|null
    {
        $value = $price[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }

        return is_string($value) && ctype_digit($value)
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : false;
    }

    /**
     * The campaign period the current price belongs to. Only ever asked
     * after {@see price()} accepted it, so the window it returns is the one
     * that price passed.
     *
     * @param  array<mixed, mixed>  $product
     */
    public static function window(array $product): ?PromotionWindow
    {
        $current = $product['currentPrice'] ?? null;

        if (! is_array($current)) {
            return null;
        }

        $from = self::bound($current, 'validFrom');
        $until = self::bound($current, 'validUntil');

        return PromotionWindow::make(
            endsAt: $until instanceof CarbonImmutable ? $until : null,
            startsAt: $from instanceof CarbonImmutable ? $from : null,
        );
    }
}
