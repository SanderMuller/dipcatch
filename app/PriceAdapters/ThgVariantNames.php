<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * The variant names a THG page (Myprotein, Lookfantastic) states in its page
 * state, for {@see VariantSizeNames}. Its JSON-LD names a variant after the
 * flavour alone, so two sizes of one flavour read the same.
 */
final readonly class ThgVariantNames
{
    /**
     * THG pages state each variant once per component in their page state:
     * `"sku":10530967,…,"choices":[{"optionKey":"Flavour",…,"title":"Chocolade
     * Munt"},{"optionKey":"Amount",…,"title":"2.5KG - 83servings"}]`. The name
     * joins the choices' titles: "Chocolade Munt, 2.5KG - 83servings".
     *
     * @return array<int|string, string>
     */
    public static function names(string $html): array
    {
        preg_match_all('/"sku":(\d+),"barcode":"[^"]*","inStock":(?:true|false),"maxPerOrder":[^,]*,"choices":(\[[^\]]*\])/', $html, $matches, PREG_SET_ORDER);
        $names = [];

        foreach ($matches as $match) {
            $choices = json_decode($match[2], true);
            $titles = is_array($choices) ? array_values(array_filter(array_map(
                static fn (mixed $choice): ?string => is_array($choice) && is_string($choice['title'] ?? null) && $choice['title'] !== '' ? $choice['title'] : null,
                $choices,
            ))) : [];

            if ($titles !== []) {
                $names[$match[1]] ??= implode(', ', $titles);
            }
        }

        return $names;
    }
}
