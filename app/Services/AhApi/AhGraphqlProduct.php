<?php declare(strict_types=1);

namespace App\Services\AhApi;

/**
 * One product from AH's GraphQL API, read into the product-card shape the
 * rest of {@see AhApiSource} was written against.
 *
 * The mobile detail endpoint (`product/detail/v4/fir/{id}`) answered 404 for
 * every product by 2026-11-20. The GraphQL API answers with the same anonymous
 * token. Its schema is not published and introspection is off, so every field
 * below was confirmed by a live query on 2026-11-20.
 *
 * GraphQL names no discount-label code. The code is read from the tier's own
 * words, as AH writes them on the shelf label: "1+1 gratis", "2 voor 2.99",
 * "2e halve prijs". A tiered-percentage offer is marked by its theme.
 */
final class AhGraphqlProduct
{
    public const string URL = 'https://api.ah.nl/graphql';

    private const string QUERY = <<<'GRAPHQL'
        query Product($id: Int!) {
          product(id: $id) {
            id
            title
            salesUnitSize
            imagePack { medium { url } }
            availability { isOrderable }
            priceV2 {
              now { amount }
              was { amount }
              discount {
                type
                theme
                description
                segmentType
                promotionType
                availability { startDate endDate }
              }
              promotionLabel { tiers { count percentage price unit description } }
            }
            tradeItem { contents { netContents } }
          }
        }
        GRAPHQL;

    /** The themes of AH's own national bonus. Any other theme enables no bundle. */
    private const array BONUS_THEMES = ['BONUS', 'TIERED_OFFER'];

    private const array UNIT_CODES = [
        'Gram' => 'g',
        'Kilogram' => 'kg',
        'Milliliter' => 'ml',
        'Liter' => 'l',
        'Stuks' => 'st',
    ];

    /** @return array{query: string, variables: array{id: int}} */
    public static function body(string $productId): array
    {
        return ['query' => self::QUERY, 'variables' => ['id' => (int) $productId]];
    }

    /**
     * `priceV2.now` is bonus-aware, an announced bonus included, as the
     * detail card's `currentPrice` was; `priceV2.was` is the price before it.
     *
     * @param  array<mixed>  $product
     * @return array<string, mixed>
     */
    public static function toDetailPayload(array $product): array
    {
        $discount = data_get($product, 'priceV2.discount');
        $card = [
            'webshopId' => data_get($product, 'id'),
            'title' => data_get($product, 'title'),
            'images' => [['url' => data_get($product, 'imagePack.0.medium.url')]],
            'currentPrice' => data_get($product, 'priceV2.now.amount'),
            'priceBeforeBonus' => data_get($product, 'priceV2.was.amount'),
            'orderAvailabilityStatus' => data_get($product, 'availability.isOrderable') === false ? 'UNAVAILABLE' : 'IN_ASSORTMENT',
        ];

        // A null discount is no bonus, and clears one that ended. A missing
        // discount is a partial answer, which says nothing about it.
        if (is_array($product['priceV2'] ?? null) && array_key_exists('discount', $product['priceV2'])) {
            $card['isBonus'] = is_array($discount);
        }

        // Only a stated size is authoritative: an empty one must never clear
        // stored pack data.
        if (is_string($product['salesUnitSize'] ?? null)) {
            $card['salesUnitSize'] = $product['salesUnitSize'];
        }

        if (is_array($discount)) {
            $card = [...$card, ...self::bonusFields($discount, data_get($product, 'priceV2.promotionLabel.tiers'))];
        }

        $payload = ['productCard' => $card];

        if (is_array($product['tradeItem'] ?? null)) {
            $payload['tradeItem'] = ['measurements' => ['netContent' => self::netContent(data_get($product, 'tradeItem.contents.netContents'))]];
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $discount
     * @return array<string, mixed>
     */
    private static function bonusFields(array $discount, mixed $tiers): array
    {
        $theme = data_get($discount, 'theme');
        $fields = [
            'bonusMechanism' => data_get($discount, 'description'),
            'discountType' => in_array($theme, self::BONUS_THEMES, true) ? 'AH' : $theme,
            'promotionType' => data_get($discount, 'promotionType'),
            'segmentType' => data_get($discount, 'segmentType'),
            'isVirtualBundle' => data_get($discount, 'type') === 'Bundle',
            'discountLabels' => is_array($tiers)
                ? array_map(static fn (mixed $tier): array => self::discountLabel($tier, $theme === 'TIERED_OFFER'), $tiers)
                : [],
        ];

        // Keys only when AH states the dates, as the detail card had them: a
        // supplied but malformed date must still reject the window.
        foreach (['bonusStartDate' => 'startDate', 'bonusEndDate' => 'endDate'] as $key => $field) {
            $date = data_get($discount, 'availability.' . $field);

            if ($date !== null) {
                $fields[$key] = $date;
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function discountLabel(mixed $tier, bool $tiered): array
    {
        $count = data_get($tier, 'count');
        $description = data_get($tier, 'description');
        $words = is_string($description) ? mb_strtolower(trim($description)) : '';
        $label = ['code' => 'UNKNOWN', 'count' => $count];

        if ($tiered) {
            return [...$label, 'code' => 'DISCOUNT_TIERED_PERCENT', 'percentage' => data_get($tier, 'percentage'), 'unit' => data_get($tier, 'unit')];
        }

        if (preg_match('/^(\d+)\s*\+\s*(\d+)\s+gratis$/u', $words, $matches) === 1) {
            return [...$label, 'code' => 'DISCOUNT_X_PLUS_Y_FREE', 'freeCount' => (int) $matches[2]];
        }

        if (preg_match('/^\d+\s+voor\s/u', $words) === 1) {
            return [...$label, 'code' => 'DISCOUNT_X_FOR_Y', 'price' => data_get($tier, 'price')];
        }

        if (preg_match('/^2e\s+halve\s+prijs$/u', $words) === 1) {
            return [...$label, 'code' => 'DISCOUNT_ONE_HALF_PRICE'];
        }

        return $label;
    }

    /**
     * `560.0 Gram` becomes `[value 560.0, unit g]`, the detail card's shape.
     *
     * @return list<array{value: string, measurementUnitCode: array{value: string}}>|null
     */
    private static function netContent(mixed $entries): ?array
    {
        if (! is_array($entries)) {
            return null;
        }

        $netContent = [];

        foreach ($entries as $entry) {
            if (is_string($entry) && preg_match('/^(\d+(?:\.\d+)?)\s+(\w+)$/u', trim($entry), $matches) === 1 && isset(self::UNIT_CODES[$matches[2]])) {
                $netContent[] = ['value' => $matches[1], 'measurementUnitCode' => ['value' => self::UNIT_CODES[$matches[2]]]];
            }
        }

        return $netContent;
    }
}
