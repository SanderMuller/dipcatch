# Plan 011: Split the notification budget into asking and paying

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 747f324..HEAD -- app/Services/Drops/NotificationBudget.php app/Actions/Drops/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: MED
- **Depends on**: 010 — do not start until its logs show users actually reach
  the hourly cap. If nobody does, this plan is a REJECT, not a TODO.
- **Category**: bug
- **Planned at**: commit `747f324`, 2026-09-12

## Why this matters

`NotificationBudget::allows()` conflates two operations: it asks whether a slot
is free and it spends one, in a single call. Every caller therefore has to solve
the ordering problem locally, which is why plan 006 left two load-bearing "asked
here and nowhere earlier" comments in the codebase and a third in `DetectDrop`.

Plan 006 put the question after the commit that makes the send certain. That is
the correct place for *paying*. It is the wrong place for *asking*, because by
then the latch is already armed:

- `DetectUnitPriceTarget` commits `unit_price_notified = '5.38'`, then asks, then
  is denied. On the next check `alreadyNotified()` compares `5.38` against the
  stored `5.38`, returns true, and the action returns. No alert.
- `DetectTargetPrice` and `DetectDrop` have the same shape with
  `target_price_notified` and `last_notified_price`.

So at the cap an alert is not delayed, it is dropped. It is not permanent —
`clearLatch()` and `clearLatchIfRecovered()` disarm the latch when the price
rises back above the target or the reference, so a later episode fires normally
— but the episode that hit the cap is lost.

**Who this reaches.** Only a user at their ceiling: 30 alerts an hour on the
free plan (`config/dipcatch.php:60`, `DIPCATCH_NOTIFICATIONS_HOURLY_LIMIT`), 200
on Pro (`config/plans.php:65`). That needs many products dropping in one hour.
This is why the plan depends on 010: the size of the problem is currently
unmeasured, and a P3 with no observed occurrences should be rejected rather than
built.

**What the split does and does not buy.** A peek before the claim lets the
detector find the denial while the latch is still unarmed, so the alert defers to
the next check instead of being lost. It does **not** close the window
completely: peek passes, the claim commits, another worker takes the last slot,
and the take fails with the latch armed — the same lost alert in a narrower
window. It also does not remove the `DB::afterCommit` placement; paying still
has to follow the commit. The honest description is "guaranteed loss at the cap
becomes a race", not "fixed".

## Current state

`app/Services/Drops/NotificationBudget.php` in full:

```php
final class NotificationBudget
{
    public function allows(User $user): bool
    {
        $limit = $user->entitlements()->notificationsHourlyLimit();

        if ($limit <= 0) {
            return true;
        }

        $key = self::key($user);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        return true;
    }

    public static function key(User $user): string
    {
        return "notify:user:{$user->id}";
    }
}
```

Note `$limit <= 0` returns true **without** hitting the limiter. Any test that
does not set a positive ceiling passes whatever the code does.

Three callers, all in `app/Actions/Drops/`:

- `DetectDrop.php:213` — inside `withinHourlyLimit()`, called from the
  `afterCommit` closure.
- `DetectUnitPriceTarget.php:127` — inside the `afterCommit` closure.
- `DetectTargetPrice.php:119` — inside the `afterCommit` closure.

The claim each detector must not arm before a denial:

- `DetectUnitPriceTarget.php:106-119` — the conditional `update()` on
  `unit_price_notified`, then `if ($claimed === 0) { return; }`.
- `DetectTargetPrice.php:98-111` — the same on `target_price_notified`.
- `DetectDrop.php` — `triggerNotificationAtomically()` writes
  `last_notified_price` inside the locked transaction, before the event row.

## Commands you will need

