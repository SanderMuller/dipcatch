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
     */
    private function __construct(public array $tokens) {}

    public static function of(string $text, ?PackSize $size = null): self
    {
        $tokens = self::split($text);

        if ($size instanceof PackSize) {
            $tokens = [...$tokens, ...self::sizeTokens($size)];
        }

        return new self(array_fill_keys($tokens, true));
    }

    /**
     * Catalogue rows carry free-text sizes; parse them the same way, and fall
     * back to raw tokens when the text does not parse.
     */
    public static function ofCatalogueRow(string $name, ?string $size): self
    {
        $parsed = PackSize::resolve($size, authoritative: true, title: null);

        if ($parsed instanceof PackSize) {
            return self::of($name, $parsed);
        }

        $tokens = [...self::split($name), ...self::split((string) $size)];

        return new self(array_fill_keys($tokens, true));
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
     * The longest token, used as the SQL prefilter needle. Short tokens make
     * a useless `LIKE` — they match half the catalogue.
     */
    public function longestToken(int $minimumLength): ?string
    {
        $longest = '';

        foreach (array_keys($this->tokens) as $token) {
            // PHP casts a numeric array key to int, so "150" comes back as 150.
            $token = (string) $token;

            if (mb_strlen($token) >= $minimumLength && mb_strlen($token) > mb_strlen($longest)) {
                $longest = $token;
            }
        }

        return $longest === '' ? null : $longest;
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
