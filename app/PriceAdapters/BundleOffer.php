<?php declare(strict_types=1);

namespace App\PriceAdapters;

use InvalidArgumentException;

final readonly class BundleOffer
{
    public int $quantity;

    /** @var numeric-string */
    public string $totalPrice;

    public function __construct(int $quantity, string $totalPrice)
    {
        if ($quantity < 2 || $quantity > 65_535) {
            throw new InvalidArgumentException('Bundle quantity must fit an unsigned small integer and require at least two items.');
        }

        if (! self::isMoney($totalPrice) || bccomp($totalPrice, '0.01', 2) < 0 || bccomp($totalPrice, '9999999999.99', 2) > 0) {
            throw new InvalidArgumentException('Bundle total must fit decimal(12,2) and be positive.');
        }

        $this->quantity = $quantity;
        $this->totalPrice = self::money($totalPrice);
    }

    /** @return numeric-string */
    public function effectiveUnitPrice(): string
    {
        return self::roundMoney(bcdiv($this->totalPrice, (string) $this->quantity, 3));
    }

    public function isCheaperThan(string $singleItemPrice): bool
    {
        if (! self::isMoney($singleItemPrice)) {
            return false;
        }

        return bccomp($this->effectiveUnitPrice(), $singleItemPrice, 2) < 0;
    }

    public static function fromLabel(string $label, string $singleItemPrice): ?self
    {
        if (! self::isMoney($singleItemPrice) || bccomp($singleItemPrice, '0.01', 2) < 0) {
            return null;
        }

        $singleItemPrice = self::money($singleItemPrice);
        $label = self::normalizeLabel($label);

        try {
            $offer = self::parseFixedTotal($label)
                ?? self::parseFreeItems($label, $singleItemPrice)
                ?? self::parsePayForFewer($label, $singleItemPrice)
                ?? self::parseLaterItemDiscount($label, $singleItemPrice)
                ?? self::parseTieredDiscount($label, $singleItemPrice);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($offer === null || ! $offer->isCheaperThan($singleItemPrice)) {
            return null;
        }

        return $offer;
    }

    private static function parseFixedTotal(string $label): ?self
    {
        if (preg_match('/^(\d+)\s+voor\s+€?\s*(\d+(?:\.\d{1,2})?)$/u', $label, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], $matches[2]);
    }

    /** @param numeric-string $singleItemPrice */
    private static function parseFreeItems(string $label, string $singleItemPrice): ?self
    {
        if (preg_match('/^(\d+)\s*\+\s*(\d+)\s+gratis$/u', $label, $matches) !== 1) {
            return null;
        }

        $paid = (int) $matches[1];
        $free = (int) $matches[2];

        if ($paid < 1 || $free < 1) {
            return null;
        }

        return new self($paid + $free, bcmul($singleItemPrice, (string) $paid, 2));
    }

    /** @param numeric-string $singleItemPrice */
    private static function parsePayForFewer(string $label, string $singleItemPrice): ?self
    {
        if (preg_match('/^(\d+)\s+halen\s+(\d+)\s+betalen$/u', $label, $matches) !== 1) {
            return null;
        }

        $quantity = (int) $matches[1];
        $paid = (int) $matches[2];

        if ($paid < 1 || $paid >= $quantity) {
            return null;
        }

        return new self($quantity, bcmul($singleItemPrice, (string) $paid, 2));
    }

    /** @param numeric-string $singleItemPrice */
    private static function parseLaterItemDiscount(string $label, string $singleItemPrice): ?self
    {
        if (preg_match('/^(\d+)e\s+halve\s+prijs$/u', $label, $matches) === 1) {
            $quantity = (int) $matches[1];
            $discount = '50';
        } elseif (preg_match('/^(\d+)e\s+(\d+(?:\.\d+)?)%\s+korting$/u', $label, $matches) === 1) {
            $quantity = (int) $matches[1];
            $discount = self::numeric($matches[2]);
        } else {
            return null;
        }

        if ($quantity < 2 || bccomp($discount, '0', 4) <= 0 || bccomp($discount, '100', 4) >= 0) {
            return null;
        }

        $discountedItem = self::roundMoney(bcmul($singleItemPrice, bcsub('1', bcdiv($discount, '100', 6), 6), 6));
        $fullPriceItems = bcmul($singleItemPrice, (string) ($quantity - 1), 2);

        return new self($quantity, bcadd($fullPriceItems, $discountedItem, 2));
    }

    /** @param numeric-string $singleItemPrice */
    private static function parseTieredDiscount(string $label, string $singleItemPrice): ?self
    {
        $tier = '\d+\s+stuks?\s+\d+(?:\.\d+)?%\s*(?:korting)?';

        if (preg_match('/^' . $tier . '(?:\s*,\s*' . $tier . ')*$/u', $label) !== 1) {
            return null;
        }

        preg_match_all('/(\d+)\s+stuks?\s+(\d+(?:\.\d+)?)%\s*(?:korting)?/u', $label, $matches, PREG_SET_ORDER);

        $best = null;

        foreach ($matches as $match) {
            $quantity = (int) $match[1];
            $discount = self::numeric($match[2]);

            if ($quantity < 2 || bccomp($discount, '0', 4) <= 0 || bccomp($discount, '100', 4) >= 0) {
                return null;
            }

            $subtotal = bcmul($singleItemPrice, (string) $quantity, 4);
            $total = self::roundMoney(bcmul($subtotal, bcsub('1', bcdiv($discount, '100', 6), 6), 6));
            $candidate = new self($quantity, $total);

            if ($best === null
                || bccomp($candidate->effectiveUnitPrice(), $best->effectiveUnitPrice(), 2) < 0
                || ($candidate->effectiveUnitPrice() === $best->effectiveUnitPrice() && $candidate->quantity < $best->quantity)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = preg_replace('/(?<=\d),(?=\d)/u', '.', $label) ?? $label;

        return preg_replace('/\s+/u', ' ', $label) ?? $label;
    }

    /** @phpstan-assert-if-true numeric-string $value */
    private static function isMoney(string $value): bool
    {
        return preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value) === 1;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function roundMoney(string $value): string
    {
        return bcdiv(bcadd($value, '0.005', 3), '1', 2);
    }

    /** @return numeric-string */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Expected a numeric promotion value.');
        }

        return $value;
    }
}
