<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\Services\Checkjebon\CheckjebonResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

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

    /** Each unit as a code the detail card used, and the factor to reach it. */
    private const array UNIT_CODES = [
        'Gram' => ['g', '1'],
        'Kilogram' => ['kg', '1'],
        'Milliliter' => ['ml', '1'],
        'Centiliter' => ['ml', '10'],
        'Liter' => ['l', '1'],
        'Stuks' => ['st', '1'],
    ];

    /** @return array{query: string, variables: array{id: int}} */
    public static function body(string $productId): array
    {
        return ['query' => self::QUERY, 'variables' => ['id' => (int) $productId]];
    }

    /**
     * The product in a successful answer, or the miss the answer means.
     *
     * AH answers an unknown product id with a redacted error on the
     * `product` path itself. Any other error is an API failure: a field the
     * schema no longer has, or a field AH nulled because it failed to
     * resolve it. Read as complete, such a null would clear a bonus or a
     * pack size that still holds.
     *
     * @return array<mixed>|CheckjebonResult
     */
    public static function product(string $productId, Response $response): array|CheckjebonResult
    {
        $product = $response->json('data.product');
        $errors = $response->json('errors');

        if (is_array($product) && $errors === null) {
            return $product;
        }

        if (! is_array($product) && is_array($errors) && array_any($errors, static fn (mixed $error): bool => data_get($error, 'path') === ['product'])) {
            // Logged, so a run where every product is unknown is visible: a
            // failing product service may answer in the same shape.
            Log::info('AH API knows no such product.', ['product_id' => $productId]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET);
        }

        Log::warning('AH API product query failed.', ['product_id' => $productId, 'error' => data_get($errors, '0.message')]);

        return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
    }

    /**
     * `priceV2.now` is bonus-aware, an announced bonus included, as the
     * detail card's `currentPrice` was; `priceV2.was` is the price before it.
     * The one-item step of a tiered offer is the exception: `now` leaves it
     * out (see {@see AhPercentTiers::singleItemPrice()}).
     *
     * @param  array<mixed>  $product
     * @return array<string, mixed>
     */
    public static function toDetailPayload(array $product): array
    {
        $discount = data_get($product, 'priceV2.discount');
        $card = [
            'title' => data_get($product, 'title'),
            'images' => [['url' => data_get($product, 'imagePack.0.medium.url')]],
            'currentPrice' => data_get($product, 'priceV2.now.amount'),
            'priceBeforeBonus' => data_get($product, 'priceV2.was.amount'),
            'orderAvailabilityStatus' => data_get($product, 'availability.isOrderable') === false ? 'UNAVAILABLE' : 'IN_ASSORTMENT',
        ];

        // A null discount is no bonus, and clears one that ended. Without
        // the key the answer says nothing about it.
        if (is_array($product['priceV2'] ?? null) && array_key_exists('discount', $product['priceV2'])) {
            $card['isBonus'] = is_array($discount);
        }

        // Only a stated size is authoritative: a missing or empty one must
        // never clear stored pack data.
        if (is_string($product['salesUnitSize'] ?? null) && $product['salesUnitSize'] !== '') {
            $card['salesUnitSize'] = $product['salesUnitSize'];
        }

        if (is_array($discount)) {
            $card = [...$card, ...self::bonusFields($discount, data_get($product, 'priceV2.promotionLabel.tiers'))];
        }

        $payload = ['productCard' => $card];

        $netContent = self::netContent(data_get($product, 'tradeItem.contents.netContents'));

        // A size in a unit this cannot read leaves the trade item out, so the
        // stored sizes stay rather than clear.
        if (is_array($product['tradeItem'] ?? null) && $netContent !== false) {
            $payload['tradeItem'] = ['measurements' => ['netContent' => $netContent]];
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
     * False when an entry is in a form this cannot read.
     *
     * @return list<array{value: string, measurementUnitCode: array{value: string}}>|false|null
     */
    private static function netContent(mixed $entries): array|false|null
    {
        if (! is_array($entries)) {
            return null;
        }

        $netContent = [];

        foreach ($entries as $entry) {
            if (! is_string($entry) || preg_match('/^(\d+(?:\.\d+)?)\s+(\w+)$/u', trim($entry), $matches) !== 1 || ! is_numeric($matches[1]) || ! isset(self::UNIT_CODES[$matches[2]])) {
                Log::info('AH net content in an unknown form.', ['net_content' => $entry]);

                return false;
            }

            [$code, $factor] = self::UNIT_CODES[$matches[2]];
            $netContent[] = ['value' => bcmul($matches[1], $factor, 3), 'measurementUnitCode' => ['value' => $code]];
        }

        return $netContent;
    }
}
