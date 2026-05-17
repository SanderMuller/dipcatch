# Failure-Code Enum Migration

## Overview

Failure / status codes flow through three layers as **raw strings** today, each with its own vocabulary:

1. `ExtractionResult::failureReason` — adapter-internal diagnostic (`jsonld_no_price`, `user_selector_no_match`, …).
2. `ProbeOutcome::errorCode` — caller-facing Add-Shop probe outcome (`invalid_url`, `currency_mismatch`, `extraction_failed`, …).
3. `PriceCheck::status` — persisted check classification (`ok`, `blocked`, `5xx`, `robots_disallowed`, `failed`, …). **Already** modelled as `App\Enums\ScrapeStatus`, but the producing call sites (`CheckShopPrice::failureOutcome`) still write raw strings that happen to match the enum's `->value`.

Goal: stop the stringly-typed drift. Land enums where the vocabulary is finite + caller-facing, keep strings where the vocabulary is open-ended + diagnostic, and make existing enums (ScrapeStatus) the single source of truth in their layer.

This spec is a refactor — no behavior change, no DB migration, all tests must continue to pass.

This is a cleanup spec, not on the launch-readiness build order in `specs/README.md`.

---

## Current state

### Layer 1 — `ExtractionResult::failureReason` (string today)

Producer sites (`grep -rn "ExtractionResult::failed("`):

| File | Codes |
|---|---|
| `app/PriceAdapters/AdapterResolver.php:67` | `no_adapter_matched` |
| `app/PriceAdapters/JsonLdAdapter.php` | `jsonld_no_offer`, `jsonld_no_price`, `jsonld_no_currency` |
| `app/PriceAdapters/MicrodataAdapter.php` | `microdata_no_price`, `microdata_invalid_price`, `microdata_no_currency` |
| `app/PriceAdapters/OpenGraphAdapter.php` | `og_invalid_price`, `og_no_currency` |
| `app/PriceAdapters/UserSelectorAdapter.php` | `user_selector_invalid`, `user_selector_no_match`, `user_selector_no_price`, `user_selector_no_currency` |
| `app/PriceAdapters/Hosts/HostAdapter.php:58` | **dynamic** — `{adapter_key}_extraction_failed` |

Plus the special-case ambiguous reason `multiple_variants` written by `ExtractionResult::ambiguous()`.

The dynamic key in `HostAdapter` is the awkward bit: it widens the vocabulary by one entry per host adapter and would force every new host adapter to add an enum case (or break the type contract).

### Layer 2 — `ProbeOutcome::errorCode` (string today)

Producer sites (`grep -rn "ProbeOutcome::failed("`):

| File:line | Code |
|---|---|
| `app/Actions/Shops/ProbeShopUrl.php:48` | `invalid_url` |
| `:58` | `probe_rate_limited` |
| `:64` | `robots_disallowed` |
| `:66` | `blocked` |
| `:68` | `local_throttle` *or* `host_rate_limited` (branch on `RateLimitedByHost->source`) |
| `:73` | `temporary_failure` |
| `:75` | `http_error` |
| `:100` | passes through `$extraction->failureReason` from Layer 1, or `extraction_failed` if null |
| `:108` | `currency_mismatch` |

Finite set of ~10 caller-facing codes — clean enum candidate. **But** `:100` pipes a Layer-1 string through, so making `errorCode` strictly enum-typed requires a design decision (see Open Questions).

### Layer 3 — `PriceCheck::status` (`ScrapeStatus` enum exists; producers still write strings)

`CheckShopPrice::failureOutcome()` (`app/Jobs/CheckShopPrice.php:185-189` after the rate-limit fix) match expression:

```php
$status = match (true) {
    $e instanceof RobotsDisallowed => 'robots_disallowed',
    $e instanceof Blocked          => 'blocked',
    $e instanceof TemporaryFailure => '5xx',
    $e instanceof HttpError        => ScrapeStatus::HttpError->value,  // already enum
    default                        => 'failed',
};
```

Inconsistent: one arm uses `ScrapeStatus::HttpError->value`, the rest use string literals that happen to match enum cases.

Consumers (`grep -rn "->status === '...'"`): nothing in production switches on these strings — they're written to the DB and read back by the UI. Tests assert exact strings, however.

---

## Phases

### Phase 1 — Tighten `CheckShopPrice::failureOutcome()` against `ScrapeStatus`

Lowest-risk pass. Pure substitution of equivalent enum values.

1. In `app/Jobs/CheckShopPrice.php`, rewrite the `failureOutcome()` match:
   ```php
   $status = match (true) {
       $e instanceof RobotsDisallowed => ScrapeStatus::RobotsDisallowed,
       $e instanceof Blocked          => ScrapeStatus::Blocked,
       $e instanceof TemporaryFailure => ScrapeStatus::TransientServerError,
       $e instanceof HttpError        => ScrapeStatus::HttpError,
       default                        => ScrapeStatus::Failed,
   };
   return [
       'status' => $status->value,
       …
   ];
   ```
