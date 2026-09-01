<?php declare(strict_types=1);

namespace App\Support;

/**
 * Parses a free-text pack size (`"200 g"`, `"6 x 250 ml"`, `"20 stuks"`) into
 * a normalized quantity + unit, and renders a unit price from it.
 *
 * See specs/unit-pricing.md Section 2 for the normative five-step algorithm
 * this implements.
 */
final readonly class PackSize
{
    /**
     * Mass unit aliases, ordered so a longer alias is tried before any alias
     * that is one of its prefixes (e.g. `gram` before `gr` before `g`) —
     * required for correct regex alternation matching.
     *
     * @var array<string, float> alias => multiplier to grams
     */
    private const array MASS_UNITS = [
        'kilogram' => 1000.0,
        'kilo' => 1000.0,
        'kg' => 1000.0,
        'gram' => 1.0,
        'gr' => 1.0,
        'g' => 1.0,
    ];

    /**
     * Volume unit aliases, same longest-alias-first ordering constraint as
     * {@see MASS_UNITS}.
     *
     * @var array<string, float> alias => multiplier to milliliters
     */
    private const array VOLUME_UNITS = [
        'milliliter' => 1.0,
        'centiliter' => 10.0,
        'deciliter' => 100.0,
        'liter' => 1000.0,
        'ltr' => 1000.0,
        'ml' => 1.0,
        'dl' => 100.0,
        'cl' => 10.0,
        'l' => 1000.0,
    ];

    /**
     * Piece vocabulary, singular + plural. `plakken` is deliberately excluded
     * — cheese fat-percentage markers (`"48+ plakken"`) must never become 48
     * pieces. Ordered longest-alias-first, same constraint as MASS_UNITS.
     *
     * @var list<string>
     */
    private const array PIECE_WORDS = [
        'tabletten', 'rollen', 'zakjes', 'tablet', 'vellen',
        'stuks', 'zakje', 'stuk', 'pack', 'rol', 'vel', 'st',
    ];

    /**
     * A number token, comma-decimal aware, that never starts a size token
     * when directly suffixed with `+` or `%` (`"48+"`, `"40%"`).
     */
    private const string NUMBER = '(\d+(?:[.,]\d+)?)(?![%+])';

    private function __construct(
        public float $quantity,
        public string $unit,
    ) {}

    public static function parse(?string $text): ?self
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $massAlt = self::alternation(array_keys(self::MASS_UNITS));
        $volumeAlt = self::alternation(array_keys(self::VOLUME_UNITS));
        $pieceAlt = self::alternation(self::PIECE_WORDS);

        // Sheets (`vel`/`vellen`) are per-roll detail, not a separate pack
        // count: drop them whenever another piece token exists anywhere in
        // the string, before multipack detection *and* bucket collection —
        // otherwise "8 rollen à 200 vel" would resolve as an à-multipack
        // (1600 vel) instead of the spec's intended 8 rollen.
        $nonVelPieceAlt = self::alternation(array_values(array_diff(self::PIECE_WORDS, ['vel', 'vellen'])));
        if (preg_match('/' . self::NUMBER . '\s*(?:' . $nonVelPieceAlt . ')\b/iu', $text) === 1) {
            $text = preg_replace('/' . self::NUMBER . '\s*(?:vellen|vel)\b/iu', ' ', $text) ?? $text;
        }

        // Step 1: whole-string rejects.
        if (preg_match('/\d[\d.,]*\s*[-\x{2013}]\s*\d/u', $text) === 1) {
            return null;
        }

        if (preg_match('/' . self::NUMBER . '\s*(?:' . $massAlt . '|' . $volumeAlt . ')\b\s*\+\s*\d/iu', $text) === 1) {
            return null;
        }

        // Step 2: multipack patterns.
        $sizeUnitAlt = $massAlt . '|' . $volumeAlt . '|' . $pieceAlt;

        preg_match_all('/' . self::NUMBER . '\s*[x\x{00D7}]\s*' . self::NUMBER . '\s*(' . $sizeUnitAlt . ')\b/iu', $text, $formA, PREG_SET_ORDER);
        preg_match_all('/' . self::NUMBER . '\s*(?:\S+\s+)?\x{00E0}\s*' . self::NUMBER . '\s*(' . $sizeUnitAlt . ')\b/iu', $text, $formB, PREG_SET_ORDER);
        preg_match_all('/' . self::NUMBER . '-pack\b/i', $text, $formC, PREG_SET_ORDER);

        $multipackCount = count($formA) + count($formB) + count($formC);

        if ($multipackCount > 1) {
            return null;
        }

        if ($multipackCount === 1) {
            if ($formA !== []) {
                return self::resolveCountedMultipack(array_values($formA[0]));
            }

            if ($formB !== []) {
                return self::resolveCountedMultipack(array_values($formB[0]));
            }

            return self::resolvePackMultipack($formC[0], $text, $massAlt, $volumeAlt);
        }

        // Step 3-5: collect size tokens into buckets and pick by precedence.
        $massValues = self::collectValues($text, $massAlt, self::MASS_UNITS);
        if ($massValues !== []) {
            return self::fromDistinctBucket($massValues, 'g');
        }

        $volumeValues = self::collectValues($text, $volumeAlt, self::VOLUME_UNITS);
        if ($volumeValues !== []) {
            return self::fromDistinctBucket($volumeValues, 'ml');
        }

        $pieceValues = self::collectValues($text, $pieceAlt, units: null);
        if ($pieceValues !== []) {
            return self::fromDistinctBucket($pieceValues, 'piece');
        }

        return null;
    }

    /**
     * Rebuild a value object from already-normalized storage (the `shops`
     * pack columns). Returns null for an unknown unit or an unusable
     * quantity, so bad stored data renders no unit price instead of a wrong
     * one.
     */
    public static function of(float $quantity, string $unit): ?self
    {
        if (! in_array($unit, ['g', 'ml', 'piece'], true)) {
            return null;
        }

        return self::make($quantity, $unit);
    }

    /**
     * Resolve the pack size a snapshot implies. The structured size wins; the
     * product title is only parsed as a fallback when the source was NOT
     * authoritative — an authoritative empty or unparseable size must stay
     * null so persistence can clear stale pack data (spec Section 4).
     */
    public static function resolve(?string $packSize, bool $authoritative, ?string $title): ?self
    {
        $parsed = self::parse($packSize);

        if ($parsed !== null || $authoritative) {
            return $parsed;
        }

        return self::parse($title);
    }

    /**
     * `price / quantity × 1000` for mass/volume, `price / count` for pieces.
     * Returns a plain decimal string (no thousands separator) with two
     * decimals, or null when the price or the quantity is not usable.
     */
    public function unitPriceFor(string $price): ?string
    {
        if (! is_numeric($price)) {
            return null;
        }

        $priceValue = (float) $price;

        if ($priceValue <= 0 || $this->quantity <= 0) {
            return null;
        }

        $result = $this->unit === 'piece'
            ? $priceValue / $this->quantity
            : $priceValue / $this->quantity * 1000;

        return number_format($result, 2, '.', '');
    }

    public function label(): string
    {
        return match ($this->unit) {
            'g' => '/kg',
            'ml' => '/l',
            default => '/stuk',
        };
    }

    /**
     * Resolve a `<count> [x×à] <count>-<unit>` match (Form A/B): the two
     * counts multiply, and the unit determines the resulting bucket.
     *
     * @param list<string> $match
     */
    private static function resolveCountedMultipack(array $match): ?self
    {
        $count1 = self::toNumber($match[1]);
        $count2 = self::toNumber($match[2]);
        $unit = strtolower($match[3]);
        $total = $count1 * $count2;

        if (array_key_exists($unit, self::MASS_UNITS)) {
            return self::make($total * self::MASS_UNITS[$unit], 'g');
        }

        if (array_key_exists($unit, self::VOLUME_UNITS)) {
            return self::make($total * self::VOLUME_UNITS[$unit], 'ml');
        }

        return self::make($total, 'piece');
    }

    /**
     * Resolve a `<count>-pack` match (Form C). A lone accompanying mass or
     * volume token multiplies through (`"6-pack 330 ml"` → 1980 ml); with no
     * such token the pack count itself is the piece count.
     *
     * @param list<string> $match
     */
    private static function resolvePackMultipack(array $match, string $text, string $massAlt, string $volumeAlt): ?self
    {
        $packCount = self::toNumber($match[1]);

        $massValues = self::collectValues($text, $massAlt, self::MASS_UNITS);
        if ($massValues !== []) {
            $distinct = array_unique(array_map(static fn (float $v): string => (string) round($v, 6), $massValues));

            return count($distinct) === 1
                ? self::make($packCount * $massValues[0], 'g')
                : null;
        }

        $volumeValues = self::collectValues($text, $volumeAlt, self::VOLUME_UNITS);
        if ($volumeValues !== []) {
            $distinct = array_unique(array_map(static fn (float $v): string => (string) round($v, 6), $volumeValues));

            return count($distinct) === 1
                ? self::make($packCount * $volumeValues[0], 'ml')
                : null;
        }

        return self::make($packCount, 'piece');
    }

    /**
     * @param non-empty-list<float> $values normalized quantities in the chosen bucket
     */
    private static function fromDistinctBucket(array $values, string $unit): ?self
    {
        $distinct = array_unique(array_map(static fn (float $v): string => (string) round($v, 6), $values));

        if (count($distinct) !== 1) {
            return null;
        }

        return self::make($values[0], $unit);
    }

    /**
     * Collect every size-token value for one unit alternation in the text,
     * normalized to grams/milliliters, or left as-is for pieces (`$units`
     * null).
     *
     * @param array<string, float>|null $units
     * @return list<float>
     */
    private static function collectValues(string $text, string $alt, ?array $units): array
    {
        preg_match_all('/' . self::NUMBER . '\s*(' . $alt . ')\b/iu', $text, $matches, PREG_SET_ORDER);

        $values = [];

        foreach ($matches as $match) {
            $amount = self::toNumber($match[1]);
            $unit = strtolower($match[2]);
            $values[] = $units === null ? $amount : $amount * $units[$unit];
        }

        return $values;
    }

    private static function make(float $quantity, string $unit): ?self
    {
        if (! is_finite($quantity) || $quantity <= 0) {
            return null;
        }

        if ($unit === 'piece' && fmod($quantity, 1.0) !== 0.0) {
            return null;
        }

        return new self($quantity, $unit);
    }

    private static function toNumber(string $raw): float
    {
        return (float) str_replace(',', '.', $raw);
    }

    /**
     * @param list<string> $aliases
     */
    private static function alternation(array $aliases): string
    {
        return implode('|', array_map(
            static fn (string $alias): string => preg_quote($alias, '/'),
            $aliases,
        ));
    }
}
