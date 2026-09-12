# Plan 001: Show the price line on the public share page and the MCP tool when the price has been stable

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Models/ProductCheapestHistory.php app/Http/Controllers/PublicProductController.php app/Mcp/Tools/PriceHistoryTool.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

A product's cheapest price is stored as *segments*: one row per change, where the
current segment has `ended_at = NULL` and may have started months ago. The public
share page and the `price_history` MCP tool both select segments with
`started_at >= $cutoff`. A product whose price has not changed inside the window
therefore has **zero** matching rows, and both surfaces render an empty chart —
for exactly the products that are working best.

The in-app chart already gets this right: it matches segments that *overlap* the
window. So the same product shows a line when the owner views it and nothing
when a visitor opens the share link. Share links are the growth surface; a blank
chart on them is worse than no share link.

## Current state

Files:

- `app/Models/ProductCheapestHistory.php` — the segment model. Already uses PHP
  attribute scopes (`#[Scope]`), so a new scope belongs here.
- `app/Http/Controllers/PublicProductController.php` — renders `GET /p/{slug}`.
  Contains the broken predicate.
- `app/Mcp/Tools/PriceHistoryTool.php` — the `price_history` MCP tool. Same bug.
- `app/Filament/App/Resources/Products/Widgets/PriceHistoryChart.php` — the
  **correct** implementation. Read it, copy the predicate, do not modify it.

The correct predicate, `PriceHistoryChart.php` (in `segmentsFor()`):

```php
// Include any segment that overlaps the window — `started_at < window`
// but still active (`ended_at IS NULL` or `ended_at >= window`).
// Otherwise long-lived current prices disappear from the left edge.
$query->where(function (EloquentBuilder $q) use ($windowStart): void {
    $q->where('started_at', '>=', $windowStart)
        ->orWhereNull('ended_at')
        ->orWhere('ended_at', '>=', $windowStart);
});
```

The broken one, `PublicProductController.php:68-76`:

```php
$cutoff = CarbonImmutable::now()->subDays(90);

$segments = ProductCheapestHistory::query()
    ->select(['cheapest_price', 'started_at', 'ended_at'])
    ->where('product_id', $product->id)
    ->where('started_at', '>=', $cutoff)
    ->inOrder()
    ->get();
```

The same bug, `PriceHistoryTool.php:34-40`:

```php
$days = $this->user($request)->entitlements()->historyDays();
$cutoff = $days === null ? null : CarbonImmutable::now()->subDays($days);

$rows = $product->cheapestHistory()
    ->when($cutoff instanceof CarbonImmutable, fn (Builder $query): Builder => $query->where('started_at', '>=', $cutoff))
    ->inOrder()
    ->get();
```

Conventions to match:

- Scopes on this model use the attribute form. Existing exemplar in
  `app/Models/ProductCheapestHistory.php`:

  ```php
  #[Scope]
  protected function inOrder(Builder $query): void
  {
      $query->orderBy('started_at')->orderBy('id');
  }
  ```

- Every file starts `<?php declare(strict_types=1);`.
- Comments explain **why**, not what. The repo's style is a short paragraph
  above the non-obvious line. Do not narrate the obvious.

## Commands you will need

| Purpose        | Command                                          | Expected on success   |
|----------------|--------------------------------------------------|-----------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/PublicSharing tests/Feature/Mcp \|\| true` | all pass |
| Full suite     | `vendor/bin/pest \|\| true`                        | 0 failures            |
| Static analysis| `composer phpstan \|\| true`                       | 0 errors              |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true` | `"result":"passed"`   |

## Scope

**In scope**:
- `app/Models/ProductCheapestHistory.php` (add one scope)
- `app/Http/Controllers/PublicProductController.php`
- `app/Mcp/Tools/PriceHistoryTool.php`
- `tests/Feature/PublicSharing/` (add cases to the existing test file)
- `tests/Feature/Mcp/McpToolsTest.php` (add a case)

**Out of scope** (do NOT touch):
- `app/Filament/App/Resources/Products/Widgets/PriceHistoryChart.php` — it is
  already correct and is covered by a large test suite
  (`tests/Feature/Billing/HistoryDepthTest.php`). Refactoring it to use the new
  scope is a deliberate follow-up, not part of this plan.