2. Repeat for `genericFailure()` (uses `ScrapeStatus::HttpError->value` already — just normalise the assignment shape).
3. Repeat for the success outcome inside `fetchAndExtract()` — currently `'status' => ScrapeStatus::Ok->value` is fine; standardize on producing `ScrapeStatus` cases and `->value`-ing only at the array boundary.
4. Run the affected tests:
   ```bash
   vendor/bin/pest tests/Feature/Shops/CheckShopPriceJobTest.php --compact
   ```

No test changes expected — assertions are against `->last_status` (string from DB), which still equals the same `->value`.

### Phase 2 — Introduce `App\Enums\ProbeFailure`

1. Create `app/Enums/ProbeFailure.php`:
   ```php
   <?php declare(strict_types=1);

   namespace App\Enums;

   enum ProbeFailure: string
   {
       case InvalidUrl         = 'invalid_url';
       case RateLimitedByUser  = 'probe_rate_limited';
       case RobotsDisallowed   = 'robots_disallowed';
       case Blocked            = 'blocked';
       case LocalThrottle      = 'local_throttle';
       case HostRateLimited    = 'host_rate_limited';
       case TemporaryFailure   = 'temporary_failure';
       case HttpError          = 'http_error';
       case ExtractionFailed   = 'extraction_failed';
       case CurrencyMismatch   = 'currency_mismatch';
   }
   ```
2. Change `ProbeOutcome::errorCode` type from `?string` to `?ProbeFailure`.
   Update `ProbeOutcome::failed()` signature: `public static function failed(ProbeFailure $errorCode, ?array $context = null): self`.
3. Rewrite producers in `app/Actions/Shops/ProbeShopUrl.php` to pass enum cases.
4. **Layer-1 passthrough at `ProbeShopUrl.php:100`** — `$extraction->failureReason` is a Layer-1 string that cannot map to `ProbeFailure` cleanly. Resolve per Open Question Q1. Default plan: always collapse to `ProbeFailure::ExtractionFailed` and surface the raw reason in the `context` array (`['extraction_reason' => $extraction->failureReason]`) — preserves diagnostic detail without bleeding Layer-1 vocabulary into Layer-2.
5. Update consumers:
   - `app/Livewire/Shops/AddShop.php` — any switch on `$outcome->errorCode` against string literals.
   - Tests in `tests/Feature/Shops/ProbeShopUrlTest.php` and Livewire tests — replace `->toBe('invalid_url')` with `->toBe(ProbeFailure::InvalidUrl)`.
6. Run:
   ```bash
   vendor/bin/pest tests/Feature/Shops --compact
   ```

### Phase 3 — `ExtractionResult::failureReason`: decide & implement

Resolve Open Question Q2 first. Default plan: **keep as `?string`** because of the dynamic `{adapter_key}_extraction_failed` pattern in `HostAdapter`. The reason is adapter-private diagnostic, not a stable API surface — the only external consumer is the `extraction_reason` context bag from Phase 2.

If Q2 closes the other way (strict enum), the work is bigger — requires per-adapter cases + the dynamic `HostAdapter` reason eliminated or refactored.

### Phase 4 — Verify

1. `vendor/bin/pest --compact` — full suite passes (baseline 374 tests / 372 passed / 2 skipped / 1032 assertions).
2. `vendor/bin/pint --dirty --format agent` — clean.
3. `vendor/bin/phpstan analyse --memory-limit=2G` — clean. PHPStan should now catch any stray string passed where `ProbeFailure` is expected.

---

## Open Questions

- **Q1 (blocks Phase 2):** when Layer-1 extraction fails inside the probe path (`ProbeShopUrl.php:100`), should `ProbeOutcome::errorCode` (Layer-2) be a strict `ProbeFailure` enum and the underlying Layer-1 reason move to `context['extraction_reason']`, or should `errorCode` be a `ProbeFailure | string` union that lets the raw Layer-1 string through? **Default:** strict enum + context bag — keeps the public outcome shape narrow, preserves diagnostic detail.
- **Q2 (blocks Phase 3):** should `ExtractionResult::failureReason` become a strict enum (e.g. `ExtractionFailure`) or stay `?string` for adapter-private diagnostics? **Default:** stay `?string`. The `HostAdapter::extractFromHtml` failure path generates `{adapter_key}_extraction_failed` dynamically, which doesn't fit an enum cleanly; the reason is never assertion-relevant in production code, only in tests + UI debug.
- **Q3 (cosmetic):** include the special `multiple_variants` case in any new enum? It's emitted by `ExtractionResult::ambiguous()` (a *non-failure* state) but it's the `failureReason` slot of that constructor for legacy reasons. **Default:** leave alone for this spec; revisit if `ExtractionResult` itself gets refactored.

---

## Findings

(filled during implementation)
