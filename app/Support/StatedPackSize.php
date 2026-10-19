<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductDepartment;

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
     * A count written as a multiplier with nothing sized after it — `200x
     * Vuilniszakken 5L`, where the 5 L is one bag and the 200 is the pack.
     *
     * The count carries no unit word, so neither the cross form nor the piece
     * vocabulary saw it, and the 5 L was read as the whole box: two hundred
     * bags priced at €3,20 per litre, which is what one bag's worth of plastic
     * costs. The negative lookahead is what keeps `12 x 55 g` out — a cross
     * form is a count followed by a size, and this shape is a count followed by
     * words.
     */
    private const string BARE_COUNT_PATTERN = '/(?<![\d.,])(\d+)\s*[x\x{00D7}](?!\s*\d)/iu';

    /**
     * A count of what a pack holds rather than how many packs: sachets,
     * capsules, pads, tablets, sheets. A subset of the piece words; "stuks",
     * "pcs" and "pack" count packs and are not in it.
     */
    private const string CONTENTS_WORDS = 'kauwtabletten|bruistabletten|zuigtabletten|tabletten|capsules|capsule|sachets|tablets|tablet|vellen|tabl|sach|caps|cups|pads|cps|cup|pad|vel|tb';

    /** Bags, which hold what a pack weighs or measures: "Koffie 500 g 20 zakjes". */
    private const string BAG_WORDS = 'zakjes|zakje';

    /**
     * A title whose product is empty bags, sized by what one holds:
     * "Diepvrieszakjes 1 L 50 zakjes" is fifty bags of a litre, where
     * "Shampoo navulzakjes 10 zakjes, 100 ml" is ten sachets holding 100 ml.
     */
    private const string BAG_PRODUCT_PATTERN = '/(?:diepvries|vries|vuilnis|afval|pedaalemmer|boterham|sandwich|opberg|zip|grip)zak(?:jes|je|ken)\b/iu';

    /**
     * The whole pack, when the stated size is the size of one item out of a
     * multipack the title counts — null when the stated size stands.
     *
     * Three title shapes count items: `12 x 55 g`, a leading piece count
     * with the size elsewhere in the string (`12st ... 55g`, which is how
     * Foodello writes every listing), and a named set (`55 g, pack of 12`).
     * All mean twelve of them, and so does a bare `200x`. A count of what is
     * inside a weight counts items only where `$countsItems`: "Koffie 500 g
     * 20 zakjes" is one 500 g bag ({@see countsContents()}).
     *
     * Deliberately narrow. The stated size has to equal the title's item size
     * exactly, and has to be a weight or a volume. Anything else leaves the
     * shop's figure alone, because a shop knows its own packaging better than
     * a title does. A size the shop's unit price `$confirmed` as the whole
     * pack stands too.
     */
    public static function wholePack(PackSize $stated, ?string $title, bool $confirmed = false, bool $countsItems = false): ?PackSize
    {
        if ($confirmed || $title === null || $stated->unit === 'piece' || ! self::restatesTheItem($stated, $title)) {
            return null;
        }

        $count = ($countsItems || ! self::countsContents($title, $stated->unit) ? ItemCount::in($title) : null)
            ?? self::bareCountIn($title)
            ?? ItemCount::setIn($title)
            ?? 0.0;

        return $count > 1 ? PackSize::of($stated->quantity * $count, $stated->unit) : null;
    }

    /**
     * The pack a title implies when the shop states no size of its own.
     *
     * Close to {@see wholePack()}, from the title alone: a title that counts
     * its items and then sizes one of them describes a pack of that many. A
     * count after the size, or of what is inside, counts only where
     * `$countsItems`. Foodello states no structured size at all, so this is
     * the path its rows take.
     */
    public static function fromTitle(?string $title, bool $countsItems = false): ?PackSize
    {
        if ($title === null) {
            return null;
        }

        $parsed = PackSize::parse($title);
        $count = self::leadingCountBeforeSize($title, $countsItems ? null : $parsed?->unit) ?? ItemCount::setIn($title) ?? ($countsItems ? self::trailingCount($title) : null);

        if (! $parsed instanceof PackSize || $count === null || $parsed->unit === 'piece') {
            return $parsed;
        }

        return PackSize::of($parsed->quantity * $count, $parsed->unit) ?? $parsed;
    }

    /**
     * A piece count after the size, for a product that is a thing rather
     * than its contents ({@see ProductDepartment::countsItems()}):
     * "Glasses 350 ml, 4 pcs" is four glasses, where "Koffie 500 g 20 zakjes"
     * is one 500 g bag of twenty. Null beside a form that counts already.
     */
    private static function trailingCount(string $title): ?float
    {
        if (PackSize::hasCrossForm($title) || preg_match('/\d-pack\b/i', $title) === 1) {
            return null;
        }

        $count = ItemCount::in($title);

        return $count !== null && $count > 1 ? $count : null;
    }

    /**
     * True when the title's piece count counts what is inside, wherever it
     * stands: "Koffie 500 g 20 zakjes" and "30 capsules 216 g" weigh that
     * much in all, where "Barebells 55 g 12 stuks" is twelve bars.
     */
    private static function countsContents(string $title, string $unit): bool
    {
        return ! PackSize::hasCrossForm($title) && preg_match(self::contentsCountPattern($unit, $title), $title) === 1;
    }

    /** A count of what is inside: not the bags of a title that sells bags by their volume. */
    private static function contentsCountPattern(string $unit, string $title): string
    {
        $sellsBags = $unit === 'ml' && preg_match(self::BAG_PRODUCT_PATTERN, $title) === 1;

        return '/\d+\s*(?:' . self::CONTENTS_WORDS . ($sellsBags ? '' : '|' . self::BAG_WORDS) . ')\b/iu';
    }

    /**
     * The count in a title that counts its items first and sizes one of them
     * last — `12st Barebells ... 55g`. Null unless the count comes before the
     * size.
     *
     * The order is the signal. "Glasses 350 ml, 4 pcs" puts the count last,
     * and is four glasses only where the department counts items. So is the
     * word: "30 capsules 216 g" weighs 216 g in all. A caller that reads any
     * word as a count of items passes no `$contentsOf` unit.
     */
    private static function leadingCountBeforeSize(string $title, ?string $contentsOf = null): ?float
    {
        if (PackSize::hasCrossForm($title)) {
            return null;
        }

        $count = self::firstMatch(PackSize::pieceCountPattern(), $title)
            ?? self::firstMatch(self::BARE_COUNT_PATTERN, $title);
        $size = self::firstMatch(PackSize::sizePattern(), $title);

        if ($count === null || $size === null || $count['offset'] >= $size['offset'] || $count['value'] <= 1) {
            return null;
        }

        return $contentsOf === null || preg_match(self::contentsCountPattern($contentsOf, $title), substr($title, $count['offset']), $match, PREG_OFFSET_CAPTURE) !== 1 || $match[0][1] !== 0
            ? $count['value']
            : null;
    }

    /**
     * How many items a bare multiplier counts. Null unless the title writes
     * exactly one, so a string counting two things states nothing rather than
     * guessing which count is the pack.
     */
    public static function bareCountIn(string $title): ?float
    {
        if (preg_match_all(self::BARE_COUNT_PATTERN, $title, $matches, PREG_SET_ORDER) !== 1) {
            return null;
        }

        return (float) $matches[0][1];
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
        $crossForm = ItemCount::crossFormItemIn($title);

        if ($crossForm instanceof PackSize) {
            return $stated->isSameSizeAs($crossForm);
        }

        // `parse()` already refuses a title that states two different sizes,
        // so a match here means the only size in the string is the one the
        // shop restated.
        return $stated->isSameSizeAs(PackSize::parse($title));
    }
}
