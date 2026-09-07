# Plan 008: Stop dispatching a digest job every minute for users with nothing to digest

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Console/Commands/DispatchDailyDigestsCommand.php app/Jobs/SendDailyDigest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

`dipcatch:dispatch-daily-digests` runs **every minute** (see the schedule in
`bootstrap/app.php`). It selects every email-enabled user whose
`last_digest_sent_at` is older than the start of their local day, and dispatches
`SendDailyDigest` for each.

`SendDailyDigest` deliberately does **not** stamp `last_digest_sent_at` when
there is nothing to send — the comment explains why: stamping would move the
cursor past events that arrive later in the day. And `ShouldBeUnique` releases
its lock when the job **completes**, so the dedup does not persist either.

The result: a user with no price drops today is re-selected and re-dispatched
every minute from their local 09:00 until midnight — roughly 900 no-op jobs per
user per day, each doing a query and returning. Nothing is *incorrect*; no
duplicate email is ever sent. It is pure waste, and it competes for the same
queue workers as the scrape jobs that make the product work.

Invisible at one user. At a thousand quiet users it is ~900,000 jobs a day.

## Current state

`app/Console/Commands/DispatchDailyDigestsCommand.php:60-79`:

```php
$startOfTodayLocalUtc = $localNow->startOfDay()->setTimezone('UTC');

$remaining = $batchSize - $dispatched;
if ($remaining <= 0) {
    break;
}

$digestDate = $localNow->format('Y-m-d');

User::query()
    ->where('notify_via_email', true)
    ->where('timezone', $timezone)
    ->where(function (EloquentQueryBuilder $q) use ($startOfTodayLocalUtc): void {
        $q->whereNull('last_digest_sent_at')
            ->orWhere('last_digest_sent_at', '<', $startOfTodayLocalUtc);
    })
    ->limit($remaining)
    ->each(function (User $user) use ($digestDate, &$dispatched): void {
        dispatch(new SendDailyDigest($user, $digestDate));
        $dispatched++;
    });
```

`app/Jobs/SendDailyDigest.php:55-79` — the job's own selection, which the command
must learn to ask about **before** dispatching:

```php
$lookbackDays = DipConfig::int('dipcatch.digest.lookback_days', 7);
$minSince = CarbonImmutable::now()->subDays($lookbackDays);
$lastSent = $this->user->last_digest_sent_at;
$since = $lastSent instanceof CarbonImmutable
    ? $lastSent->max($minSince)
    : CarbonImmutable::now()->subDay()->max($minSince);

$events = PriceDropEvent::query()
    ->where('user_id', $this->user->id)
    ->where('fired_at', '>', $since)
    ->with(['product', 'triggeredByShop'])
    ->oldest('fired_at')
    ->get();

if ($events->isEmpty()) {
    // Don't send empty digests; don't bump last_digest_sent_at so
    // the next non-empty window will still pick up these events.
    return;
}
```

`price_drop_events` already carries `index(['user_id', 'fired_at'])`
(`database/migrations/2026_04_26_162648_create_price_drop_events_table.php:26`),
which is exactly the index an existence check on this pair needs.

## Commands you will need

| Purpose        | Command                                                          | Expected on success |
|----------------|------------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Digests \|\| vendor/bin/pest --filter=igest \|\| true` | all pass |
| Full suite     | `vendor/bin/pest \|\| true`                                        | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                       | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                 | `"result":"passed"` |

Find the real digest test path first: `grep -rln "SendDailyDigest\|dispatch-daily-digests" tests/`.

## Scope

**In scope**:
- `app/Console/Commands/DispatchDailyDigestsCommand.php`
- The existing digest test file(s) found by the grep above

**Out of scope**:
- `app/Jobs/SendDailyDigest.php` — its "don't stamp on empty" behaviour is
  deliberate and correct. **Do not** make it stamp `last_digest_sent_at` on an
  empty run to solve this; that skips events that arrive later the same day,
  which is a correctness bug traded for a performance one.
- The every-minute schedule in `bootstrap/app.php` — the minute granularity is
  deliberate (it bounds the skew from the user's local send hour to 60 seconds).
- The digest email content and the lookback window.

## Git workflow

- Branch: `advisor/008-digest-dispatch-only-when-due`
- Conventional commits (e.g. `perf: only dispatch a digest when the user has something to digest`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Only select users who actually have undelivered events

Add an existence check to the user query that mirrors the job's own `$since`
logic. The window is per user (`last_digest_sent_at` differs per row), so it must
be expressed in SQL rather than computed once:

```php
$minSince = $nowUtc->subDays($lookbackDays);

