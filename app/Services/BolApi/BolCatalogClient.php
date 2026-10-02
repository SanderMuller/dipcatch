<?php declare(strict_types=1);

namespace App\Services\BolApi;

use App\Enums\ApiService;
use App\Models\ApiUsageDay;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * bol.com's Marketing Catalog API, for the Netherlands in Dutch.
 *
 * The login token is cached and reused until shortly before it expires:
 * bol rate-limits its login service far below the API and may block an IP
 * address that asks for a token per request. Every endpoint allows 10
 * requests a second; a 429 is thrown as {@see BolApiFailed} for the caller
 * to back off on, never retried here.
 */
final readonly class BolCatalogClient
{
    public const string BASE_URL = 'https://api.bol.com/marketing/catalog/v1';

    public const string TOKEN_URL = 'https://login.bol.com/token?grant_type=client_credentials';

    private const string TOKEN_CACHE_KEY = 'bol-api:token';

    private const int TIMEOUT_SECONDS = 10;

    public static function configured(): bool
    {
        return Config::string('services.bol.api.client_id') !== '';
    }

    /** The product with this barcode, with its best offer in the Netherlands; null when bol has none. */
    public function findByEan(string $ean): ?BolProduct
    {
        $ean = str_pad(ltrim($ean, '0'), 13, '0', STR_PAD_LEFT);

        if (preg_match('/^\d{13}$/', $ean) !== 1) {
            return null;
        }

        $product = $this->get("/products/{$ean}", ['include-offer' => 'true', 'include-image' => 'true']);

        return is_array($product) ? self::product($product) : null;
    }

    /** The barcode of a bol product id (the number in a product page URL); null when bol has no such product. */
    public function eanOf(string $bolProductId): ?string
    {
        if (preg_match('/^\d+$/', $bolProductId) !== 1) {
            return null;
        }

        $body = $this->get("/products/{$bolProductId}/to-ean", []);
        $ean = is_array($body) ? ($body['ean'] ?? null) : null;

        return is_string($ean) && $ean !== '' ? $ean : null;
    }

    /**
     * Products for a search term, most relevant first, each with its best
     * offer in the Netherlands.
     *
     * @return list<BolProduct>
     */
    public function search(string $term, int $limit = 10): array
    {
        $body = $this->get('/products/search', [
            'search-term' => $term,
            'page-size' => max(1, min(50, $limit)),
            'include-offer' => 'true',
            'include-image' => 'true',
        ]);

        $results = is_array($body) && is_array($body['results'] ?? null) ? $body['results'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $row): ?BolProduct => is_array($row) ? self::product($row) : null,
            $results,
        )));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null  null when bol has no such product
     */
    private function get(string $path, array $query, bool $retried = false): ?array
    {
        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->withHeaders(['Accept-Language' => 'nl'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::BASE_URL . $path, ['country-code' => 'NL'] + $query);
        } catch (ConnectionException $e) {
            ApiUsageDay::call(ApiService::Bol, self::purposeOf($path), failed: true);

            throw new BolApiFailed('bol.com API unreachable: ' . $e->getMessage(), 0, $e);
        }

        ApiUsageDay::call(ApiService::Bol, self::purposeOf($path), failed: ! $response->successful() && ! $response->notFound());

        if ($response->status() === 401 && ! $retried) {
            // A token bol revoked before its own expiry: log in once more.
            Cache::forget(self::TOKEN_CACHE_KEY);

            return $this->get($path, $query, retried: true);
        }

        if ($response->notFound()) {
            return null;
        }

        return self::body($response);
    }

    private static function purposeOf(string $path): string
    {
        return match (true) {
            $path === '/products/search' => 'search',
            str_ends_with($path, '/to-ean') => 'to-ean',
            default => 'by-ean',
        };
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // One login at a time: when the token expires, every queued lookup
        // would otherwise log in at once, which is what bol blocks.
        try {
            $token = Cache::lock(self::TOKEN_CACHE_KEY . ':login', self::TIMEOUT_SECONDS)->block(self::TIMEOUT_SECONDS, function (): string {
                $cached = Cache::get(self::TOKEN_CACHE_KEY);

                return is_string($cached) && $cached !== '' ? $cached : $this->logIn();
            });
        } catch (LockTimeoutException $e) {
            throw new BolApiFailed('Waited too long for the bol.com login.', 0, $e);
        }

        return is_string($token) ? $token : throw new BolApiFailed('bol.com login gave no token.');
    }

    private function logIn(): string
    {
        try {
            $response = Http::withBasicAuth(Config::string('services.bol.api.client_id'), Config::string('services.bol.api.client_secret'))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::TOKEN_URL);
        } catch (ConnectionException $e) {
            ApiUsageDay::call(ApiService::Bol, 'login', failed: true);

            throw new BolApiFailed('bol.com login unreachable: ' . $e->getMessage(), 0, $e);
        }

        // Counted apart: bol.com blocks bursts of logins.
        ApiUsageDay::call(ApiService::Bol, 'login', failed: ! $response->successful());

        $body = self::body($response);
        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new BolApiFailed('bol.com login answered without a token.');
        }

        $lifetime = is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 299;
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(30, $lifetime - 30));

        return $token;
    }

    /**
     * @return array<mixed>
     */
    private static function body(Response $response): array
    {
        if (! $response->successful()) {
            throw new BolApiFailed("bol.com answered {$response->status()}.", $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new BolApiFailed('bol.com answered without a JSON body.');
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function product(array $row): ?BolProduct
    {
        $ean = $row['ean'] ?? null;
        $url = $row['url'] ?? null;
        $offer = is_array($row['offer'] ?? null) ? $row['offer'] : [];
        $image = is_array($row['image'] ?? null) ? $row['image'] : [];

        if (! is_string($ean) || ! is_string($url)) {
            return null;
        }

        return new BolProduct(
            ean: $ean,
            title: is_string($row['title'] ?? null) ? $row['title'] : '',
            url: $url,
            price: is_numeric($offer['price'] ?? null) ? number_format((float) $offer['price'], 2, '.', '') : null,
            strikethroughPrice: is_numeric($offer['strikethroughPrice'] ?? null) ? number_format((float) $offer['strikethroughPrice'], 2, '.', '') : null,
            imageUrl: is_string($image['url'] ?? null) ? $image['url'] : null,
            deliveryDescription: is_string($offer['deliveryDescription'] ?? null) ? $offer['deliveryDescription'] : null,
        );
    }
}
