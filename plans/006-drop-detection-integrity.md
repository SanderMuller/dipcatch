# Plan 006: Stop spending the alert budget on sends that never happen, and get the reference read out of the lock

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Actions/Drops/DetectDrop.php app/Actions/Drops/DetectUnitPriceTarget.php app/Models/Product.php app/Services/Drops/Reference.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED
- **Depends on**: none
- **Category**: bug + perf
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

Two defects in the same two files.

**A. The hourly alert budget is spent before the send is claimed.**
`DetectUnitPriceTarget` checks the budget — which *increments* a rate limiter —
then performs an atomic claim, then returns without notifying if the claim was
lost. The slot is gone either way. `DetectDrop` has a variant of the same
problem: it takes the budget inside a transaction that can roll back, while the
limiter is cache-backed and does not roll back with it. A user's ceiling is
eroded by sends that never happened, and at the free-plan limit that silently
suppresses real price-drop alerts.

**B. The reference price is computed twice, the second time inside the lock.**
`Product::recomputeCheapestShop()` computes it before the transaction, with a
docblock explaining that this keeps the 30-day window read out of the critical
section. It then passes that value only to the recovery branch; the drop branch
calls `DetectDrop`, which computes it again — inside `lockForUpdate`. The stated
invariant does not hold on the branch that matters, and the critical section
carries a segment query plus a `price_checks` count under concurrent jobs.

The two computations return the **same value** today: the new segment is created
with `started_at = now()` and `Reference` skips zero-duration segments, while the
just-closed segment's `ended_at = now()` equals the `$now` the earlier read
already used. So this is a performance fix with no behaviour change — the reason
it is MED risk rather than LOW is that it moves which snapshot the threshold is
evaluated against under concurrency.

## Current state

`app/Actions/Drops/DetectUnitPriceTarget.php:98-122` — budget before claim:

```php
$user = $product->user;

// The same hourly ceiling the drop alerts obey. Without this a Pro
// account with unlimited products could pass its own cap through
// this door.
if ($user === null || ! app(NotificationBudget::class)->allows($user)) {
    return;
}

// Claim the send first: two workers finishing checks together would
// otherwise both see an unlatched product and both notify.
$claimed = Product::query()
    ->whereKey($product->getKey())
    ->where(function (EloquentBuilder $query) use ($product): void {
        $query->whereNull('unit_price_notified')
            ->orWhere('unit_price_notified', $product->unit_price_notified);
    })
    ->update([...]);

if ($claimed === 0) {
    return;
}
```

The comment says "Claim the send first" — the code does not.

`app/Services/Drops/NotificationBudget.php` — `allows()` calls `RateLimiter::hit`,
so asking the question spends the slot.

`app/Actions/Drops/DetectDrop.php:163-172` — budget inside the transaction:

```php
if ($this->withinHourlyLimit($user)) {
    $user->notify(new PriceDropNotification($locked, $outcome, $event->id));
} else {
    Log::warning('Notification suppressed by hourly rate limit', [...]);
}
```

`app/Actions/Drops/DetectDrop.php:34-42` — the second compute:

```php
public function __invoke(Product $product, ?int $triggeringPriceCheckId): void
{
    if ($product->cheapest_price === null) {
        return;
    }

    $newPrice = (string) $product->cheapest_price;

    $ref = $this->reference->compute($product);
```

`app/Models/Product.php:179` and `:241-248` — the precompute and the two branches:

```php
$reference = app(Reference::class)->compute($this);
...
$direction = self::compareDirection($previousPrice, $newPrice);
$detector = app(DetectDrop::class);

match ($direction) {
    'down' => $detector($locked, $triggeringPriceCheckId),
    'up', 'null' => $detector->clearLatchIfRecovered($locked, $newPrice, $reference),
    default => null,
};
```

The pattern to copy for deferring a side effect past the transaction is already
in `DetectUnitPriceTarget`:

```php
DB::afterCommit(function () use ($product, $shop, $unitPrice, $user): void {
    $user->notify(new UnitPriceTargetNotification($product, $shop, $unitPrice));
});
```

## Commands you will need

| Purpose        | Command                                                    | Expected on success |
|----------------|------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Drops tests/Unit/Drops \|\| true` | all pass       |
| Full suite     | `vendor/bin/pest \|\| true`                                  | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                 | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`           | `"result":"passed"` |

## Scope

**In scope**:
- `app/Actions/Drops/DetectUnitPriceTarget.php`
- `app/Actions/Drops/DetectDrop.php`
- `app/Models/Product.php` (pass the reference into the drop branch)
- `tests/Feature/Drops/` and `tests/Unit/Drops/`

**Out of scope**:
- `app/Services/Drops/NotificationBudget.php` — the limiter itself is correct;
  the bug is *when* it is asked.