| Purpose        | Command                                                          | Expected on success |
|----------------|-------------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Drops tests/Feature/Notifications \|\| true` | all pass   |
| Full suite     | `php -d memory_limit=1G vendor/bin/pest --compact \|\| true`        | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                       | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                 | `"result":"passed"` |

## Scope

**In scope**:
- `app/Services/Drops/NotificationBudget.php`
- `app/Actions/Drops/DetectDrop.php`
- `app/Actions/Drops/DetectUnitPriceTarget.php`
- `app/Actions/Drops/DetectTargetPrice.php`
- `tests/Feature/Drops/`

**Out of scope**:
- The latch semantics themselves. A denial after a successful peek still arms
  the latch and still drops that alert; this plan narrows the window, it does
  not change what happens inside it.
- `app/Jobs/CheckShopPrice.php` and the transaction shape.
- The `catch` blocks and logging from plan 010. Keep them; the peek and the take
  both live inside the same closure they guard.

## Git workflow

- Branch: `advisor/011-notification-budget-peek-and-take`
- Conventional commits
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 0: Re-confirm the caller count

`grep -rn "\->allows(" app/ tests/`

At `747f324` this returns exactly three lines, all in `app/Actions/Drops/`:

```
app/Actions/Drops/DetectDrop.php:213:        return app(NotificationBudget::class)->allows($user);
app/Actions/Drops/DetectUnitPriceTarget.php:127:            if (! app(NotificationBudget::class)->allows($user)) {
app/Actions/Drops/DetectTargetPrice.php:119:            if (! app(NotificationBudget::class)->allows($user)) {
```

No test calls it directly. If the grep returns a fourth line, the plan's premise
about where the ordering problem lives is wrong. Treat it as a STOP condition.

### Step 1: Add `available()` beside `allows()`

Add a peek that answers the question without paying:

```php
public function available(User $user): bool
{
    $limit = $user->entitlements()->notificationsHourlyLimit();

    if ($limit <= 0) {
        return true;
    }

    return ! RateLimiter::tooManyAttempts(self::key($user), $limit);
}
```

Rename `allows()` to `take()` so the name states that calling it costs a slot.
Do not keep `allows()` as an alias: the whole point is that no future caller can
ask the ambiguous question by accident. `final` class, three callers, so the
rename is mechanical.

**Verify**: `composer phpstan || true` → 0 errors.
`grep -rn "->allows(" app/ tests/` → no matches.

### Step 2: Peek before the claim in the two target detectors

In `DetectUnitPriceTarget::notify()` and `DetectTargetPrice::notify()`, add the
peek above the conditional `update()`, keeping the `take()` where plan 006 put
it inside the `afterCommit` closure:

```php
if ($user === null) {
    return;
}

// Peek before the claim: a denial found here leaves the latch unarmed, so
// the next check retries. Peeking does not spend a slot — `take()` does,
// and that has to wait for the commit.
if (! app(NotificationBudget::class)->available($user)) {
    return;
}

// ... the atomic claim, unchanged ...
```

**Verify**: `vendor/bin/pest tests/Feature/Drops || true` → all pass.

### Step 3: Peek before the latch write in `DetectDrop`

`DetectDrop` is the awkward one: its claim is the `last_notified_price` write
inside `triggerNotificationAtomically()`'s locked transaction, and the event row
is written in the same place. The event row must still be written on a denial —
`tests/Feature/Notifications/HourlyRateLimitTest.php` asserts exactly that
("suppresses excess notifications but still writes the event row"), and that
behaviour is deliberate: the drop happened whether or not the user was told.

So the peek here must **not** skip the transaction. Read the existing test
before changing anything, then decide between:

- **(a)** No peek in `DetectDrop` at all — rename `allows()` to `take()` and
  change nothing else. The plan's benefit is then limited to the two target
  detectors. Defensible; say so in your report.
- **(b)** Peek, and on a denial write the event row but skip the
  `last_notified_price` write so the next drop re-fires.

Option (b) changes drop-latch semantics and is a product decision. **Do not
choose it without asking the operator.** If in doubt, ship (a) and report (b)
as an option.

**Verify**: `vendor/bin/pest tests/Feature/Notifications || true` → all pass.

## Test plan

Add to `tests/Feature/Drops/NotificationBudgetSpendTest.php`, which already
carries the fixtures and the positive-ceiling `beforeEach`.

1. **A peek does not spend a slot.** Set a limit of 5, call `available()`
   directly, assert `RateLimiter::attempts()` is still 0. The control that stops
   every other test here passing vacuously.
2. **A denial at the cap leaves the latch unarmed.** Limit 1, slot pre-spent,
   run `DetectUnitPriceTarget`. Assert nothing sent **and**
   `unit_price_notified` is null. This is the behaviour the plan buys, and it
   inverts the assertion in the existing test
   `'a denied unit-price budget keeps the claim and drops that alert'` — that
   test was written to pin plan 006's behaviour, so **update it rather than
   deleting it**, and say in your report that you did.
3. **The same for `DetectTargetPrice`.** Assert `target_price_notified` is null.
4. **The race window still exists.** A pin on the accepted residual, not a
   regression test — it passes before and after the change. Without it the done
   criteria cannot tell "we narrowed the window" from "we did not know there was
   one". Say so in the test's own comment.

   **The window only exists inside a transaction.** In a direct call the peek,
   the claim and the take all run synchronously, leaving no gap to inject into.
   Wrap the detector in a transaction so the take is deferred to the commit, and
   spend the last slot from inside the same closure:

   ```php
   config()->set('plans.pro.notifications_hourly_limit', 1);

   DB::transaction(function () use ($product, $user): void {
       // Peek passes and the claim lands.
       app(DetectUnitPriceTarget::class)($product);

       // The sibling worker takes the last slot before this one pays.
       RateLimiter::hit(NotificationBudget::key($user), 3600);
   });

   Notification::assertNothingSent();
   expect($product->refresh()->unit_price_notified)->toBe('5.38');
   ```
5. **The budget still caps.** The existing ceiling tests in
   `UnitPriceTargetTest.php` and `TargetPriceTest.php` must keep passing
   unchanged. A peek that leaks would show up here.

Prove tests 2 and 3 fail without the change.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `php -d memory_limit=1G vendor/bin/pest --compact || true` → 0 failures
- [ ] Four new tests exist and pass, and the plan-006 latch test is updated
      rather than deleted
- [ ] `grep -rn "\->allows(" app/ tests/` → no matches (three today, see step 0)
- [ ] Every `available()` call sits above a claim; every `take()` call sits
      inside a `DB::afterCommit` closure
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated, and the report states which
      `DetectDrop` option (a or b) was taken

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- **Step 0 finds a fourth caller of `allows()`.** The plan counted three; a
  fourth means the ordering problem reaches code this plan has not read.
- **Plan 010's logs show nobody reaches the hourly cap.** Then this plan has no
  observed problem to solve and should be marked REJECTED in `plans/README.md`
  with that reasoning, not built.
- **Test 5 fails**, meaning the peek changed whether the ceiling binds. The cap
  exists to stop a buggy product alerting someone all night; weakening it is a
  worse outcome than the bug this plan fixes.
- `DetectDrop` step 3 cannot be done as option (a) without changing the event-row
  behaviour that `HourlyRateLimitTest` pins. Report; do not choose option (b)
  alone.

## Maintenance notes

- The two method names carry the whole design: `available()` never costs
  anything, `take()` always does. If a third method appears that does both
  again, this plan has been undone.
- The race in test 4 is closable, but not cheaply: it needs the claim and the
  slot to be taken atomically, which means moving the counter into the database
  beside the latch, or a reservation the send confirms. That is a much larger
  change and belongs in its own plan if the logs ever justify it.
- `available()` inherits the `$limit <= 0` short circuit, so it returns true
  without touching the limiter on an unlimited plan. Any test written against
  either method must set a positive ceiling or it proves nothing.
