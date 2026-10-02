<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use App\Enums\ProductDepartment;
use App\Enums\PromotionDepthBand;
use App\Enums\TrackingIdea;
use App\Models\ApiUsageDay;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TypeSafe's Jev model, for three jobs. `categorise()` places a product in the
 * taxonomy; `sameProduct()` judges whether other offers sell the same product
 * and pack; `promotionDepth()` judges how deep its promotions usually go.
 *
 * Categorising is one request
 * with a department Choice plus a leaf Choice per real department, scored by
 * `CategoryScorer`. `Other` gets no leaf question, because a Choice with one
 * option would score 1.0 and beat every real path. The same request asks
 * which getting-started idea the product covers.
 */
final readonly class TypeSafeClient
{
    public const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    private const string MODEL = 'jev-latest';

    public const string DEPARTMENT_QUESTION = 'department';

    public const string LEAF_QUESTION_PREFIX = 'leaf_';

    public const string TRACKING_IDEA_QUESTION = 'tracking_idea';

    public const string PROMOTION_DEPTH_QUESTION = 'promotion_depth';

    /** The Choice option for a product no getting-started idea covers. */
    public const string NO_TRACKING_IDEA = 'none';

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

        $payload = $this->send(CategorisationBudget::CATEGORISE, [
            'model' => self::MODEL,
            'state' => $this->state($product),
            'questions' => $this->questions(),
        ]);

        return CategoryScorer::verdict($payload);
    }

    /**
     * The chance that each candidate offer sells the same product as the
     * tracked one, in the same pack (in any pack for a candidate in
     * `$anyPackKeys`), as one Noul question per candidate in a single
     * request. A candidate the answer leaves out is left out here. Klarna
     * leads point at other sizes too, and a product compares sizes per unit.
     *
     * Pass `$quick` false for the longer timeout and the retry; see
     * `request()`.
     *
     * @param  array<string, array<string, string>>  $candidates  Keyed by a caller-chosen id.
     * @param  list<string>  $anyPackKeys
     * @return array<string, float>
     *
     * @throws TypeSafeRequestFailed
     */
    public function sameProduct(Product $product, ShopCheckPurpose $purpose, array $candidates, bool $quick = true, array $anyPackKeys = []): array
    {
        if ($candidates === []) {
            return [];
        }

        $product->loadMissing('shops');

        $questions = [];

        foreach ($candidates as $key => $candidate) {
            $questions[$key] = in_array((string) $key, $anyPackKeys, strict: true) ? [
                'type' => 'noul',
                'instructions' => [
                    'candidate' => $candidate,
                    'question' => 'Does `candidate` sell the same product as the tracked product in the state, in any pack size?',
                ],
                'criteria' => [
                    'true' => 'The same product, the same variant or flavour; the pack size may differ',
                    'false' => 'A different product, variant or flavour',
                ],
            ] : [
                'type' => 'noul',
                'instructions' => [
                    'candidate' => $candidate,
                    'question' => 'Does `candidate` sell the same product as the tracked product in the state, in the same pack size or in one of its `tracked_pack_sizes`, so that the prices compare like for like?',
                ],
                'criteria' => [
                    'true' => 'The same product, the same variant or flavour, and the same amount per pack',
                    'false' => 'A different product, variant, flavour, or pack size',
                ],
            ];
        }

        $payload = $this->send($purpose->value, [
            'model' => self::MODEL,
            'state' => [...$this->state($product), 'tracked_pack_sizes' => self::trackedPackSizes($product)],
            'questions' => $questions,
        ], quick: $quick);

        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $chances = [];

        foreach (array_keys($candidates) as $key) {
            $answer = $answers[$key] ?? null;
            $value = is_array($answer) ? ($answer['noul'] ?? null) : null;

            if (is_numeric($value)) {
                $chances[$key] = max(0.0, min(1.0, (float) $value));
            }
        }

        if ($chances === []) {
            throw new TypeSafeRequestFailed('TypeSafe answered without any same-product answer.');
        }

        return $chances;
    }

    /**
     * What `promotionDepth()` sends about a product: the shared state, with
     * each shop's price, pack, promotion and live multi-buy in place of the
     * plain offers, plus the category. Public so a caller can tell whether an
     * answer still describes the product.
     *
     * @return array<string, mixed>
     */
    public function promotionDepthState(Product $product): array
    {
        $product->loadMissing('shops');
        $state = $this->state($product);

        $state['offers'] = PromotionDepthQuestion::offers($product);

        $category = $product->category ?? $product->suggested_category;

        if ($category !== null) {
            $state['category'] = $category->label();
        }

        return $state;
    }

    /**
     * The chance of each `PromotionDepthBand` for the product `$state`
     * describes, as one Choice. A quick request: a person waits for it.
     *
     * @param  array<string, mixed>  $state  From `promotionDepthState()`.
     * @return array<string, float> Keyed by band value.
     *
     * @throws TypeSafeRequestFailed
     */
    public function promotionDepth(array $state): array
    {
        $criteria = [];

        foreach (PromotionDepthBand::cases() as $band) {
            $criteria[$band->value] = $band->rubric();
        }

        $payload = $this->send(ShopCheckPurpose::AlertSuggestion->value, [
            'model' => self::MODEL,
            'state' => $state,
            'questions' => [
                self::PROMOTION_DEPTH_QUESTION => [
                    'type' => 'choice',
                    'instructions' => 'How deep do the promotions on this product usually go, per item, at Dutch shops like the ones in the state? '
                        . 'Judge from the product, its category, its shops and any promotion they show now.',
                    'criteria' => $criteria,
                ],
            ],
        ], quick: true);

        return PromotionDepthQuestion::chances($payload);
    }

    /**
     * @param  string  $purpose  The usage row it counts under: a ShopCheckPurpose value or CategorisationBudget::CATEGORISE.
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws TypeSafeRequestFailed
     */
    private function send(string $purpose, array $body, bool $quick = false): array
    {
        try {
            $response = $this->request($quick)->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            ApiUsageDay::typeSafeCall($purpose, failed: true, payload: null);

            throw new TypeSafeRequestFailed('TypeSafe unreachable: ' . $e->getMessage(), previous: $e);
        }

        $payload = $response->json();
        ApiUsageDay::typeSafeCall($purpose, failed: ! $response->successful() || ! is_array($payload), payload: $payload);

        if (! $response->successful()) {
            throw new TypeSafeRequestFailed("TypeSafe answered {$response->status()}.", status: $response->status());
        }

        if (! is_array($payload)) {
            throw new TypeSafeRequestFailed('TypeSafe answered with a body that is not JSON.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * A quick request is one a person waits for: a short timeout and no
     * retry, because an unanswered check only means no warning.
     */
    private function request(bool $quick): PendingRequest
    {
        $request = Http::withToken(self::key())->acceptJson();

        if ($quick) {
            return $request->timeout(Config::integer('dipcatch.shop_checks.timeout_seconds'));
        }

        return $request
            ->timeout(Config::integer('dipcatch.categories.timeout_seconds'))
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

    /**
     * Every distinct pack the product's shops sell: a product can track a
     * 150 g and a 250 g pack side by side, and either one is a match.
     *
     * @return list<string>
     */
    public static function trackedPackSizes(Product $product): array
    {
        $sizes = [];

        foreach ($product->shops as $shop) {
            $size = self::packSize($shop);

            if ($size !== null) {
                $sizes[$size] = true;
            }
        }

        $sizes = array_keys($sizes);
        sort($sizes);

        return $sizes;
    }

    public static function packSize(Shop $shop): ?string
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

        $ideas = [];

        foreach (TrackingIdea::cases() as $idea) {
            $ideas[$idea->value] = $idea->rubric();
        }

        $questions[self::TRACKING_IDEA_QUESTION] = [
            'type' => 'choice',
            'instructions' => 'Which kind of repeat purchase is this product? Pick none when it is not something a household buys again and again.',
            'criteria' => [...$ideas, self::NO_TRACKING_IDEA => 'None of these: a one-off purchase, or a product no option above describes'],
        ];

        return $questions;
    }

    private static function key(): string
    {
        return trim(Config::string('services.typesafe.key'));
    }
}
