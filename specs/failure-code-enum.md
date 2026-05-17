# Failure-Code Enum Migration

## Overview

Failure / status codes flow through three layers as **raw strings** today, each with its own vocabulary:

1. `ExtractionResult::failureReason` — adapter-internal diagnostic (`jsonld_no_price`, `user_selector_no_match`, …).
2. `ProbeOutcome::errorCode` — caller-facing Add-Shop probe outcome (`invalid_url`, `currency_mismatch`, `extraction_failed`, …) — but **also** load-bearing for the manual-selector fallback (`no_adapter_matched`, `user_selector_*` propagate from Layer 1 through this layer and drive UI state in `AddShop`).
3. `PriceCheck::status` — persisted check classification (`ok`, `blocked`, `5xx`, `robots_disallowed`, `parse_error`, `http_error`, `failed`, …). **Already** modelled as `App\Enums\ScrapeStatus`, but the producing call sites in `CheckShopPrice` still write raw strings that happen to match the enum's `->value`, and internal status comparisons inside the job also use raw strings.

Goal: stop the stringly-typed drift. Land enums where the vocabulary is finite + caller-facing, keep strings where the vocabulary is open-ended + diagnostic, and make existing enums (ScrapeStatus) the single source of truth in their layer. **Preserve the manual-selector fallback signal that AddShop currently relies on** — see Phase 2 design.

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

Plus `multiple_variants` written by `ExtractionResult::ambiguous()` (does **not** travel through the failure channel — `ambiguous` is a distinct state).

The dynamic key in `HostAdapter` widens the vocabulary by one entry per host adapter and is the main reason this layer stays open-ended.

### Layer 2 — `ProbeOutcome::errorCode` (string today)

Producer sites in `app/Actions/Shops/ProbeShopUrl.php`:

| Code | Origin |
|---|---|
| `invalid_url` | `parse_url` failure |
| `probe_rate_limited` | per-user limit on probes |
| `robots_disallowed` | `RobotsDisallowed` exception |
| `blocked` | `Blocked` exception |
| `local_throttle` / `host_rate_limited` | branch on `RateLimitedByHost->source` |
| `temporary_failure` | `TemporaryFailure` exception |
| `http_error` | `HttpError` exception |
| `extraction_failed` (or Layer-1 string passthrough at line 99) | `! $extraction->isSuccess()` |
| `currency_mismatch` | snapshot currency ≠ product currency |

**Critical**: the line-99 passthrough is load-bearing. `AddShop::handleFailure()` (line 251) checks `$code === 'no_adapter_matched' || str_starts_with($code, 'user_selector_')` to decide whether to switch the form into `manual_selector` state. Collapsing Layer-1 reasons into a generic `ProbeFailure::ExtractionFailed` enum case would lose this branch — it would have to become a separate first-class signal on `ProbeOutcome`.

Consumers of `errorCode`:

- `app/Livewire/Shops/AddShop.php` — branches on values; also produces its own local codes (`user_selector_required` at line 75, `'unknown'` fallback at line 253) on the same `?string $errorCode` property.
- `resources/views/livewire/shops/add-shop.blade.php` — switches at lines 90 (`user_selector_required` / `user_selector_invalid` / `user_selector_no_match` / `user_selector_no_price`) and 188 (probe failures rendered for the user).
- Tests in `tests/Feature/Shops/ProbeShopUrlTest.php` and `tests/Feature/Shops/AddShopLivewireTest.php`.

### Layer 3 — `PriceCheck::status` (`ScrapeStatus` enum exists; producers still write strings)

`CheckShopPrice` writes statuses to `price_checks.status` along two paths:

- **Network failure** path — `failureOutcome()` (around `app/Jobs/CheckShopPrice.php:191`) builds a match expression that returns string literals except for `HttpError` which already uses `ScrapeStatus::HttpError->value`.
- **Extraction failure** path — `fetchAndExtract()` at line 151 returns `'status' => ScrapeStatus::ParseError->value` (already enum-based).
- **Success** path — line 168 uses `ScrapeStatus::Ok->value` (already enum-based).
- **Generic failure** — `genericFailure()` around line 228 uses `ScrapeStatus::HttpError->value`.

Internal consumers in `CheckShopPrice` also branch on raw status strings:

- Line 279: `$outcome['status'] === ScrapeStatus::Ok->value` (correct enum usage).
- Around line 314 (`incrementCountersFor()`): match against `'5xx'`, `'robots_disallowed'` literals.

So "nothing in production switches on these strings" is false — production *does* compare them; the migration must update those comparison sites too.

