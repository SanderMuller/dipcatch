# Plan 010: Let each alert fail on its own, and say when one is dropped

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 747f324..HEAD -- app/Actions/Drops/`
> If any file in that directory changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug + observability
- **Planned at**: commit `747f324`, 2026-09-12

## Why this matters

Plan 006 moved the hourly budget question and the send into a `DB::afterCommit`
callback in all three alert detectors. That fixed the budget accounting and
introduced two costs that were accepted at the time and are now due.

**A. One failing alert kills the other two.**
`DatabaseTransactionRecord::executeCallbacks()` is a bare `foreach` with no
`try`. `ManagesTransactions::transaction()` calls
`$this->transactionsManager?->commit(...)` — which runs those callbacks —
*after* `PDO::commit()` has already succeeded, and outside both of its own
`try` blocks. So a throw in one callback escapes `DB::transaction()` with the
data committed, and every callback staged after it is skipped.

This was observed, not inferred. A probe run against this repository nested two
transactions, registered a throwing callback on the inner one and a recording
callback on the outer, and reported: the inner callback ran, the exception
escaped `DB::transaction()`, the committed row survived, **and the outer
callback never ran**.

In `CheckShopPrice::persist()` the detectors run drop, unit-price, target-price,
and `DetectDrop` registers its callback three levels deep — so it is staged
first and runs first. A failure there takes the unit-price and target-price
alerts with it, even though both of their claims committed and both of their
latches are armed.

The throw surface is ordinary. `NotificationBudget::allows()` reads and writes
the cache-backed rate limiter, and all three notifications are `ShouldQueue`,
so `notify()` pushes to the queue driver. Both are network calls.

The job retry cannot recover any of it. `$tries = 10`, but on the retry
`recomputeCheapestShop()` sees no price change and returns before `DetectDrop`,
and both target detectors see an armed latch and return. The data is correct
and the alerts are gone.

**B. Two of the three detectors drop an alert silently.**
`DetectDrop` writes a `Log::warning` when the budget suppresses a send.
`DetectUnitPriceTarget` and `DetectTargetPrice` return without a word. Since
plan 006 the three behave identically, so the asymmetry is now arbitrary — and
without the log there is no way to answer "does anyone actually reach the
hourly cap?", which is the question that decides whether plan 011 is worth
doing at all.

## Current state

`app/Actions/Drops/DetectDrop.php:171-184` — logs the suppression, no `try`:

```php
            // The event row belongs inside the transaction; the budget and the
            // send do not. Asking spends a slot of the hourly ceiling, and the
            // limiter is cache-backed — it does not roll back.
            DB::afterCommit(function () use ($locked, $outcome, $event, $user): void {
                if ($this->withinHourlyLimit($user)) {
                    $user->notify(new PriceDropNotification($locked, $outcome, $event->id));
                } else {
                    Log::warning('Notification suppressed by hourly rate limit', [
                        'user_id' => $user->id,
                        'product_id' => $locked->id,
                        'price_drop_event_id' => $event->id,
                    ]);
                }
            });
```

`app/Actions/Drops/DetectUnitPriceTarget.php:123-132` — silent on denial:

```php
        // Asked here and nowhere earlier: asking spends a slot of the hourly
        // ceiling, the limiter is cache-backed and does not roll back, and
        // `CheckShopPrice` runs this action inside a transaction.
        DB::afterCommit(function () use ($product, $shop, $unitPrice, $user): void {
            if (! app(NotificationBudget::class)->allows($user)) {
                return;
            }

            $user->notify(new UnitPriceTargetNotification($product, $shop, $unitPrice));
        });
```

`app/Actions/Drops/DetectTargetPrice.php:115-124` is the same shape with
`$price` and `TargetPriceNotification`.

The framework behaviour this plan works around:

- `vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionRecord.php:83-88`
  — `executeCallbacks()` is `foreach ($this->callbacks as $callback) { $callback(); }`.
- `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:50-72`
  — the `PDO::commit()` sits in a `try`; `$this->transactionsManager?->commit(...)`
  and `fireConnectionEvent('committed')` that follow it do not.

## Commands you will need

| Purpose        | Command                                                          | Expected on success |
|----------------|-------------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Drops tests/Feature/Notifications \|\| true` | all pass   |
| Full suite     | `vendor/bin/pest \|\| true`                                        | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                       | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                 | `"result":"passed"` |

Note: the local PHP CLI memory limit is too low for the full suite. Run it as
`php -d memory_limit=1G vendor/bin/pest --compact || true`.

## Scope

**In scope**:
- `app/Actions/Drops/DetectDrop.php`
- `app/Actions/Drops/DetectUnitPriceTarget.php`
- `app/Actions/Drops/DetectTargetPrice.php`
- `tests/Feature/Drops/`

**Out of scope**:
- `app/Services/Drops/NotificationBudget.php` — plan 011 owns that class. This
  plan must not change its contract, only how its answer is reported.
- `app/Jobs/CheckShopPrice.php` — the transaction shape is correct. This plan
  makes the callbacks tolerate each other, not the job restructure itself.
- Durable redelivery of a failed send. That is the deferred outbox option in
  `plans/README.md`; this plan records the loss, it does not repair it.
- The latch behaviour on a denial. Plan 006 settled that deliberately and a
  test pins it.

## Git workflow

- Branch: `advisor/010-alert-callbacks-fail-independently`
- Conventional commits, one per part (A and B are independent)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1 (A): Catch inside each callback

Wrap the **whole** body of each of the three `DB::afterCommit` closures in a
`try`/`catch (Throwable $e)`. The budget question must sit inside the `try` as
well as the send — a cache outage throws there too.

The `catch` logs and returns. It must not rethrow: rethrowing is what skips the
sibling callbacks.

