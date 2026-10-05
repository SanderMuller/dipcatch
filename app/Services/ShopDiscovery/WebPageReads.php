<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Enums\ProbeFailure;
use App\Enums\WebFindingStatus;
use App\Models\HiddenShop;
use App\Models\Product;
use App\Models\User;
use App\Models\WebDiscovery;
use App\Models\WebShopFinding;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Config;

/**
 * The page read of web shop discovery: one found page through the normal
 * add-shop probe, which stores no shop or product. See specs/web-shop-discovery.md §5.2.
 */
final readonly class WebPageReads
{
    private const array RETRYABLE = [ProbeFailure::LocalThrottle, ProbeFailure::HostRateLimited, ProbeFailure::TemporaryFailure];

    public function __construct(private ProbeShopUrl $probe) {}

    /**
     * Reads one page. Returns the seconds to wait before another try, or null
     * when the read reached a final state.
     */
    public function read(WebShopFinding $finding): ?int
    {
        $product = $finding->product;
        $owner = $product?->user;

        if (! $product instanceof Product || ! $owner instanceof User) {
            return null;
        }

        // The product changed while the read waited: this finding starts over
        // on the next run, and the state must not stay "running" meanwhile.
        if ($finding->status !== WebFindingStatus::PendingRead || $finding->fingerprint !== WebShopFinding::fingerprintFor($product)) {
            WebDiscovery::finishIfDone($product);

            return null;
        }

        $outcome = ($this->probe)($product, $finding->url, $owner, spendBudget: false, preferredPack: $finding->leadPackSize());

        if (in_array($outcome->errorCode, self::RETRYABLE, strict: true) && $finding->attempts + 1 < Config::integer('dipcatch.web_discovery.read_attempts')) {
            $delay = self::retryDelay($outcome);
            $finding->writeIfUnchanged(WebFindingStatus::PendingRead, ['attempts' => $finding->attempts + 1, 'next_attempt_at' => now()->addSeconds($delay)]);

            return $delay;
        }

        $finding->writeIfUnchanged(WebFindingStatus::PendingRead, self::result($product, $outcome) + ['attempts' => $finding->attempts + 1, 'next_attempt_at' => null]);
        self::afterRead($product);

        return null;
    }

    /** A read that gave up for good: out of tries, or the worker failed. */
    public function giveUp(WebShopFinding $finding, string $failure): void
    {
        $finding->writeIfUnchanged(WebFindingStatus::PendingRead, ['status' => WebFindingStatus::Unreadable, 'failure' => $failure, 'next_attempt_at' => null]);

        if ($finding->product instanceof Product) {
            self::afterRead($finding->product);
        }
    }

    private static function afterRead(Product $product): void
    {
        WebShopDiscovery::checkReadPages($product);
        WebDiscovery::finishIfDone($product);
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(Product $product, ProbeOutcome $outcome): array
    {
        if (! $outcome->isSuccess() || $outcome->normalizedUrl === null || $outcome->adapterKey === null) {
            return ['status' => WebFindingStatus::Unreadable, 'failure' => $outcome->errorCode->value ?? ($outcome->isSuccess() ? 'incomplete_probe' : $outcome->state)];
        }

        $draft = ShopDraft::fromOutcome($outcome, $outcome->normalizedUrl, $outcome->adapterKey);
        $addHost = UrlNormalizer::normalizeHost((string) parse_url($outcome->normalizedUrl, PHP_URL_HOST));
        $servedHost = $outcome->host === null ? $addHost : UrlNormalizer::normalizeHost($outcome->host);
        $page = [
            'add_url' => $outcome->normalizedUrl,
            'served_host' => $servedHost,
            'page_title' => $draft->title === null ? null : mb_substr($draft->title, 0, 255),
            'page_pack_quantity' => $draft->packSize?->quantity,
            'page_pack_unit' => $draft->packSize?->unit,
            'page_price' => $draft->trackedPrice(),
            'page_currency' => $draft->currency,
            'page_gtin' => $draft->gtin,
            'variant_key' => $outcome->pickedVariantKey,
            'read_at' => now(),
        ];

        // A page with the product's barcode still goes to the second check:
        // the barcode proves the product, not that the shop sells in the
        // shoppers' country.
        return $page + (self::rejection($product, $addHost, $servedHost, $draft) ?? ['status' => WebFindingStatus::Read]);
    }

    /**
     * @return array{status: WebFindingStatus, failure: string}|null
     */
    private static function rejection(Product $product, string $addHost, string $servedHost, ShopDraft $draft): ?array
    {
        $tracked = $product->shops->pluck('host')->all();
        $hidden = HiddenShop::hostsOf($product->user);

        $failure = match (true) {
            in_array($addHost, $tracked, strict: true) || in_array($servedHost, $tracked, strict: true) => 'tracked_host',
            WebResultFilter::isNotAShop($addHost) || WebResultFilter::isNotAShop($servedHost) => 'not_a_shop',
            HiddenShop::covers($hidden, $addHost) || HiddenShop::covers($hidden, $servedHost) => 'hidden_shop',
            $draft->consumerPriceIssue !== null => 'not_a_consumer_price',
            default => null,
        };

        return $failure === null ? null : ['status' => WebFindingStatus::Rejected, 'failure' => $failure];
    }

    private static function retryDelay(ProbeOutcome $outcome): int
    {
        $named = $outcome->context['retry_after_seconds'] ?? null;
        $delay = is_int($named) && $named > 0 ? $named : Config::integer('dipcatch.web_discovery.retry_fallback_seconds');

        return min($delay, Config::integer('dipcatch.web_discovery.retry_max_seconds'));
    }
}
