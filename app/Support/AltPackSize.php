<?php declare(strict_types=1);

namespace App\Support;

use App\Jobs\ConfirmAltPackSize;
use App\Models\Shop;
use App\PriceAdapters\ShopSnapshot;
use Carbon\CarbonInterface;

/**
 * Decides, on a successful read, which second size a shop keeps: the same
 * pack stated in another unit than its pack columns — 20 pieces beside 560 g.
 *
 * Decided on every read, not only on one that writes the primary size: a
 * partial response can carry net content without a sales unit. It is judged
 * against the primary the shop ends up with after this read, because a
 * second size only means anything beside a first one.
 */
final readonly class AltPackSize
{
    /**
     * The second size a shop states for the same pack, in another unit than
     * `$primary`: the first of `$statedSizes` (a structured source's own
     * list, such as AH's net content) that parses to another unit, else the
     * piece count the title gives a pack sold by weight or volume — Jumbo's
     * "15 stuks 15 x 28 g", Dirk's "20 stuks" beside a 560 g field.
     *
     * Null when neither says anything new. A title never adds a weight to a
     * count: "Iglo 20 Vissticks" is a count, and any weight in it would be a
     * guess at what the count weighs.
     *
     * @param  list<string>  $statedSizes
     */
    public static function from(array $statedSizes, PackSize $primary, ?string $title): ?PackSize
    {
        foreach ($statedSizes as $stated) {
            $size = PackSize::parse($stated);

            if ($size !== null && $size->unit !== $primary->unit) {
                return $size;
            }
        }

        if ($title === null || $primary->unit === 'piece') {
            return null;
        }

        $count = PackSize::itemCountIn($title);

        return $count !== null && $count > 1 ? PackSize::of($count, 'piece') : null;
    }

    /**
     * The `alt_pack_*` columns to write, or an empty array when nothing
     * changes.
     *
     * In order: a size the source states wins, then an authoritative source
     * stating none clears it, then a count the title gives. Otherwise the
     * stored second size stays — a Checkjebon fallback for an AH shop knows
     * nothing of net content and must not wipe it — with two exceptions:
     *
     *  - The shop now leads with the size it used to state second (the AH
     *    API says 20 pieces, the dataset 560 g). If the new primary is that
     *    same size, the old primary becomes the second size, so the shop
     *    keeps both, and Jev's answer and the join date stand: it is the same
     *    pack. A different quantity is a different pack.
     *  - The primary changed quantity in the same unit with nothing new said
     *    about the second size: 560 g became 500 g, and a count kept from the
     *    old pack would make a pair that describes no real pack at all.
     *
     * `$packSize` is the primary size this read resolved; the shop keeps its
     * stored one when the read states none and has no authority over it.
     *
     * @return array<string, mixed>
     */
    public static function updates(Shop $shop, ?PackSize $packSize, ShopSnapshot $snapshot): array
    {
        $primary = $snapshot->packSizeAuthoritative || $packSize !== null ? $packSize : $shop->packSize();
        $stored = $shop->altPackSize();
        $alt = $primary === null ? null : self::resolve($shop, $primary, $snapshot, $stored);

        if ($alt !== null && $alt->unit === $primary?->unit) {
            $alt = null;
        }

        $altChanged = ! ($alt === null ? $stored === null : $alt->isSameSizeAs($stored));
        $primaryChanged = ! ($primary === null ? $shop->packSize() === null : $primary->isSameSizeAs($shop->packSize()));

        if (! $altChanged && ! $primaryChanged) {
            return [];
        }

        if (self::sameSizes([$primary, $alt], [$shop->packSize(), $stored])) {
            return ['alt_pack_quantity' => $alt?->quantity, 'alt_pack_unit' => $alt?->unit];
        }

        $updates = [
            'alt_pack_quantity' => $alt?->quantity,
            'alt_pack_unit' => $alt?->unit,
            // Jev's answer was about the pair as it stood: either half moving
            // makes it an answer about some other pack.
            'alt_pack_check_key' => null,
            'alt_pack_confirmed' => null,
        ];

        if ($altChanged) {
            $updates['alt_pack_since'] = $alt === null ? null : now();
        }

        return $updates;
    }

    /**
     * After a read wrote the shop: the pair key the read's title belongs to,
     * for {@see ConfirmAltPackSize}, or null without a second size.
     *
     * A second size the plausibility guard keeps out has not joined the
     * comparison, so its join date moves with every read until it does. A
     * shop admitted later, when its price moved into the band, is then a new
     * shop from that read on, and not a fall from the readings before it.
     */
    public static function afterWrite(Shop $locked, CarbonInterface $now): ?string
    {
        if ($locked->altPackSize() === null) {
            return null;
        }

        $product = $locked->product()->with('shops')->first();

        if ($product !== null && $product->comparablePacks()->altInDoubt($locked)) {
            $locked->forceFill(['alt_pack_since' => $now])->save();
        }

        return ConfirmAltPackSize::pairKey($locked);
    }

    /**
     * The same two sizes in either order.
     *
     * @param  array{?PackSize, ?PackSize}  $a
     * @param  array{?PackSize, ?PackSize}  $b
     */
    private static function sameSizes(array $a, array $b): bool
    {
        [$a1, $a2] = $a;
        [$b1, $b2] = $b;

        if ($a1 === null || $a2 === null || $b1 === null || $b2 === null) {
            return false;
        }

        return ($a1->isSameSizeAs($b1) && $a2->isSameSizeAs($b2)) || ($a1->isSameSizeAs($b2) && $a2->isSameSizeAs($b1));
    }

    /**
     * The second size a read states, in the order every writer applies: a
     * stated size, then — unless the source authoritatively stated none — a
     * count from the title.
     *
     * @param  list<string>  $statedSizes
     */
    public static function first(array $statedSizes, bool $authoritative, PackSize $primary, ?string $title): ?PackSize
    {
        return self::from($statedSizes, $primary, title: null)
            ?? ($authoritative ? null : self::from([], $primary, $title));
    }

    private static function resolve(Shop $shop, PackSize $primary, ShopSnapshot $snapshot, ?PackSize $stored): ?PackSize
    {
        $read = self::first($snapshot->altPackSizes, $snapshot->altPackSizesAuthoritative, $primary, $snapshot->title);

        if ($read !== null || $snapshot->altPackSizesAuthoritative || $stored === null) {
            return $read;
        }

        $previous = $shop->packSize();

        if ($stored->unit === $primary->unit) {
            return $stored->isSameSizeAs($primary) && $previous !== null && $previous->unit !== $primary->unit
                ? $previous
                : null;
        }

        if ($previous !== null && $previous->unit === $primary->unit && ! $previous->isSameSizeAs($primary)) {
            return null;
        }

        return $stored;
    }
}