- `app/Billing/HistoryWindow.php` — the plan-entitlement clamp. This plan does
  not change which window an account may read, only which rows fall inside it.

## Git workflow

- Branch: `advisor/001-history-window-overlap`
- Conventional commits, matching `git log` (e.g. `fix: show the price line when the cheapest price has not changed recently`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add an `overlapping` scope to the segment model

In `app/Models/ProductCheapestHistory.php`, add a scope beside `inOrder()`:

```php
/**
 * Segments that overlap the window, not merely those that started inside
 * it. A price that has not changed for a year is one open segment that
 * started before any window — matching on `started_at` alone would render
 * an empty chart for the products that are working best.
 *
 * @param  Builder<$this>  $query
 */
#[Scope]
protected function overlapping(Builder $query, ?DateTimeInterface $windowStart): void
{
    if ($windowStart === null) {
        return;
    }

    $query->where(function (Builder $inner) use ($windowStart): void {
        $inner->where('started_at', '>=', $windowStart)
            ->orWhereNull('ended_at')
            ->orWhere('ended_at', '>=', $windowStart);
    });
}
```

Import `DateTimeInterface` at the top of the file.

**Verify**: `composer phpstan || true` → 0 errors.

### Step 2: Use it in the public controller

In `app/Http/Controllers/PublicProductController.php`, replace
`->where('started_at', '>=', $cutoff)` with `->overlapping($cutoff)`.

Leave the `select([...])` projection exactly as it is — it is a deliberate
allowlist that keeps private columns off the public page.

**Verify**: `vendor/bin/pest tests/Feature/PublicSharing || true` → all pass.

### Step 3: Use it in the MCP tool

In `app/Mcp/Tools/PriceHistoryTool.php`, replace the `when(...)` clause with a
direct `->overlapping($cutoff)` call — the scope already no-ops on `null`, so
the conditional wrapper is no longer needed:

```php
$rows = $product->cheapestHistory()
    ->overlapping($cutoff)
    ->inOrder()
    ->get();
```

Remove the now-unused `Builder` import if nothing else in the file uses it.

**Verify**: `vendor/bin/pest tests/Feature/Mcp || true` → all pass.

### Step 4: Write the regression tests (see Test plan below)

**Verify**: `vendor/bin/pest tests/Feature/PublicSharing tests/Feature/Mcp || true` → all pass, including the new cases.

## Test plan

Model the new cases on the existing files in `tests/Feature/PublicSharing/` and
on `tests/Feature/Mcp/McpToolsTest.php`.

Write **three** cases:

1. **Public page, stable price** — a product with exactly one segment, opened
   400 days ago, `ended_at` still `NULL`. Request the share URL. Assert the
   rendered chart payload contains that segment's price. This is the exact bug:
   before the fix the payload is empty.
2. **MCP tool, stable price** — same shape of product, call the `price_history`
   tool as the owner, assert the response contains one segment rather than an
   empty list.
3. **Window still excludes genuinely old, closed segments** — a segment that
   started 400 days ago **and ended 300 days ago** must NOT appear in a 90-day
   public window. This guards against the fix over-correcting into "return
   everything".

**Prove each test fails without the fix.** Temporarily restore
`->where('started_at', '>=', $cutoff)` in one file, confirm the matching test
fails, then restore the scope. A test that passes either way is not coverage.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Three new tests exist and pass
- [ ] `grep -n "started_at', '>=', \$cutoff" app/Http/Controllers/PublicProductController.php app/Mcp/Tools/PriceHistoryTool.php` → no matches
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- `PriceHistoryChart::segmentsFor()` no longer contains the overlap predicate —
  this plan copies it, so its absence means the codebase moved and the intended
  behaviour needs re-confirming.
- Making the public-page test pass appears to require changing
  `app/Billing/HistoryWindow.php` or the chart widget. It does not; that would
  mean the diagnosis is wrong.
- The full suite fails in `tests/Feature/Billing/HistoryDepthTest.php`. Nothing
  in this plan should touch plan entitlements.

## Maintenance notes

- The chart widget still carries its own inline copy of this predicate. A
  follow-up should point it at the new scope so a fourth reader cannot drift
  again. Deferred here to keep this change's blast radius small — the chart is
  covered by the history-depth suite and refactoring it invites unrelated
  failures.
- A reviewer should check the third test specifically: it is the one that proves
  the fix did not turn the window into "select everything".
