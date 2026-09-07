# Plan 003: Stop a recheck adopting a shop's new currency

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Jobs/CheckShopPrice.php app/Enums/ScrapeStatus.php app/Models/Product.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: `plans/002-rate-limited-recheck-retry.md` (same file, avoid a conflicting edit)
- **Category**: bug
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

When an offer is added, `ProbeShopUrl` **rejects** it if the page's currency
differs from the product's — there is a dedicated `ProbeFailure::CurrencyMismatch`
for it. The scheduled recheck never re-enforces that invariant: it writes
whatever currency the page reported straight onto the offer.

Nothing downstream compares currencies. `Product::recomputeCheapestShop()` orders
by `current_price` alone, and `bestValueShop()` groups by pack unit alone. So an
offer that starts reporting GBP competes numerically against EUR offers: GBP 9.00
beats EUR 10.00, becomes the cheapest, is rendered with the **product's** symbol,
and fires a price-drop notification for a price that does not exist. A
cross-domain redirect — which the fetcher deliberately follows, keying adapters
on the final host — is one realistic way this happens under a URL that never
changed.

**The decision, already made by the maintainer**: a currency mismatch is a failed
check. The offer keeps its last good price and currency, the failure counter
ticks, and the offer walks to `failing` then `dead` if it persists. This mirrors
the add path and reuses machinery that already exists.

## Current state

`app/Jobs/CheckShopPrice.php` in `persist()`, around line 470:

```php
$updates = ['last_checked_at' => $now, 'last_status' => $status];

if ($status === ScrapeStatus::Ok) {
    $updates += [
        'current_price' => $outcome['price'],
        'current_in_stock' => (bool) ($outcome['in_stock'] ?? true),
        'currency' => $outcome['currency'] ?? $locked->currency,
        'last_success_at' => $now,
        'last_error' => null,
        'consecutive_failures' => 0,
        'consecutive_5xx_failures' => 0,
```

The add-path guard this plan mirrors, `app/Actions/Shops/ProbeShopUrl.php:137-142`:

```php
if ($product instanceof Product && strcasecmp($snapshot->currency, $product->currency) !== 0) {
    return ProbeOutcome::failed(ProbeFailure::CurrencyMismatch, [
        'expected' => $product->currency,
        'actual' => $snapshot->currency,
    ]);
}
```

`app/Enums/ScrapeStatus.php` currently declares:

```php
case Ok = 'ok';
case EmptyMatch = 'empty_match';
case HttpError = 'http_error';
case ParseError = 'parse_error';
case Throttled = 'throttled';
case RobotsBlocked = 'robots_blocked';
case NeedsJs = 'needs_js';
case Pending = 'pending';
case Blocked = 'blocked';
case RateLimited = 'rate_limited';
case TransientServerError = '5xx';
case RobotsDisallowed = 'robots_disallowed';
case Dead = 'dead';
case Failed = 'failed';
```

`last_status` is a plain string column and is rendered only in the admin panel
(`app/Filament/Admin/Resources/Shops/Tables/ShopsTable.php:59`, as a badge). The
enum has **no** `label()` method, so adding a case needs no translation work and
no migration.

**Critical constraint — currency strings are not guaranteed canonical.**
`app/PriceAdapters/ShopSnapshot.php:19` documents `$currency` as "ISO 4217
uppercase, e.g. EUR", but nothing enforces it, and `app/Support/MoneyFormatter.php`
defensively does `strtoupper(trim($currency))` in three places — which tells you
the contract is not trusted. If one adapter ever emits `eur`, `€`, or an empty
string, a naive strict comparison would fail every recheck on that host and walk
healthy offers to `dead`. The comparison **must** normalise, and an empty
currency **must** be treated as "no signal", not as a mismatch.

## Commands you will need

| Purpose        | Command                                                              | Expected on success |
|----------------|----------------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Shops tests/Feature/Drops \|\| true`    | all pass            |
| Full suite     | `vendor/bin/pest \|\| true`                                            | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                           | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                     | `"result":"passed"` |

## Scope

**In scope**:
- `app/Enums/ScrapeStatus.php` (one new case)
- `app/Jobs/CheckShopPrice.php` (the guard in `persist()`)
- `app/Models/Product.php` (currency predicate on the two comparison queries)
- `tests/Feature/Shops/CheckShopPriceJobTest.php`
- A new or existing test file covering `Product::recomputeCheapestShop()` /
  `bestValueShop()` — find it with `grep -rln "bestValueShop" tests/` and add
  there rather than creating a duplicate.

**Out of scope**:
- `app/Actions/Shops/ProbeShopUrl.php` — the add path is already correct.
- Any currency **conversion**. This app does not convert; a mismatch is an
  error, never something to normalise into the product's currency.
- Changing `products.currency` or offering the user a way to change it.

## Git workflow

- Branch: `advisor/003-currency-mismatch-fails-the-check`
- Conventional commits (e.g. `fix: treat a changed shop currency as a failed check`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the status case

In `app/Enums/ScrapeStatus.php`, add:

```php
case CurrencyMismatch = 'currency_mismatch';
```

Place it next to `ParseError`. Do **not** reuse `ParseError` — parsing succeeded;
only the answer disagreed, and the admin badge should say which.

If the enum has helper methods that switch over cases (for example something
that classifies a status as a failure, or maps it to a health transition),
`grep -n "match" app/Enums/ScrapeStatus.php` and add the new case to every one.
A `match` without a default will fatal at runtime if you miss one.

**Verify**: `composer phpstan || true` → 0 errors. (PHPStan flags unhandled
match arms, so a clean run is real evidence here.)

### Step 2: Guard the write in `persist()`

Before the `$status === ScrapeStatus::Ok` branch applies its updates, compare
currencies **normalised**:

```php
$reported = strtoupper(trim((string) ($outcome['currency'] ?? '')));
$expected = strtoupper(trim($locked->currency));

