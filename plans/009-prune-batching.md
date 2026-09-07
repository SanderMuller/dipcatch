# Plan 009: Make the nightly prune's cost independent of the product count

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Console/Commands/PruneOldChecksCommand.php`
> If the file changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: `plans/005-infra-hardening.md` (its index makes these queries cheap)
- **Category**: perf
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

`dipcatch:prune-checks` walks every product and every offer, issuing per-row
queries: two per product (drop events, cheapest-history segments) plus one per
offer (price checks). At the current size that is trivial. It grows linearly:
10,000 products and 40,000 offers is ~60,000 queries in one nightly run.

This is a "before it hurts" change, not a fix — nothing is wrong today. It is
P3 for that reason, and it is worth doing only in a way that keeps the retention
rules exactly as they are, because those rules encode a paid promise: history
accumulated while an account was Pro is never deleted.

## Current state

`app/Console/Commands/PruneOldChecksCommand.php:30-58`:

```php
public function handle(): int
{
    $this->stampKeptHistory();

    $cutoff = now()->subDays(self::RETAIN_DAYS);
    $checksDeleted = 0;
    $eventsDeleted = 0;
    $segmentsDeleted = 0;

    Product::query()
        ->select(['id', 'history_kept_from'])
        ->lazyById(500)
        ->each(function (Product $product) use ($cutoff, &$eventsDeleted, &$segmentsDeleted): void {
            $keptFrom = $product->history_kept_from;
            $eventsDeleted += $this->pruneDropEvents($product->id, $cutoff, $keptFrom);
            $segmentsDeleted += $this->pruneCheapestHistory($product->id, $cutoff, $keptFrom);
        });

    Shop::query()
        ->select('id')
        ->lazyById(500)
        ->each(function (Shop $shop) use ($cutoff, &$checksDeleted): void {
            $checksDeleted += $this->pruneChecks($shop->id, $cutoff);
        });
    ...
```

The per-product work, which is what makes batching hard:

```php
private function pruneDropEvents(string $productId, DateTimeInterface $cutoff, ?DateTimeInterface $keptFrom): int
{
    $keepIds = PriceDropEvent::query()
        ->where('product_id', $productId)
        ->latest('fired_at')
        ->limit(self::RETAIN_MIN_DROP_EVENTS_PER_PRODUCT)
        ->pluck('id')
        ->all();

    $query = PriceDropEvent::query()
        ->where('product_id', $productId)
        ->where('fired_at', '<', $cutoff)
        ->whereNotIn('id', $keepIds);

    $this->exceptKeptHistory($query, $keptFrom, 'fired_at');

    $deleted = $query->delete();

    return is_int($deleted) ? $deleted : 0;
}
```

and the exemption that must survive untouched:

```php
private function exceptKeptHistory(Builder $query, ?DateTimeInterface $keptFrom, string $column): void
{
    if ($keptFrom !== null) {
        $query->where($column, '<', $keptFrom);
    }
}
```

**The rules that must not change** (they are specified and tested in
`tests/Feature/Billing/HistoryRetentionTest.php`):

- Rows at or after a product's `history_kept_from` are never deleted, whatever
  the owner's plan is tonight.
- At least the 50 most recent drop events per product survive, on any plan.
- The open cheapest segment (`ended_at IS NULL`) is never deleted.
- A `price_check` referenced by a surviving drop event is never deleted.
- `price_checks` keep the 365-day prune for every plan.

## Commands you will need

| Purpose        | Command                                                          | Expected on success |
|----------------|------------------------------------------------------------------|---------------------|
| Retention tests| `vendor/bin/pest tests/Feature/Billing/HistoryRetentionTest.php \|\| true` | all pass   |
| Prune tests    | `vendor/bin/pest --filter=rune \|\| true`                          | all pass            |
| Full suite     | `vendor/bin/pest \|\| true`                                        | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                       | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                 | `"result":"passed"` |

## Scope

**In scope**:
- `app/Console/Commands/PruneOldChecksCommand.php`
- `tests/Feature/Billing/HistoryRetentionTest.php` (add a query-count assertion)
- Whatever existing prune test file the `--filter=rune` run reveals

**Out of scope**:
- `app/Billing/ProUsers.php` and the `stampKeptHistory()` statement — already one
  bulk UPDATE, already correct.
- The retention constants (`RETAIN_DAYS`, `RETAIN_MIN_PER_OFFER`,
  `RETAIN_MIN_DROP_EVENTS_PER_PRODUCT`). Changing what is kept is not this plan.
- `price_checks` retention policy.

## Git workflow

- Branch: `advisor/009-prune-batching`
- Conventional commits (e.g. `perf: batch the nightly prune's per-product deletes`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add a query-count characterization test first

Before changing anything, add a test that seeds N products (say 5) with prunable
history and counts the queries the command issues, using the `QueryExecuted`
listener pattern already present in `tests/Feature/Billing/HistoryRetentionTest.php`
(it has a test asserting the stamp costs a constant number of queries — copy that
structure).

Assert the **current** count. This is the number the fix must lower, and it is
also the proof that nothing else changed.

**Verify**: `vendor/bin/pest tests/Feature/Billing/HistoryRetentionTest.php || true`
→ all pass, including the new counting test against unmodified code.

### Step 2: Batch the cheapest-history prune

Segments are the easier of the two: the rule is "older than the cutoff, closed,
and before `history_kept_from` when set". That is expressible for all products at
once with a join against `products`:

```php
ProductCheapestHistory::query()
    ->whereNotNull('ended_at')
    ->where('ended_at', '<', $cutoff)
    ->whereExists(function (QueryBuilder $q): void {
        $q->selectRaw('1')
            ->from('products')
            ->whereColumn('products.id', 'product_cheapest_history.product_id')
            ->where(function (QueryBuilder $inner): void {
                $inner->whereNull('products.history_kept_from')
                    ->orWhereColumn('product_cheapest_history.ended_at', '<', 'products.history_kept_from');
            });
    })
    ->delete();
```

Keep the loop for now — replace only the segment call inside it, or move this out
of the loop entirely if the tests stay green. Whichever you choose, the open
segment (`ended_at IS NULL`) must remain excluded, which the `whereNotNull` above
preserves.

**Verify**: `vendor/bin/pest tests/Feature/Billing/HistoryRetentionTest.php || true`
→ all pass, and the step-1 count drops.

### Step 3: Decide honestly about the drop-event prune

`pruneDropEvents()` has a per-product "keep the 50 most recent" rule. Expressing
that set-wise needs a window function (`ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY fired_at DESC)`),
which Postgres supports and SQLite — the test database — supports from 3.25.

**If a window function passes the full suite on the test database**, batch it the
same way as step 2.

**If it does not**, stop batching here and say so in your report. Keep the
per-product loop for drop events, and record that the segment prune alone
removed one of the two per-product queries. Half the win with none of the risk is
the correct outcome; a hand-rolled emulation of the window function is not.

**Verify**: `vendor/bin/pest || true` → 0 failures.

## Test plan

- The query-count test from step 1, updated to assert the new, lower count.
- **Every existing retention test must pass unchanged.** They encode the paid
  promise; if one needs editing, the change is wrong. That is a STOP condition,
  not a test to update.
- Add one case if step 2 changed the segment prune: a product whose
  `history_kept_from` is set keeps a closed segment older than the cutoff, while
  a product without the stamp loses it — in the **same** run, so the batched
  statement is proven to discriminate between products rather than applying one
  rule to all.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] `tests/Feature/Billing/HistoryRetentionTest.php` passes with **no edits to
      existing assertions**
- [ ] The query-count test asserts a lower number than step 1 recorded
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated, noting whether step 3 batched or was
      deliberately left per-product

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- **Any existing retention test fails or appears to need editing.** Those tests
  encode a promise to paying customers: history accumulated under Pro is never
  deleted. A batched statement that breaks one is wrong, however much faster it is.
- The window function in step 3 is not supported by the test database, or
  produces a different result set than the loop. Take the documented fallback.
- Batching turns out to need a raw SQL string built from anything other than
  constants.

## Maintenance notes

- This command holds the retention promise. Any future change to it should start
  by reading `tests/Feature/Billing/HistoryRetentionTest.php` — the tests are the
  specification.
- The offer loop (`pruneChecks`) is untouched here because its "keep 50 per offer
  plus anything referenced by a surviving drop event" rule is the most
  entangled of the three. If the product loop batches cleanly, that is the next
  candidate.
- A reviewer should check that the open segment and the 50-event floor still hold
  in the batched form — those are the two rules a set-wise rewrite most easily
  loses.
