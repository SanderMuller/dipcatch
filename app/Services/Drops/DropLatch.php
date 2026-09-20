<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\Product;
use App\Support\Numeric;
use Illuminate\Support\Facades\DB;

/**
 * The latch that stops one drop being mailed twice.
 *
 * `last_notified_price` holds the price already sent, and `last_notified_unit`
 * says what money that is. The pair matters: a latch armed while the product
 * compared packs says nothing once it compares kilos, and read as though it did
 * it would silently suppress every later drop above that number.
 */
final readonly class DropLatch
{
    private const int BC_SCALE = 4;

    /**
     * Clear `last_notified_price` / `last_notified_at` when the new cheapest
     * is at or above the reference (recovered). Called from
     * `recomputeCheapestShop()` on upward / null-cheapest moves so the latch
     * doesn't get stuck after the original cheapest offer goes out of stock.
     */
    public function clearIfRecovered(Product $product, ?string $newPrice, ?ReferenceValue $reference): void
    {
        if ($product->last_notified_price === null && $product->last_notified_at === null) {
            return;
        }

        // A latch armed in one basis says nothing in the other: €6.15 a pack
        // read as €6.15 a kilo would suppress every real fall above it, for
        // good and without a trace. A basis change clears it.
        if ($reference !== null && ! self::matchesBasis($product, $reference->unit)) {
            $this->clearAtomically($product);

            return;
        }

        // Null cheapest = no eligible offer; treat as "recovered" (nothing to
        // compare against, latch should not stay armed indefinitely).
        if ($newPrice === null || $reference === null) {
            $this->clearAtomically($product);

            return;
        }

        if ($this->isRecovered($newPrice, $reference)) {
            $this->clearAtomically($product);
        }
    }

    public function isRecovered(string $newPrice, ReferenceValue $ref): bool
    {
        return bccomp(Numeric::str($newPrice), Numeric::str($ref->value), self::BC_SCALE) >= 0;
    }

    /** Whether the stored latch is money in the basis being measured now. */
    public static function matchesBasis(Product $product, ?string $unit): bool
    {
        return $product->last_notified_unit === $unit;
    }

    public function shouldNotify(Product $locked, string $newPrice, ?string $unit): bool
    {
        if ($locked->last_notified_price === null || ! self::matchesBasis($locked, $unit)) {
            return true;
        }

        return bccomp(Numeric::str($newPrice), Numeric::str((string) $locked->last_notified_price), self::BC_SCALE) < 0;
    }

    public function clearAtomically(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->find($product->id);

            if ($locked === null) {
                return;
            }

            if ($locked->last_notified_price === null && $locked->last_notified_at === null) {
                return;
            }

            $locked->forceFill([
                'last_notified_price' => null,
                'last_notified_at' => null,
                'last_notified_unit' => null,
            ])->save();
        });
    }
}