User::query()
    ->where('notify_via_email', true)
    ->where('timezone', $timezone)
    ->where(function (EloquentQueryBuilder $q) use ($startOfTodayLocalUtc): void {
        $q->whereNull('last_digest_sent_at')
            ->orWhere('last_digest_sent_at', '<', $startOfTodayLocalUtc);
    })
    // The job returns immediately when there is nothing in the window, and
    // deliberately does not move the cursor — so without this the same quiet
    // user is re-dispatched every minute until midnight.
    ->whereExists(function (QueryBuilder $q) use ($minSince): void {
        $q->selectRaw('1')
            ->from('price_drop_events')
            ->whereColumn('price_drop_events.user_id', 'users.id')
            ->where('price_drop_events.fired_at', '>', $minSince)
            ->where(function (QueryBuilder $inner): void {
                $inner->whereNull('users.last_digest_sent_at')
                    ->orWhereColumn('price_drop_events.fired_at', '>', 'users.last_digest_sent_at');
            });
    })
    ->limit($remaining)
    ->each(...);
```

Read `$lookbackDays` from the same config key the job uses
(`dipcatch.digest.lookback_days`, default 7) so the two windows cannot drift.

Import the query-builder type the project already uses for raw sub-queries —
check the file's existing imports rather than adding a new alias.

**Verify**: `composer phpstan || true` → 0 errors.

### Step 2: Confirm the two windows agree

The command's `whereExists` and the job's `$since` must select the same events.
The job takes `max($lastSent, $minSince)`; the SQL above expresses the same with
two predicates. Re-read both and confirm by hand, then rely on the tests.

**Verify**: `vendor/bin/pest || true` → 0 failures.

## Test plan

Model on the existing digest test file.

1. **A quiet user is not dispatched.** Email-enabled, past their send hour, no
   drop events at all. Run the command with `Queue::fake()` and assert nothing
   was dispatched. Before the fix, one job is dispatched.
2. **A user with a fresh drop is dispatched.** The control case.
3. **A user whose only drop predates their last digest is not dispatched.**
   `last_digest_sent_at` set to now, one event fired an hour ago. This proves the
   `whereColumn` half of the predicate, not just the existence half.
4. **A user whose only drop is older than the lookback is not dispatched.**
   Event fired 30 days ago, lookback 7. Proves the `$minSince` half.
5. **Running the command twice in the same minute dispatches once.** Not new
   behaviour — `ShouldBeUnique` handles it — but it locks in that the fix did not
   break dedup.

Prove test 1 fails without the `whereExists`.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Five new tests exist and pass
- [ ] `grep -n "whereExists" app/Console/Commands/DispatchDailyDigestsCommand.php` → 1 match
- [ ] `grep -n "last_digest_sent_at" app/Jobs/SendDailyDigest.php` → the empty-events
      branch still does **not** stamp it
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- Test 3 or 4 cannot be made to pass without changing `SendDailyDigest`. That
  would mean the command and the job disagree about the window, and the right fix
  is to extract the window into one shared place — a bigger change than this
  plan, and worth reporting rather than improvising.
- The `whereExists` turns out to need a raw expression the project's static
  analysis rejects. Report the error rather than reaching for `DB::raw` on a
  user-influenced value.

## Maintenance notes

- The window logic now lives in two places: the job's `$since` and the command's
  `whereExists`. They must move together. A follow-up worth doing is a single
  `PriceDropEvent` scope both call — deliberately deferred here to keep the
  change reviewable.
- If a second digest cadence is ever added (weekly, say), this predicate is where
  it plugs in.
- A reviewer should confirm the job still refuses to stamp the cursor on an empty
  run. That is the invariant this plan works around rather than removes.