- `app/Services/Drops/Reference.php` — the computation is correct and is being
  called one time fewer, not changed.
- The drop threshold rules in `DropEvaluator`.

## Git workflow

- Branch: `advisor/006-drop-detection-integrity`
- Conventional commits, one per part (A and B are independent)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1 (A): Claim first, then spend the budget

In `DetectUnitPriceTarget`, move the `allows()` call **below** the
`if ($claimed === 0) { return; }` guard, keeping the null-user check where it is
(it is a precondition, not a budget question):

```php
$user = $product->user;

if ($user === null) {
    return;
}

// ... the atomic claim, unchanged ...

if ($claimed === 0) {
    return;
}

// Budget after the claim: asking spends a slot, and a lost race must not
// cost the user an alert they never received.
if (! app(NotificationBudget::class)->allows($user)) {
    return;
}
```

**Verify**: `vendor/bin/pest tests/Feature/Drops || true` → all pass.

### Step 2 (A): Take the drop budget after the transaction commits

In `DetectDrop`, move the budget check and the notify into a `DB::afterCommit`
callback, matching the pattern already used in `DetectUnitPriceTarget`. The
`Log::warning` for a suppressed notification moves with it.

The event row itself must still be created inside the transaction — only the
budget question and the send move out.

**Verify**: `vendor/bin/pest tests/Feature/Drops || true` → all pass.

### Step 3 (B): Pass the precomputed reference into the drop branch

- Add a third parameter to `DetectDrop::__invoke()`:
  `public function __invoke(Product $product, ?int $triggeringPriceCheckId, ?ReferenceValue $reference = null): void`
  (use the actual type `Reference::compute()` returns — read it; do not guess).
- Inside, use the passed value when present and fall back to computing it when
  not, so the paths that call it without a reference (tests, manual triggers)
  keep working:
  `$ref = $reference ?? $this->reference->compute($product);`
- In `Product::recomputeCheapestShop()`, pass it:
  `'down' => $detector($locked, $triggeringPriceCheckId, $reference),`

**Verify**: `composer phpstan || true` → 0 errors, then
`vendor/bin/pest tests/Feature/Drops tests/Unit/Drops || true` → all pass.

### Step 4: Confirm the critical section shrank

Add a query-count assertion (see Test plan) proving `Reference::compute` is not
called inside the transaction.

## Test plan

Model on `tests/Feature/Drops/DropEvaluatorTest.php` and
`tests/Feature/Drops/UnitPriceTargetTest.php`.

**Part A:**

1. **A lost claim does not spend a slot.** Pre-set `unit_price_notified` so the
   conditional update matches zero rows, run the action, and assert the rate
   limiter's remaining count is unchanged. Use the limiter key the
   `NotificationBudget` builds — read the class for the exact key format.
2. **A successful send does spend a slot.** The control case for test 1.
3. **A rolled-back transaction does not spend a slot.** Wrap the drop path so the
   surrounding transaction rolls back, then assert the limiter is untouched. If
   forcing a rollback at that point is impractical, assert instead that the
   notify and budget call happen after commit (Laravel's
   `Event::fake()` / `Notification::fake()` plus a transaction assertion), and
   say in your report which form you used.

**Part B:**

4. **The reference is computed once per recompute.** Bind a spy/counting
   decorator for `Reference` in the container, run `recomputeCheapestShop()` on a
   product whose price dropped, and assert `compute()` was called exactly once.
   Before the fix it is called twice.
5. **Drop detection still fires identically.** An existing drop test must keep
   passing unchanged — this part is a refactor, and any behaviour change in the
   threshold outcome means step 3 was done wrong.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Five new tests exist and pass
- [ ] `grep -n "reference->compute" app/Actions/Drops/DetectDrop.php` → the only
      match is the null-coalescing fallback
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- **Test 5 fails**, i.e. moving the reference changes which drops fire. The two
  computations are expected to be identical; if they are not, the assumption
  behind part B is wrong and it must not ship. Part A is independent — report and
  keep it.
- Moving the notify into `afterCommit` causes a notification test to fail because
  the test does not run inside a transaction. Read how
  `UnitPriceTargetNotification`'s tests handle it before changing any assertion.
- `Reference::compute()` returns something other than a nullable value object,
  making the optional-parameter signature awkward. Report the real signature.

## Maintenance notes

- Part A's ordering is easy to reintroduce: any future "check the budget early to
  save work" refactor puts the bug straight back. The comment added in step 1 is
  the guard against that — keep it.
- Part B leaves `DetectDrop` able to compute its own reference for callers that
  do not pass one. If every caller ends up passing one, make the parameter
  required and delete the fallback.
- A reviewer should confirm the `price_drop_events` row is still written inside
  the transaction after step 2 — only the send moved.
