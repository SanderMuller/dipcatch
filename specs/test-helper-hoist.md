# Test Helper Hoist to `tests/Pest.php`

## Overview

Three JSON-LD test-fixture helpers and a handful of inline HTML wrappers are scattered across five test files, with the same `<html><head><script type="application/ld+json">…</script></head></html>` body repeated under three different helper names (`withJsonLd`, `withBolJsonLd`, `withAmazonJsonLd`) plus two inline copies. Consolidate the shared wrapper into one helper in `tests/Pest.php`, then build the higher-level fixtures on top of it.

Scope is wider than originally written — see the corrected "Current state" table below.

This is a cleanup spec, not on the launch-readiness build order in `specs/README.md`.

---

## Current state

| Helper / pattern | File:line | Notes |
|---|---|---|
| `withJsonLd(string $jsonLd): string` | `tests/Feature/PriceAdapters/JsonLdAdapterTest.php:6` | Will become the canonical helper. |
| `withBolJsonLd(string $jsonLd): string` | `tests/Feature/PriceAdapters/BolAdapterTest.php:5` | Identical body to `withJsonLd`; just renamed to be file-local. |
| `withAmazonJsonLd(string $jsonLd): string` | `tests/Feature/PriceAdapters/AmazonAdapterTest.php:5` | Same as above. |
| `jsonLdPage(string $price, string $currency, string $title): string` | `tests/Feature/Shops/ProbeShopUrlTest.php:11` | Higher-level fixture — emits a full Product page. Re-implements the wrapper inline (does not call `withJsonLd`). |
| Inline ProductGroup wrappers | `tests/Feature/Shops/ProbeShopUrlTest.php` (the two AMBIGUOUS tests) | Direct `"<html><head><script type=\"application/ld+json\">…</script></head></html>"` interpolation. |
| `fakeJsonLdOffer(string $url, string $price, string $currency): array` | `tests/Feature/Shops/AddShopLivewireTest.php:13` | Builds an `Http::fake` map for robots.txt + a JSON-LD product page; re-wraps inline. |
| Inline ProductGroup wrapper | `tests/Feature/Shops/AddShopLivewireTest.php:242-246` | Same pattern as ProbeShopUrlTest. |
| `fakeJsonLdResponse(string $host, string $path, string $price, string $currency): array` | `tests/Feature/Shops/CheckShopPriceJobTest.php:14` | Single call site, but uses the same wrapper. |

`tests/Pest.php` currently ships the Pest boilerplate, an `expect()` extension placeholder, and an empty `something()` function — flat function helpers are an established but unused pattern.

**Net duplication:** the `<html>…<script type="application/ld+json">…</script>…</html>` body string appears in at least 7 places.

---

## Phases

### Phase 1 — Hoist `withJsonLd()` to `tests/Pest.php`, retire the namespaced copies

1. Add to `tests/Pest.php`:
   ```php
   function withJsonLd(string $jsonLd): string
   {
       return "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";
   }
   ```
2. **Move** (not delete) the definition from `JsonLdAdapterTest.php:6` to `tests/Pest.php`. The function then disappears from that file.
3. In `BolAdapterTest.php` and `AmazonAdapterTest.php`, delete the namespaced helpers (`withBolJsonLd` / `withAmazonJsonLd`) and replace all call sites with `withJsonLd(...)`. Identical body → safe substitution.
4. Run: `vendor/bin/pest tests/Feature/PriceAdapters --compact`. Must pass.

### Phase 2 — Rewrite `jsonLdPage()` on top of `withJsonLd()` and hoist

Pure mechanical refactor: same fixture output, deduplicated wrapper.

1. Move `jsonLdPage()` from `ProbeShopUrlTest.php:11` to `tests/Pest.php`, rewriting its return as `withJsonLd($json)`.
2. **Preserve the current `'@type' => 'Shop'` value** on the offer block. Fixing it to `'Offer'` is a fixture *behavior* change that does not belong in this hoist — see Q2.
3. Replace the two inline JSON-LD wrappers in `ProbeShopUrlTest.php`'s AMBIGUOUS tests with `withJsonLd($json)`.
4. Run: `vendor/bin/pest tests/Feature/Shops/ProbeShopUrlTest.php --compact`. Must pass.

