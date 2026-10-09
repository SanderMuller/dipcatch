<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;

/**
 * The shops, departments and categories a search names, to offer as a
 * filter: "jumbo" finds no product called Jumbo, but the products at
 * jumbo.com.
 */
final readonly class FilterSuggestions
{
    private const int LIMIT = 3;

    /** One letter matches nearly every product, so the server search starts at two. */
    public const int MIN_SEARCH_LENGTH = 2;

    /**
     * Shops come first, a matching department hides its own categories, and
     * at most three come back. The caller passes only what it offers as a
     * filter.
     *
     * @param  list<string>  $shopHosts
     * @param  array<string, list<ProductCategory>>  $categoryGroups  department value => categories
     * @param  list<string>  $except  the filters already on
     * @return list<array{kind: 'shop'|'category', key: string, label: string}>
     */
    public static function for(string $search, array $shopHosts, array $categoryGroups, array $except): array
    {
        $term = mb_strtolower(trim($search));

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return [];
        }

        $namedHosts = ShopNames::hostsMatching($term);
        $matches = static fn (string $label): bool => str_contains(mb_strtolower($label), $term);
        $suggestions = [];

        $shops = array_filter($shopHosts, static fn (string $host): bool => $matches($host) || in_array($host, $namedHosts, strict: true));
        // A host that starts with the term first, as the header search has it.
        usort($shops, static fn (string $a, string $b): int => str_starts_with($b, $term) <=> str_starts_with($a, $term));

        foreach ($shops as $host) {
            $suggestions[] = ['kind' => 'shop', 'key' => $host, 'label' => $host];
        }

        foreach ($categoryGroups as $department => $categories) {
            $departmentLabel = ProductDepartment::from($department)->label();

            if ($categories !== [] && $matches($departmentLabel)) {
                $suggestions[] = ['kind' => 'category', 'key' => $department, 'label' => $departmentLabel];

                continue;
            }

            foreach ($categories as $category) {
                if ($matches($category->label())) {
                    $suggestions[] = ['kind' => 'category', 'key' => $category->value, 'label' => $category->label()];
                }
            }
        }

        $suggestions = array_filter($suggestions, static fn (array $suggestion): bool => ! in_array($suggestion['key'], $except, strict: true));

        return array_slice(array_values($suggestions), 0, self::LIMIT);
    }
}
