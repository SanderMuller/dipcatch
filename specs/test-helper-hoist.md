# Test Helper Hoist to `tests/Pest.php`

## Overview

Three small fixture builders are duplicated across four test files. Hoist to `tests/Pest.php` and update call sites. Pure refactor — no behavior change, all tests must continue to pass.

This is a cleanup spec, not on the launch-readiness build order in `specs/README.md`.

---

## Current state

| Helper | Source of truth | Other definitions / inline copies |
|---|---|---|
| `withJsonLd(string $json): string` | `tests/Feature/PriceAdapters/JsonLdAdapterTest.php:6` | `tests/Feature/PriceAdapters/BolAdapterTest.php:7`, `tests/Feature/PriceAdapters/AmazonAdapterTest.php:7` (own copies); `tests/Feature/Shops/ProbeShopUrlTest.php` inlines the same `<html><head><script type="application/ld+json">…</script></head></html>` wrapper twice |
| `jsonLdPage(string $price, string $currency, string $title): string` | `tests/Feature/Shops/ProbeShopUrlTest.php:11` | single source — duplicates the wrapper independently from `withJsonLd()` |
| `fakeJsonLdResponse(string $host, string $path, string $price, string $currency): array` | `tests/Feature/Shops/CheckShopPriceJobTest.php:14` | single call site (no duplication) — **out of scope for this spec** |

`tests/Pest.php` currently only ships the boilerplate skeleton plus an `expect()` extension placeholder — no project helpers live there yet.

---

## Phases

### Phase 1 — Extract `withJsonLd()` to `tests/Pest.php`

1. Add to `tests/Pest.php`:
   ```php
   function withJsonLd(string $json): string
   {
       return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
   }
   ```
2. Delete the duplicate definitions in `JsonLdAdapterTest.php`, `BolAdapterTest.php`, `AmazonAdapterTest.php`.
3. Run `vendor/bin/pest tests/Feature/PriceAdapters --compact` — must pass.

### Phase 2 — Extract `jsonLdPage()` to `tests/Pest.php` (rewritten on top of `withJsonLd()`)

1. Add to `tests/Pest.php`:
   ```php
   function jsonLdPage(string $price = '50.00', string $currency = 'EUR', string $title = 'Test Item'): string
   {
       $json = json_encode([
           '@context' => 'https://schema.org',
           '@type' => 'Product',
           'name' => $title,
           'offers' => [
               '@type' => 'Offer',
               'price' => $price,
               'priceCurrency' => $currency,
               'availability' => 'https://schema.org/InStock',
           ],
       ], JSON_THROW_ON_ERROR);

       return withJsonLd($json);
   }
   ```
   Note: the existing copy uses `'@type' => 'Shop'` on the offer block — that is a typo (should be `Offer`). Validate against the tests that consume it before normalizing.
2. Delete the duplicate from `ProbeShopUrlTest.php:11`.
3. Replace the two inline JSON-LD wrappers in `ProbeShopUrlTest.php` (the AMBIGUOUS tests) with `withJsonLd($json)` calls so they stop carrying their own copy of the wrapper string.
4. Run `vendor/bin/pest tests/Feature/Shops/ProbeShopUrlTest.php --compact` — must pass.

### Phase 3 — Verify

1. `vendor/bin/pest --compact` — full suite passes (baseline: 374 tests, 372 passed, 2 skipped, 1032 assertions).
2. `vendor/bin/pint --dirty --format agent` — clean.
3. `vendor/bin/phpstan analyse --memory-limit=2G` — clean.

---

## Open Questions

- **Q1:** keep flat function names `withJsonLd` / `jsonLdPage` in `tests/Pest.php` (current Pest convention in this repo — see `fakeJsonLdResponse()` already living at file-scope in `CheckShopPriceJobTest.php`), or move to a namespaced helper class under `tests/Support/`? **Default:** flat — matches existing convention; class-based wrappers buy nothing for two helpers.
- **Q2:** during Phase 2, normalize the `'@type' => 'Shop'` → `'@type' => 'Offer'` typo in the canonical helper, or preserve bug-for-bug for safety? **Default:** normalize. The JSON-LD adapter accepts `Offer` (correct) and apparently also tolerates `Shop` (since current tests pass), so flipping to `Offer` removes a wrong example without breaking tests. Re-verify with Phase 3's full-suite run.
- **Q3:** hoist `fakeJsonLdResponse()` too? **Default:** no — single call site, hoisting adds noise without reducing duplication.

---

## Findings

(filled during implementation)
