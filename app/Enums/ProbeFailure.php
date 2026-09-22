<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Caller-facing failure code emitted by {@see ProbeShopUrl}.
 *
 * Layer-2 vocabulary — finite, stable, and rendered to users via the
 * Livewire AddShop form. Layer-1 adapter diagnostics (e.g. `jsonld_no_price`,
 * `user_selector_no_match`) travel through {@see ProbeOutcome::$extractionReason}
 * when `errorCode === ExtractionFailed` — that side channel preserves the
 * detail without forcing every new adapter reason to update this enum.
 */
enum ProbeFailure: string
{
    case InvalidUrl = 'invalid_url';

    case ProbeRateLimited = 'probe_rate_limited';

    case RobotsDisallowed = 'robots_disallowed';

    case Blocked = 'blocked';

    case LocalThrottle = 'local_throttle';

    case HostRateLimited = 'host_rate_limited';

    case TemporaryFailure = 'temporary_failure';

    case HttpError = 'http_error';

    case ExtractionFailed = 'extraction_failed';

    case CurrencyMismatch = 'currency_mismatch';

    /**
     * Checkjebon-served host (ah.nl / boodschaapje.nl): the URL is
     * fine but the product has no row in the local daily dataset — or the
     * URL carries no recognizable product id, or the dataset was never
     * refreshed. The specific case travels in the outcome context `reason`.
     * No manual selector can help: there is no HTML to select from.
     */
    case NotInDataset = 'not_in_dataset';

    /**
     * A shop whose prices are never present in the page the server can
     * fetch — an app shell that renders its price client-side from an
     * endpoint the user's browser is authorized for and we are not. No
     * fetch and no manual selector can help, so the probe says so instead
     * of offering the selector flow. See {@see UnservableShops}.
     */
    case ShopNotServable = 'shop_not_servable';

    /**
     * Whether this wall is one worth keeping the URL behind.
     *
     * A page that cannot be read today is still a page that sells the thing,
     * and finding it was the slow part. Keeping it as a link preserves that
     * work and puts the URL on the weekly retry, which is how a shop that
     * stops refusing us gets picked up — see {@see ShopKind}.
     *
     * Not every failure qualifies. A rate limit is a wall that clears in
     * seconds, so offering to keep the link there would turn "wait a moment"
     * into a permanent second-class row. A URL that is not a URL, a page in
     * the wrong currency, and a product missing from the dataset are not
     * shops that refuse to be read at all — they are answers to a different
     * question.
     */
    public function isWorthKeepingAsLink(): bool
    {
        return match ($this) {
            self::Blocked, self::RobotsDisallowed, self::TemporaryFailure,
            self::HttpError, self::ExtractionFailed, self::ShopNotServable => true,
            self::InvalidUrl, self::ProbeRateLimited, self::LocalThrottle,
            self::HostRateLimited, self::CurrencyMismatch, self::NotInDataset => false,
        };
    }
}