Tests assert exact strings on `->last_status`. Those continue to work because enum `->value` matches the string they assert.

---

## Phases

### Phase 1 — Tighten `CheckShopPrice` against `ScrapeStatus` end-to-end

Lowest-risk pass. Producer and consumer call sites both swap to the enum.

1. In `app/Jobs/CheckShopPrice.php::failureOutcome()`, rewrite the match to return `ScrapeStatus` cases:
   ```php
   $status = match (true) {
       $e instanceof RobotsDisallowed => ScrapeStatus::RobotsDisallowed,
       $e instanceof Blocked          => ScrapeStatus::Blocked,
       $e instanceof TemporaryFailure => ScrapeStatus::TransientServerError,
       $e instanceof HttpError        => ScrapeStatus::HttpError,
       default                        => ScrapeStatus::Failed,
   };
   return ['status' => $status->value, …];
   ```
2. `genericFailure()` already uses `ScrapeStatus::HttpError->value` — fine.
3. In the internal `incrementCountersFor()` (around line 314), replace `'5xx'` / `'robots_disallowed'` string literals with `ScrapeStatus::TransientServerError->value` / `ScrapeStatus::RobotsDisallowed->value` (or refactor the match to take `ScrapeStatus` directly).
4. Run: `vendor/bin/pest tests/Feature/Shops/CheckShopPriceJobTest.php --compact`. Must pass — tests assert `->value` strings, which don't change.

No test changes expected.

### Phase 2 — Introduce `App\Enums\ProbeFailure` and split extraction signal

The naive plan ("collapse all extraction reasons to `ExtractionFailed`") would regress the manual-selector flow. Instead, give the load-bearing signal its own first-class field on `ProbeOutcome`.

1. Create `app/Enums/ProbeFailure.php`:
   ```php
   enum ProbeFailure: string
   {
       case InvalidUrl        = 'invalid_url';
       case ProbeRateLimited  = 'probe_rate_limited';
       case RobotsDisallowed  = 'robots_disallowed';
       case Blocked           = 'blocked';
       case LocalThrottle     = 'local_throttle';
       case HostRateLimited   = 'host_rate_limited';
       case TemporaryFailure  = 'temporary_failure';
       case HttpError         = 'http_error';
       case ExtractionFailed  = 'extraction_failed';
       case CurrencyMismatch  = 'currency_mismatch';
   }
   ```
2. Change `ProbeOutcome`:
   - `errorCode` → `?ProbeFailure` (strict)
   - Add `public ?string $extractionReason = null;` — carries the Layer-1 string verbatim (`no_adapter_matched`, `user_selector_no_match`, `jsonld_no_price`, etc.) when `errorCode === ExtractionFailed`. Null in all other cases.
   - Update `ProbeOutcome::failed()` to require `ProbeFailure`. Add optional `extractionReason` param to support the extraction-failed factory:
     ```php
     public static function failed(ProbeFailure $errorCode, ?array $context = null, ?string $extractionReason = null): self
     ```
3. In `ProbeShopUrl.php`:
   - All existing `ProbeOutcome::failed('...')` calls take enum cases.
   - The line-99 passthrough becomes:
     ```php
     return ProbeOutcome::failed(
         ProbeFailure::ExtractionFailed,
         extractionReason: $extraction->failureReason,
     );
     ```
4. Update `AddShop::handleFailure()` to switch on the new shape:
   ```php
   $reason = $outcome->extractionReason ?? '';
   if ($outcome->errorCode === ProbeFailure::ExtractionFailed
       && ($reason === 'no_adapter_matched' || str_starts_with($reason, 'user_selector_'))) {
       $this->errorCode = $reason;     // keep blade view + manual-selector flow unchanged
       …
   }
   ```
   AddShop's local `?string $errorCode` property and `'user_selector_required'` / `'unknown'` codes stay as-is — they're AddShop-internal UI state, not part of the `ProbeOutcome` contract. Casting `ProbeFailure` to `->value` at the AddShop boundary preserves the blade view's string switches.
5. Audit the blade view at `resources/views/livewire/shops/add-shop.blade.php` (lines 90 + 188). The string literals it switches on (`user_selector_required`, `user_selector_invalid`, `user_selector_no_match`, `user_selector_no_price`, plus probe failures) must match the values AddShop assigns to its `$errorCode` property. Verify no drift after Phase 2.
6. Update tests:
   - `tests/Feature/Shops/ProbeShopUrlTest.php` — replace `->toBe('invalid_url')` etc. with `->toBe(ProbeFailure::InvalidUrl)`.
   - `tests/Feature/Shops/AddShopLivewireTest.php` — tests around line 134 exercise the manual-selector flow. Verify they still pass; if they assert on `ProbeOutcome` directly, update to new shape.
