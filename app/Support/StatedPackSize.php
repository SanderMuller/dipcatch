<?php declare(strict_types=1);

namespace App\Support;

/**
 * Reconciles the pack size a shop states with the one its own title implies.
 *
 * A shop selling a box of twelve bars that reports "55 g" is describing one
 * bar. Taken at face value it makes the box look twelve times more expensive
 * per kilo than it is, and that is the number best value ranks on and the
 * unit-price alerts fire on.
 *
 * Separate from {@see PackSize} on purpose: that class turns text into a size,
 * this one decides which of two sizes to believe.
 */
final readonly class StatedPackSize
{
    /**
     * The whole pack, when the stated size is the size of one item out of a
     * multipack the title counts — null when the stated size stands.
     *
     * Two title shapes count items: `12 x 55 g`, and a leading piece count
     * with the size elsewhere in the string (`12st ... 55g`, which is how
     * Foodello writes every listing). Both mean twelve of them.
     *
     * Deliberately narrow. The stated size has to equal the title's item size
     * exactly, and has to be a weight or a volume. Anything else leaves the
     * shop's figure alone, because a shop knows its own packaging better than
     * a title does.
     */
    public static function wholePack(PackSize $stated, ?string $title): ?PackSize
    {
        if ($title === null || $stated->unit === 'piece' || ! self::restatesTheItem($stated, $title)) {
            return null;
        }

        $count = PackSize::itemCountIn($title) ?? 0.0;

        return $count > 1 ? PackSize::of($stated->quantity * $count, $stated->unit) : null;
    }

    /**
     * The pack a title implies when the shop states no size of its own.
     *
     * Same reading as {@see wholePack()}, from the title alone: a title that
     * counts its items and then sizes one of them describes a pack of that
     * many. Foodello states no structured size at all, so this is the path
     * its rows take.
     */
    public static function fromTitle(?string $title): ?PackSize
    {
        if ($title === null) {
            return null;
        }

        $parsed = PackSize::parse($title);
        $count = self::leadingCountBeforeSize($title);

        if (! $parsed instanceof PackSize || $count === null || $parsed->unit === 'piece') {
            return $parsed;
        }

        return PackSize::of($parsed->quantity * $count, $parsed->unit) ?? $parsed;
    }

    /**
     * The count in a title that counts its items first and sizes one of them
     * last — `12st Barebells ... 55g`. Null unless the count comes before the
     * size.
     *
     * The order is the whole signal. "Koffie 500 g 20 zakjes" is 500 g holding
     * twenty sachets, not twenty times 500 g, and it is told apart from the
     * shape above only by which of the two comes first.
     */
    private static function leadingCountBeforeSize(string $title): ?float
    {
        if (PackSize::hasCrossForm($title)) {
            return null;
        }

        $count = self::firstMatch(PackSize::pieceCountPattern(), $title);
        $size = self::firstMatch(PackSize::sizePattern(), $title);

        if ($count === null || $size === null || $count['offset'] >= $size['offset'] || $count['value'] <= 1) {
            return null;
        }

        return $count['value'];
    }

    /** @return array{value: float, offset: int}|null */
    private static function firstMatch(string $pattern, string $title): ?array
    {
        if (preg_match($pattern, $title, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['value' => (float) str_replace(',', '.', $match[1][0]), 'offset' => (int) $match[0][1]];
    }

    /** True when the size the shop states is the size of one item in the title. */
    private static function restatesTheItem(PackSize $stated, string $title): bool
    {
        $crossForm = PackSize::crossFormItemIn($title);

        if ($crossForm instanceof PackSize) {
            return $stated->isSameSizeAs($crossForm);
        }

        // `parse()` already refuses a title that states two different sizes,
        // so a match here means the only size in the string is the one the
        // shop restated.
        return $stated->isSameSizeAs(PackSize::parse($title));
    }
}