```php
DB::afterCommit(function () use (...): void {
    try {
        // budget question and send, unchanged
    } catch (Throwable $e) {
        report($e);

        Log::warning('Alert failed to send', [
            'alert' => 'unit_price_target',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'exception' => $e->getMessage(),
        ]);
    }
});
```

`report($e)` is not optional. A `Log::warning` carrying only
`$e->getMessage()` throws the stack trace away, and a Redis outage or a queue
push failure is exactly what the exception tracker exists for. `report()` is
what reconciles this catch with the project's silent-failure guidance: the
error still reaches the tracker, it just stops steering control flow.

Swallowing a `Throwable` otherwise is what that guidance warns about, so the
reasoning goes in a comment beside the catch: the alert is **already** lost at
this point — the data is committed, the latch is armed, and no retry re-detects
it — so rethrowing buys no recovery and costs the other two alerts on the same
check.

**Verify**: `vendor/bin/pest tests/Feature/Drops || true` → all pass.

### Step 2 (B): Say when an alert is dropped

Give the two target detectors the suppression log `DetectDrop` already has, and
keep the two reasons apart.

Use **two distinct messages**, not one message with a reason field. The
question plan 011 has to answer is "how many users hit the cap", and that must
be greppable without parsing a context array:

- `'Notification suppressed by hourly rate limit'` — the budget denied. Reuse
  `DetectDrop`'s exact wording so one grep finds all three.
- `'Alert failed to send'` — the callback threw. New in step 1.

Every log line carries `user_id`, `product_id` and an `alert` key naming the
type (`price_drop`, `unit_price_target`, `target_price`), so the three can be
counted separately. `DetectDrop`'s existing line gains the `alert` key and keeps
`price_drop_event_id`.

**Verify**: `composer phpstan || true` → 0 errors, then
`vendor/bin/pest tests/Feature/Drops tests/Feature/Notifications || true` → all pass.

## Test plan

Model on `tests/Feature/Drops/NotificationBudgetSpendTest.php` and
`tests/Feature/Drops/AlertsSurviveTheJobTransactionTest.php`, which already
build the fixtures for all three alert types.

**Part A:**

1. **A failing drop alert does not take the other two with it.** The strongest
   test in this plan and the one that justifies it. Drive
   `dispatch_sync(new CheckShopPrice($shop))` on a product that fires a drop
   *and* a pack-price target — `AlertsSurviveTheJobTransactionTest` already sets
   that up. Force the drop send to throw, then assert the
   `TargetPriceNotification` still arrives. Before the fix the target alert is
   missing.

   **How to force the throw.** `NotificationBudget` is `final`, so Mockery
   cannot double it — the same wall plan 006 hit with `Reference`. Use the
   container instead: every call site is an untyped
   `app(NotificationBudget::class)->allows($user)`, so
   `app()->instance(NotificationBudget::class, $stub)` is enough, with `$stub`
   an anonymous class whose `allows()` throws on its first call and returns
   `true` afterwards. The first call is `DetectDrop`'s, because its callback is
   staged deepest and therefore runs first. Note that this stub does not survive
   plan 011, which renames `allows()` to `take()`.
2. **A failing alert does not fail the job.** Same fixture: assert
   `dispatch_sync()` returns without throwing. This is not vacuous — `handle()`
   calls `persist()` outside every one of its `try` blocks, so today the
   exception escapes the job. Re-confirm that with
   `grep -n "persist(" app/Jobs/CheckShopPrice.php` before trusting the test; if
   a `try` has since been added around it, change the assertion to
   `Exceptions::fake()` plus `assertReported()` on the caught exception, which
   still discriminates.
3. **The committed data survives a failing send.** Assert the
   `price_drop_events` row and the armed latch are both still there after the
   throw. This pins that the catch did not change the transaction semantics.

**Part B:**

4. **A suppressed unit-price alert is logged.** `Log::shouldReceive`/`Log::spy`
   with the hourly limit set to 1 and the slot pre-spent. Assert the warning
   fires with `alert => 'unit_price_target'`.
5. **A suppressed target-price alert is logged.** The same for
   `DetectTargetPrice`.

Prove tests 1 and 4 fail without the change.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `php -d memory_limit=1G vendor/bin/pest --compact || true` → 0 failures
- [ ] Five new tests exist and pass
- [ ] `grep -l "Notification suppressed by hourly rate limit" app/Actions/Drops/*.php | wc -l`
      → `3` (it returns `1` today)
- [ ] Every `DB::afterCommit` closure in `app/Actions/Drops/` has a `catch`
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- **Test 1 passes before the fix.** That would mean the cascade does not exist
  as described, so the premise of part A is wrong and it must not ship. Part B
  is independent — report and keep it.
- The framework line references no longer match, meaning Laravel changed how
  commit callbacks are executed. Re-derive the behaviour with a probe before
  writing any code; do not trust this plan's description over the installed
  source.
- Catching `Throwable` turns out to hide a failure the queue worker currently
  handles better — for example, if the project gains a failed-job alerting path
  that this would bypass. Report rather than choosing.

## Maintenance notes

- The `catch` is load-bearing and easy to remove as "swallowing errors". The
  comment beside it is the guard; keep it, and keep it specific about *why* the
  alert is unrecoverable at that point.
- Any new alert type added to `app/Actions/Drops/` inherits both obligations:
  its `afterCommit` closure needs the `catch`, and its suppression needs the
  log with an `alert` key. A reviewer adding a fourth detector should check both.
- Part B exists to produce evidence. Once the logs have run for a while, the
  count of `Notification suppressed by hourly rate limit` decides plan 011, and
  the count of `Alert failed to send` decides whether the deferred outbox option
  in `plans/README.md` is worth planning.
