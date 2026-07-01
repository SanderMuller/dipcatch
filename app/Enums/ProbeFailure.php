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
}
