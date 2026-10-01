<?php declare(strict_types=1);

namespace App\Services\Suggestions;

use App\Support\PackSize;
use Illuminate\Support\Str;

/**
 * A normalized token set for one side of a suggestion match, plus the
 * Jaccard overlap between two of them.
 *
 * Pack size is canonicalized through {@see PackSize} before tokenizing, so
 * "150 g", "150 gram" and "Per 150 g" all reduce to the same two tokens.
 */
final readonly class QueryTokens
{
    /**
     * Words that make a different product of the same name, grouped so the
     * forms of one word count as one: a row that says "plantaardige" is the
     * same variant as a product that says "vegan". A variant on one side
     * only rules a row out, whatever the overlap.
     *
     * Organic is not here: shops label the same article bio, biologisch or
     * not at all, so it says too little to rule a row out.
     *
     * @var array<string, string> token => variant
     */
    private const array VARIANTS = [
        'vegan' => 'vegan', 'vegano' => 'vegan', 'vegana' => 'vegan', 'veganistisch' => 'vegan', 'veganistische' => 'vegan',
        'plantaardig' => 'vegan', 'plantaardige' => 'vegan', 'plantbased' => 'vegan',
        'vegetarisch' => 'vegetarian', 'vegetarische' => 'vegetarian', 'vegetarian' => 'vegetarian', 'veggie' => 'vegetarian', 'vega' => 'vegetarian',
        'halal' => 'halal',
        'light' => 'light', 'licht' => 'light', 'lichte' => 'light', 'zero' => 'light',
        'suikervrij' => 'sugar-free', 'suikervrije' => 'sugar-free',
        'glutenvrij' => 'gluten-free', 'glutenvrije' => 'gluten-free',
        'lactosevrij' => 'lactose-free', 'lactosevrije' => 'lactose-free',
        'alcoholvrij' => 'alcohol-free', 'alcoholvrije' => 'alcohol-free',
        'cafeinevrij' => 'decaf', 'cafeinevrije' => 'decaf', 'decaf' => 'decaf',
        'mini' => 'mini', 'minis' => 'mini', 'piccola' => 'mini', 'piccolissima' => 'mini',
    ];

    /**
     * @param  array<string, true>  $tokens
     * @param  PackSize|null  $size  The pack size, when one was given or parsed.
     */
    private function __construct(public array $tokens, public ?PackSize $size = null) {}

    public static function of(string $text, ?PackSize $size = null): self
    {
        $tokens = self::split($text);

        if ($size instanceof PackSize) {
            $tokens = [...$tokens, ...self::sizeTokens($size)];
        }

        return new self(array_fill_keys($tokens, true), $size);
    }

    /**
     * Catalogue rows carry free-text sizes; parse them the same way, and fall
     * back to raw tokens when the text does not parse.
     */
    public static function ofCatalogueRow(string $name, ?string $size): self
    {
        // No stated size (bol.com, some chains): read it from the name.
        $parsed = PackSize::resolve($size, authoritative: $size !== null, title: $name);

        if ($parsed instanceof PackSize) {
            return self::of($name, $parsed);
        }

        $tokens = [...self::split($name), ...self::split((string) $size)];

        return new self(array_fill_keys($tokens, true));
    }

    /**
     * Whether both sides name a pack size in the same unit, and the sizes
     * differ: a 300 g bar against a 100 g one. A size in another unit, or a
     * side without one, says nothing either way.
     */
    public function hasOtherSizeThan(self $other): bool
    {
        return $this->size instanceof PackSize
            && $other->size instanceof PackSize
            && $this->size->unit === $other->size->unit
            && ! $this->size->isSameSizeAs($other->size);
    }

    /**
     * Whether both sides name the same variants from {@see VARIANTS}, none
     * of them on one side only.
     */
    public function sameVariantAs(self $other): bool
    {
        return $this->variants() == $other->variants();
    }

    /**
     * @return array<string, true>
     */
    private function variants(): array
    {
        $variants = [];

        foreach (array_keys($this->tokens) as $token) {
            $variant = self::VARIANTS[(string) $token] ?? null;

            if ($variant !== null) {
                $variants[$variant] = true;
            }
        }

        ksort($variants);

        return $variants;
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    public function overlapWith(self $other): float
    {
        $union = count($this->tokens + $other->tokens);

        if ($union === 0) {
            return 0.0;
        }

        return count(array_intersect_key($this->tokens, $other->tokens)) / $union;
    }

    /**
     * The SQL prefilter a catalogue row must pass to be scored: two of the
     * three longest words of four letters or more. Two, so a row that leaves
     * out one word of the title ("dagelijkse") still matches on the others,
     * while a row that shares one common word ("classic") is not scored at
     * all. Shorter words make a useless `LIKE`: they match half the
     * catalogue. A short title ("7up 1 l") falls back to its longest word,
     * rather than to no suggestions.
     *
     * `lower(name) like ?` rather than a bare `like`: production runs
     * PostgreSQL, where `like` is case-sensitive. A sum of `CASE`s, because
     * PostgreSQL does not add booleans.
     *
     * @return array{0: literal-string, 1: list<string>}|null  The condition and its bindings.
     */
    public function prefilter(): ?array
    {
        $needles = $this->prefilterNeedles();

        if ($needles === []) {
            return null;
        }

        $like = 'CASE WHEN lower(name) like ? THEN 1 ELSE 0 END';

        return [
            match (count($needles)) {
                1 => "({$like}) >= 1",
                2 => "({$like} + {$like}) >= 2",
                default => "({$like} + {$like} + {$like}) >= 2",
            },
            array_map(static fn (string $needle): string => "%{$needle}%", $needles),
        ];
    }

    /**
     * Words with a digit ("1500", "500ml") are left out: they come from the
     * pack size, and catalogue names rarely state it the same way.
     *
     * @return list<string>
     */
    public function prefilterNeedles(): array
    {
        return $this->longestTokens(4, 3, withoutDigits: true) ?: $this->longestTokens(2, 1, withoutDigits: false);
    }

    /**
     * @return list<string>
     */
    private function longestTokens(int $minimumLength, int $count, bool $withoutDigits): array
    {
        // PHP casts a numeric array key to int, so "150" comes back as 150.
        $tokens = array_values(array_filter(
            array_map(strval(...), array_keys($this->tokens)),
            static fn (string $token): bool => mb_strlen($token) >= $minimumLength && ! ($withoutDigits && preg_match('/\d/', $token) === 1),
        ));

        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($tokens, 0, $count);
    }

    /**
     * @return list<string>
     */
    private static function sizeTokens(PackSize $size): array
    {
        $quantity = rtrim(rtrim(number_format($size->quantity, 2, '.', ''), '0'), '.');

        return [$quantity, $size->unit];
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        // To ASCII first, or "cafeïnevrij" splits into "cafe" and "nevrij".
        $normalized = preg_replace('/[^a-z0-9+]+/', ' ', mb_strtolower(Str::ascii($text))) ?? '';

        return array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => $token !== '',
        ));
    }
}