// A shop that starts quoting another currency is not a cheaper shop. The
// add path already refuses this (ProbeShopUrl, ProbeFailure::CurrencyMismatch);
// without the same check here the offer competes numerically against the
// others and fires a drop alert for a price that does not exist.
// An empty currency is no signal at all, not a mismatch — some adapters
// legitimately return none.
if ($status === ScrapeStatus::Ok && $reported !== '' && $reported !== $expected) {
    $status = ScrapeStatus::CurrencyMismatch;
}
```

When the status is `CurrencyMismatch`, the offer must keep `current_price`,
`currency`, and `current_in_stock` untouched, and must tick
`consecutive_failures` exactly as the other failure statuses do. Follow whatever
the file's existing failure branch does — do not hand-roll a third path.

**Verify**: `vendor/bin/pest tests/Feature/Shops || true` → all pass.

### Step 3: Never compare prices across currencies

In `app/Models/Product.php`:

- `recomputeCheapestShop()`'s offer query (around lines 194-208): add
  `->where('currency', $this->currency)`.
- `bestValueShop()` (around lines 149-175): include currency alongside
  `pack_unit` in the grouping so a EUR/kg figure is never ranked against a GBP/kg one.

This is defence in depth: step 2 stops **new** drift, this stops an offer that
already drifted from winning.

**Verify**: `vendor/bin/pest || true` → 0 failures.

### Step 4: Check for existing drifted data

Read-only, report the result in your summary — do not modify data:

```bash
php artisan tinker --execute="\$rows = App\Models\Shop::query()->join('products','products.id','=','shops.product_id')->whereColumn('shops.currency','!=','products.currency')->count(); echo \$rows;"
```

If the count is non-zero, report it. Cleaning up existing rows is deliberately
out of scope — the maintainer decides what to do with them.

## Test plan

In `tests/Feature/Shops/CheckShopPriceJobTest.php`, modelled on the existing
JSON-LD fixtures in that file (`fakeJsonLdResponse()` takes a `$currency`
argument — use it):

1. **A changed currency does not overwrite the price.** Offer at EUR 10.00, page
   now returns GBP 9.00. Assert `current_price` is still `10.00`, `currency` is
   still `EUR`, `last_status` is `currency_mismatch`, and `consecutive_failures`
   incremented.
2. **No drop event, no notification.** Same setup, assert `price_drop_events` is
   empty and no notification was sent. This is the user-visible harm.
3. **Case and whitespace do not trigger it.** Page returns `" eur "`. Assert the
   check succeeds normally — status `ok`, price updated.
4. **An empty currency does not trigger it.** Page returns `''`. Assert the check
   succeeds and the offer keeps its existing currency.
5. **Cross-currency offers cannot win the comparison.** Build a product with a
   EUR offer at 10.00 and a (drifted) GBP offer at 9.00, run
   `recomputeCheapestShop()`, assert the EUR offer is cheapest.

**Prove tests 1 and 5 fail without the fix**, then restore it.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Five new tests exist and pass
- [ ] `grep -n "CurrencyMismatch" app/Enums/ScrapeStatus.php app/Jobs/CheckShopPrice.php` → matches in both
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] The step 4 count is reported in your summary
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- Adding the enum case breaks a `match` you cannot locate — report the fatal
  rather than adding a `default` arm that silently swallows future cases.
- **Test 3 or 4 fails**, i.e. normalisation is not enough and real adapters emit
  currencies in shapes this plan did not anticipate. Report what shapes you
  found. Shipping a strict comparison against unnormalised data would mark
  healthy offers dead, which is worse than the bug.
- More than a handful of rows come back from step 4's query — that suggests
  drift is already widespread and the rollout needs a data decision first.

## Maintenance notes

- Any new adapter must return an uppercase ISO 4217 code or an empty string.
  Worth stating in `ShopSnapshot`'s docblock, which currently only documents the
  intent.
- If multi-currency products are ever supported, step 3's predicates are the
  first thing to revisit — they assume one currency per product.
- A reviewer should scrutinise the `$reported !== ''` guard: dropping it turns
  every adapter that omits a currency into a dead offer.
