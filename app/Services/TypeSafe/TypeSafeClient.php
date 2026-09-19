<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Enums\ProductDepartment;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Config as DipConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Places a product in the taxonomy through TypeSafe's Jev model: one request
 * with a department Choice plus a leaf Choice per real department, scored by
 * `CategoryScorer`. `Other` gets no leaf question, because a Choice with one
 * option would score 1.0 and beat every real path.
 */
final readonly class TypeSafeClient
{
    public const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const string MODEL = 'jev-latest';

    public const string DEPARTMENT_QUESTION = 'department';

    public const string LEAF_QUESTION_PREFIX = 'leaf_';

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    /**
     * @throws TypeSafeRequestFailed
     */
    public function categorise(Product $product): CategoryVerdict
    {
        $product->loadMissing('shops');

        $payload = $this->send([
            'model' => self::MODEL,
            'state' => $this->state($product),
            'questions' => $this->questions(),
        ]);

        return CategoryScorer::verdict($payload);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws TypeSafeRequestFailed
     */
    private function send(array $body): array
    {
        try {
            $response = $this->request()->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            throw new TypeSafeRequestFailed('TypeSafe unreachable: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new TypeSafeRequestFailed("TypeSafe answered {$response->status()}.", status: $response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TypeSafeRequestFailed('TypeSafe answered with a body that is not JSON.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function request(): PendingRequest
    {
        return Http::withToken(self::key())
            ->acceptJson()
            ->timeout(DipConfig::int('dipcatch.categories.timeout_seconds', 20))
            // One retry, and only where a second try can change the answer:
            // a dropped connection (a timeout is one), a rate limit, or a
            // server error. A 401 for a bad key is final and must not be
            // retried. Without the closure, retry() retries every failure.
            ->retry(2, 2000, static function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false);
    }

    /**
     * Pack size, GTIN and price are shop facts, taken from the cheapest shop
     * or else the first one, and left out when that shop lacks them.
     *
     * @return array<string, mixed>
     */
    private function state(Product $product): array
    {
        $state = ['product_title' => $product->title];

        $evidence = $this->evidenceShop($product);

        if ($evidence !== null) {
            $packSize = self::packSize($evidence);

            if ($packSize !== null) {
                $state['pack_size'] = $packSize;
            }

            if (is_string($evidence->gtin) && $evidence->gtin !== '') {
                $state['gtin'] = $evidence->gtin;
            }

            if ($evidence->current_price !== null) {
                $state['price'] = "{$evidence->current_price} {$product->currency}";
            }
        }

        $state['offers'] = $product->shops
            ->map(static fn (Shop $shop): array => [
                'shop' => $shop->host,
                'url_path' => (string) parse_url($shop->url, PHP_URL_PATH),
            ])
            ->values()
            ->all();

        return $state;
    }

    private static function packSize(Shop $shop): ?string
    {
        if ($shop->pack_quantity === null || ! is_string($shop->pack_unit) || $shop->pack_unit === '') {
            return null;
        }

        $quantity = rtrim(rtrim(number_format((float) $shop->pack_quantity, 2, '.', ''), '0'), '.');

        return "{$quantity} {$shop->pack_unit}";
    }

    private function evidenceShop(Product $product): ?Shop
    {
        if ($product->cheapest_shop_id !== null) {
            $cheapest = $product->shops->firstWhere('id', $product->cheapest_shop_id);

            if ($cheapest instanceof Shop) {
                return $cheapest;
            }
        }

        $first = $product->shops->sortBy('created_at')->first();

        return $first instanceof Shop ? $first : null;
    }

    /**
     * @return array<string, array{type: string, instructions: string, criteria: array<string, string>}>
     */
    private function questions(): array
    {
        $departments = [];

        foreach (ProductDepartment::cases() as $department) {
            $departments[$department->value] = $department->rubric();
        }

        $questions = [
            self::DEPARTMENT_QUESTION => [
                'type' => 'choice',
                'instructions' => 'Which department of a shopper\'s price tracker does this product belong to? '
                    . 'Judge the product itself from its title, the shops that sell it, its pack size and its price.',
                'criteria' => $departments,
            ],
        ];

        foreach (ProductDepartment::real() as $department) {
            $leaves = [];

            foreach ($department->categories() as $category) {
                $leaves[$category->leafKey()] = $category->rubric();
            }

            $questions[self::LEAF_QUESTION_PREFIX . $department->value] = [
                'type' => 'choice',
                'instructions' => "Assuming the product belongs to the department \"{$department->label()}\" ({$department->rubric()}), which kind is it?",
                'criteria' => $leaves,
            ];
        }

        return $questions;
    }

    private static function key(): string
    {
        return trim(DipConfig::string('services.typesafe.key'));
    }
}
