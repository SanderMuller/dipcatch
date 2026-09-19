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
