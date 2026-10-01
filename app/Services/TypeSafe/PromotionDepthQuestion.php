<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\Shop;

/** The parts of the `promotion_depth` Choice that are its own: the offers it shows Jev, and reading its answer. */
final class PromotionDepthQuestion
{
    /**
     * Each shop as the other questions show it, plus its price, pack and
     * any offer it shows now.
     *
     * @return list<array<string, string>>
     */
    public static function offers(Product $product): array
    {
        return $product->shops
            ->map(static fn (Shop $shop): array => array_filter([
                'shop' => (string) $shop->host,
                // The whole address and the barcode: a different variant or
                // barcode is another product, and the answer must not carry over.
                'url' => (string) $shop->url,
                'gtin' => (string) $shop->gtin,
                'price' => $shop->current_price === null ? '' : "{$shop->current_price} {$product->currency}",
                'pack_size' => (string) TypeSafeClient::packSize($shop),
                'promotion' => (string) $shop->promotion_label,
                'multi_buy' => self::multiBuy($shop, (string) $product->currency),
            ], static fn (string $value): bool => $value !== ''))
            ->values()
            ->all();
    }

    /** A multi-buy the shop applies now; a parsed offer that is not live says nothing. */
    private static function multiBuy(Shop $shop, string $currency): string
    {
        $offer = $shop->liveBundleOffer();

        return $offer === null ? '' : "{$offer->quantity} for {$offer->totalPrice} {$currency}";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float> Keyed by band value; a band the answer leaves out is left out.
     *
     * @throws TypeSafeRequestFailed
     */
    public static function chances(array $payload): array
    {
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $answer = $answers[TypeSafeClient::PROMOTION_DEPTH_QUESTION] ?? null;
        $probabilities = is_array($answer) ? ($answer['probabilities'] ?? null) : null;

        if (! is_array($probabilities)) {
            throw new TypeSafeRequestFailed('TypeSafe answered without the promotion depth answer.');
        }

        $chances = [];

        foreach (PromotionDepthBand::cases() as $band) {
            $value = $probabilities[$band->value] ?? null;

            if (is_numeric($value)) {
                $chances[$band->value] = max(0.0, min(1.0, (float) $value));
            }
        }

        return $chances;
    }
}
