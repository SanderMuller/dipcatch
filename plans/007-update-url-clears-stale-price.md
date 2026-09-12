# Plan 007: Clear the old price when an offer is repointed at a different URL

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Models/Shop.php`
> If the file changed since this plan was written, compare the "Current state"
> excerpt against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

`Shop::updateUrl()` already understands that repointing an offer at a new URL
invalidates what was learned from the old one: it clears the CSS selectors, the
variant key, the image, the GTIN, and the pack size — with a comment saying "A
different product means a different pack: a stale size would price the new offer
wrongly until the next successful check."

The same reasoning applies with more force to `current_price` and
`current_in_stock`, which it does **not** clear. Between the edit and the next
successful check, the offer advertises the *previous* product's price under the
*new* URL. `Product::recomputeCheapestShop()` only requires a non-null
`current_price`, an active offer, in stock, and health not dead — all of which
the just-repointed offer satisfies. So it can win cheapest, write a
`product_cheapest_history` segment, and fire a drop notification linking to a
page that never had that price.

The window is normally short, because the edit queues an immediate recheck. It is
not bounded: a queue backlog, or a first check that fails, leaves the stale price
in place indefinitely.

## Current state

`app/Models/Shop.php:85-120`:

```php
$newHash = UrlNormalizer::hash($normalized);

if ($newHash === $this->url_hash) {
    return false;
}

$this->forceFill([
    'url' => $normalized,
    'url_hash' => $newHash,
    'host' => UrlNormalizer::normalizeHost(parse_url($normalized, PHP_URL_HOST) ?: ''),
    'consecutive_failures' => 0,
    'consecutive_5xx_failures' => 0,
    'last_status' => ScrapeStatus::Pending,
    'last_error' => null,
    'health' => ShopHealth::Ok,
    'active' => true,
    // Drop URL-blind hints so the next probe re-runs the full chain.
    // ...
    'price_selector' => null,
    'title_selector' => null,
    'image_selector' => null,
    'variant_key' => null,
    'image_url' => null,
    // A different product means a different pack: a stale size would
    // price the new offer wrongly until the next successful check.
    'pack_quantity' => null,
    'pack_unit' => null,
    'gtin' => null,
])->save();

return true;
```

Note `'last_status' => ScrapeStatus::Pending` — the offer is already marked as
"we do not know anything yet". Clearing the price makes the row agree with that
status.

The query that must stop selecting it, `app/Models/Product.php:194-208`
(`recomputeCheapestShop()`), filters on `current_price` not null, `active`,
`current_in_stock`, and health not dead.

## Commands you will need

| Purpose        | Command                                                | Expected on success |
|----------------|--------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Shops \|\| true`          | all pass            |
| Full suite     | `vendor/bin/pest \|\| true`                              | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                             | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`       | `"result":"passed"` |

## Scope

**In scope**:
- `app/Models/Shop.php` (the `forceFill` in `updateUrl()`)
- `tests/Feature/Shops/` — add to the existing test covering `updateUrl`; find it
  with `grep -rln "updateUrl" tests/`

**Out of scope**:
- The Filament form and Livewire component that call `updateUrl()`. This plan
  changes the model's own reset, which every caller inherits.
- `app/Jobs/CheckShopPrice.php` — the recheck that repopulates the price is
  already correct.
- The conditional/promotion columns are **in** scope only if the "Current state"
  excerpt shows them being left behind; verify against the live file. If
  `conditional_price` / `promotion_*` exist on the table, clear them too — a
  stale promotion window is the same bug wearing a different hat.

## Git workflow

- Branch: `advisor/007-update-url-clears-stale-price`
- Conventional commits (e.g. `fix: clear the old price when a shop URL is repointed`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Clear the price fields in the reset

Add to the `forceFill` array, beside the pack-size block:

```php
// The same reasoning as the pack size, with more consequence: until the
// next successful check this offer has no known price. Leaving the old
// one made it eligible for `recomputeCheapestShop()`, so a repointed
// offer could win cheapest and fire a drop alert for a price that
// belonged to the previous product.
'current_price' => null,
'current_in_stock' => null,
```

Then check the table for `conditional_price`, `conditional_label`,
`conditional_starts_at`, `conditional_ends_at`, `promotion_starts_at`,
`promotion_ends_at`, `promotion_label` — confirm with
`php artisan tinker --execute="echo implode(',', Schema::getColumnListing('shops'));"`
— and clear every one that exists. A promotion window from the old product is
stale in exactly the same way.

**Verify**: `composer phpstan || true` → 0 errors. If `current_in_stock` is a
non-nullable column, use `false` rather than `null` and say so in your report.

### Step 2: Recompute the product's cheapest offer after the reset

Clearing the price does not by itself fix `products.cheapest_shop_id`, which may
still point at the offer just repointed. Find how the callers of `updateUrl()`
handle this (`grep -rn "updateUrl" app/`): if a recompute already follows, note
it and skip. If not, call `recomputeCheapestShop()` on the product after a
successful `updateUrl()` — in the caller, not inside the model, to keep the
model free of that dependency.

**Verify**: `vendor/bin/pest tests/Feature/Shops || true` → all pass.

## Test plan

Add to the existing `updateUrl` test file:

1. **The old price is gone.** An offer with `current_price = 10.00` repointed at
   a different URL has `current_price === null` afterwards.
2. **The repointed offer cannot win cheapest.** A product with two offers — one
   at EUR 12.00, one repointed from EUR 5.00 — recomputes to the 12.00 offer, not
   the repointed one. This is the actual harm; test 1 alone would pass with a
   cosmetic change.
3. **A no-op URL edit changes nothing.** Calling `updateUrl()` with a URL whose
   hash is unchanged returns `false` and leaves `current_price` intact. The early
   return already guarantees this; the test locks it in so a future refactor
   cannot start wiping prices on every form save.

Prove test 2 fails without the change.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Three new tests exist and pass
- [ ] `grep -n "current_price" app/Models/Shop.php` → the reset includes it
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpt in "Current state" does not match the live code.
- `current_in_stock` cannot be null and no sensible non-null value exists —
  report rather than guessing, because `false` hides the offer while `true`
  keeps it eligible.
- Test 3 fails, meaning `updateUrl()` no longer early-returns on an unchanged
  hash. That would mean every form save wipes prices, which is a bigger problem
  than the one this plan fixes.
- Step 2 reveals that no caller recomputes and adding the call requires touching
  more than one out-of-scope file.

## Maintenance notes

- The reset list in `updateUrl()` is the single place that answers "what do we
  still know about this offer after it points somewhere else?". Any new column
  describing the *offer's current state* (rather than its identity) belongs in
  it. A reviewer adding a column to `shops` should ask that question.
- Deliberately not done here: marking the offer inactive until the first
  successful check. That would change what the user sees on the product page
  immediately after an edit, which is a UX decision, not a bug fix.
