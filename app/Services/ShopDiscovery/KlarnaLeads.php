<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Support\PackSize;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads the shops a Klarna product page lists.
 *
 * Klarna is a comparison site: its price is another shop's. The page embeds
 * its offer list as JSON (`<script id="initial_payload">`), with each shop's
 * website in the `merchantUrl` parameter of its `otcUrl`. Each offer's own
 * `url` is a paid click redirect that robots.txt disallows, so it is never
 * read or followed. The payload is not a published interface; when it is
 * missing the reader logs it, so a changed page shows rather than just
 * yielding fewer suggestions.
 */
final class KlarnaLeads
{
    /** A Klarna product page, the one Klarna path DipCatch reads. */
    public static function isKlarnaPage(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($host) || ! is_string($path)) {
            return false;
        }

        $host = strtolower($host);

        return ($host === 'klarna.com' || str_ends_with($host, '.klarna.com'))
            && preg_match('#^/[a-z]{2}/shopping/pl/#', $path) === 1;
    }

    /**
     * @return list<ShopLead>
     */
    public static function fromHtml(string $html, string $url): array
    {
        $block = self::offerBlock($html);

        if ($block === null) {
            Log::warning('klarna_payload_missing', ['url' => $url]);

            return [];
        }

        $leads = [];

        foreach ($block['offers'] as $offer) {
            $lead = is_array($offer) ? self::lead($offer, $block['merchants']) : null;

            if ($lead instanceof ShopLead) {
                $leads[] = $lead;
            }
        }

        return $leads;
    }

    /**
     * @param  array<mixed>  $offer
     * @param  array<mixed>  $merchants
     */
    private static function lead(array $offer, array $merchants): ?ShopLead
    {
        $merchantId = $offer['merchantId'] ?? null;
        $merchant = is_string($merchantId) || is_int($merchantId) ? ($merchants[$merchantId] ?? null) : null;
        $name = $offer['name'] ?? null;
        $amount = $offer['price']['amount'] ?? null;
        $currency = $offer['price']['currency'] ?? null;

        if (! is_array($merchant) || ! is_string($name) || ! is_numeric($amount) || ! is_string($currency)) {
            return null;
        }

        $host = self::host($merchant['otcUrl'] ?? null);

        if ($host === null) {
            return null;
        }

        // Klarna's shops write these; one line each, so no title can pose as
        // another line of the list an assistant reads.
        $title = Str::limit(Str::squish(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 150);

        return new ShopLead(
            shopName: is_string($merchant['name'] ?? null) ? Str::limit(Str::squish($merchant['name']), 60) : $host,
            host: $host,
            title: $title,
            price: number_format((float) $amount, 2, '.', ''),
            currency: strtoupper($currency),
            inStock: match ($offer['stockStatus'] ?? null) {
                'IN_STOCK' => true,
                'OUT_OF_STOCK' => false,
                default => null,
            },
            packSize: PackSize::resolve(packSize: null, authoritative: false, title: $title),
        );
    }

    private static function host(mixed $otcUrl): ?string
    {
        $query = is_string($otcUrl) ? parse_url($otcUrl, PHP_URL_QUERY) : null;

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $parameters);
        $merchantUrl = $parameters['merchantUrl'] ?? null;

        if (! is_string($merchantUrl) || $merchantUrl === '') {
            return null;
        }

        $host = parse_url(str_contains($merchantUrl, '://') ? $merchantUrl : "https://{$merchantUrl}", PHP_URL_HOST);

        try {
            return is_string($host) ? UrlNormalizer::normalizeHost($host) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The query whose data holds both the offers and the shops they belong to.
     *
     * @return array{offers: array<mixed>, merchants: array<mixed>}|null
     */
    private static function offerBlock(string $html): ?array
    {
        if (preg_match('#<script id="initial_payload"[^>]*>(.*?)</script>#s', $html, $match) !== 1) {
            return null;
        }

        $payload = json_decode($match[1], true);
        $queries = is_array($payload) ? ($payload['__DEHYDRATED_QUERY_STATE__']['queries'] ?? null) : null;

        foreach (is_array($queries) ? $queries : [] as $query) {
            $data = is_array($query) ? ($query['state']['data'] ?? null) : null;

            if (is_array($data) && is_array($data['offers'] ?? null) && is_array($data['merchants'] ?? null)) {
                return ['offers' => $data['offers'], 'merchants' => $data['merchants']];
            }
        }

        return null;
    }
}
