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
}