7. Run: `vendor/bin/pest tests/Feature/Shops --compact`. Must pass.

### Phase 3 — `ExtractionResult::failureReason`: keep as `?string`

Per Q1's resolution (default below), no work. Adapter-private diagnostic strings remain free-form. The Layer-1 → Layer-2 bridge from Phase 2 (`extractionReason` field) preserves diagnostic detail without leaking the vocabulary into an enum.

If Q1 closes the other way (strict enum at Layer 1), the work is bigger — requires per-adapter cases and `HostAdapter::extractFromHtml`'s dynamic `{adapter_key}_extraction_failed` either eliminated or refactored into a structured `(adapter_key, generic_reason)` tuple.

### Phase 4 — Verify

1. `vendor/bin/pest --compact` — full suite passes (baseline 374 tests / 372 passed / 2 skipped / 1032 assertions).
2. `vendor/bin/pint --dirty --format agent` — clean.
3. `vendor/bin/phpstan analyse --memory-limit=2G` — clean. PHPStan should now catch any stray string passed where `ProbeFailure` is expected.

---

## Open Questions

- **Q1 (blocks Phase 3):** should `ExtractionResult::failureReason` become a strict enum, or stay `?string` for adapter-private diagnostics? **Default:** stay `?string`. `HostAdapter::extractFromHtml`'s dynamic `{adapter_key}_extraction_failed` pattern doesn't fit an enum cleanly, and the reason is consumed only by the `extractionReason` bridge in `ProbeOutcome` (Phase 2) plus debug surfaces.
- **Q2 (Phase 2 fork):** instead of the `extractionReason: ?string` field, should `ProbeFailure` grow first-class cases for the manual-selector-fallback signals (`NoAdapterMatched`, `UserSelectorInvalid`, `UserSelectorNoMatch`, `UserSelectorNoPrice`, `UserSelectorNoCurrency`)? **Default:** no — that pulls Layer-1 vocabulary into Layer-2 and forces every new adapter-side reason to update the `ProbeFailure` enum. The split-field design keeps the layers clean and lets the AddShop branch logic stay scoped to the few signals it actually uses.

---

## Findings

- **Q1 default applied**: `ExtractionResult::failureReason` stays `?string`. Phase 3 was a no-op as planned. The Layer-1 vocabulary remains open-ended (still includes `HostAdapter`'s dynamic `{adapter_key}_extraction_failed`).
- **Q2 default applied**: added `?string $extractionReason` to `ProbeOutcome` rather than expanding `ProbeFailure` with manual-selector cases. `AddShop::handleFailure()` now checks `$outcome->errorCode === ProbeFailure::ExtractionFailed && $outcome->extractionReason === 'no_adapter_matched' || str_starts_with(...)` to drive the manual-selector flow.
- **AddShop's public `$errorCode` property stayed `?string`** as the spec predicted — it carries both ProbeFailure `->value`s and AddShop-local codes (`empty_url`, `duplicate`, `user_selector_required`, `'unknown'`). The blade view (lines 90, 188-225) switches on those raw strings; no blade changes needed.
- **PHPStan caught a real subtlety**: `$outcome->errorCode?->value ?? 'unknown'` flagged as `nullsafe.neverNull` because PHPStan proves errorCode is non-null inside `handleFailure()` (the match in `submitProbe()` filters to the failed-state branch only, and `ProbeOutcome::failed()` requires a non-null `ProbeFailure`). Replaced with `assert($outcome->errorCode !== null)` + `$outcome->errorCode->value`. The `'unknown'` fallback became unreachable — defensive code for an impossible state, removed.
- **Test assertions updated**: `ProbeShopUrlTest` now asserts `->toBe(ProbeFailure::Foo)` instead of `->toBe('foo')`. The `no_adapter_matched` test also asserts the new `extractionReason` field carries the Layer-1 string. `AddShopLivewireTest` needed no changes — it asserts on the AddShop public `$errorCode` property which is still `?string`.
- **`CheckShopPrice` internal status comparisons** in `incrementCountersFor()` now compare against `ScrapeStatus::...->value` instead of raw `'5xx'` / `'robots_disallowed'` literals. The match arms still operate on strings (the outcome array uses `'status' => ScrapeStatus::*->value` so persistence/test assertions don't shift). A future refactor could plumb `ScrapeStatus` cases through the outcome array directly to eliminate `->value` round-trips.