### Phase 3 — `AddShopLivewireTest.php`

Two changes:

1. Replace the inline JSON-LD wrapper inside `fakeJsonLdOffer()` (line 16-ish) with `withJsonLd($json)`. Keep the function file-local — it builds a fakery map, not a pure HTML fixture, so it's a different concern.
2. Replace the inline ProductGroup wrapper at line 242-246 with `withJsonLd($json)`.
3. Run: `vendor/bin/pest tests/Feature/Shops/AddShopLivewireTest.php --compact`. Must pass.

### Phase 4 — Verify

1. `vendor/bin/pest --compact` — full suite passes (baseline: 374 tests, 372 passed, 2 skipped, 1032 assertions).
2. `vendor/bin/pint --dirty --format agent` — clean.
3. `vendor/bin/phpstan analyse --memory-limit=2G` — clean.

---

## Open Questions

- **Q1:** are these helpers broad enough to justify global `tests/Pest.php` scope, or should they live in `tests/Support/` under a `Tests\Support\Fixtures` namespace? **Default:** flat global functions in `tests/Pest.php`. Pest convention favors flat helpers; `withJsonLd` and `jsonLdPage` are short, descriptive, and useful from any test that needs a JSON-LD page. A `Tests\Support\Fixtures` class would be defensible if we expect many more fixture builders — the `fakeJsonLd*` family suggests that may happen.
- **Q2:** in Phase 2, the existing `jsonLdPage()` emits `offers['@type'] = 'Shop'` which is wrong per schema.org (should be `'Offer'`). Fix it as part of this spec, or leave for a separate follow-up? **Default:** leave alone. This spec is scoped as a pure-refactor hoist; fixing the fixture changes the JSON-LD shape the JsonLdAdapter parses (it currently tolerates the typo), and conflating the two would muddy the diff. Cut a follow-up issue.
- **Q3:** hoist `fakeJsonLdResponse()` (`CheckShopPriceJobTest.php`) and/or `fakeJsonLdOffer()` (`AddShopLivewireTest.php`)? **Default:** no — single call site each, and they each build a different shape of `Http::fake` map. Hoisting would force a common signature that buys nothing.

---

## Findings

- **Q1 default applied**: flat function helpers in `tests/Pest.php`. Reasonable for two helpers; revisit if the `fakeJsonLd*` family grows.
- **Q2 default applied**: preserved the `offers['@type'] = 'Shop'` typo. Added a PHPDoc note on `jsonLdPage()` pointing at this spec for context. Follow-up to fix should also touch the JsonLdAdapter's tolerance of the typo so the fixture and the production behavior stay in sync.
- **Q3 default applied**: `fakeJsonLdResponse()` (CheckShopPriceJobTest) and `fakeJsonLdOffer()` (AddShopLivewireTest) stayed file-local. Phase 3 *did* touch `fakeJsonLdOffer()` internally — replaced its inline wrapper with `withJsonLd($json)` — but the function itself remains scoped to AddShopLivewireTest.
- **PHPStan config gap surfaced (not in spec)**: hoisting `withJsonLd` to `tests/Pest.php` made PHPStan throw 51 `function.notFound` errors because `tests/Pest.php` wasn't in the `paths` or `scanFiles` list — only `tests/Feature` + `tests/Unit` were analysed. Fixed by adding `tests/Pest.php` to `scanFiles` in `phpstan.neon` (scan for symbols, don't analyse — the Pest bootstrap has dynamic-`$this` idioms that PHPStan flags noisily otherwise).
- **Wrapper duplication eliminated**: the `<html><head><script type="application/ld+json">…</script></head></html>` body now appears exactly once, in `tests/Pest.php::withJsonLd()`.
