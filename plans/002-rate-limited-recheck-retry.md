# Plan 002: Make a rate-limited recheck retry instead of dead-lettering

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Jobs/CheckShopPrice.php tests/Feature/Shops/CheckShopPriceJobTest.php`
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

`CheckShopPrice` catches `RateLimitedByHost` and calls `$this->release(...)`,
with a comment stating the intent: wait for the per-host token bucket to refill
and try again. But the job declares `public int $tries = 1`. A released job is
re-queued with its attempt count incremented, so on the next pop the worker sees
attempt 2 against a maximum of 1 and **fails the job before `handle()` runs**,
with `MaxAttemptsExceededException`.

The designed self-healing therefore never happens. Every time a host's bucket is
drained — the default is 12 fetches per minute per host, which a product tracked
at several offers on one supermarket reaches routinely — that offer is not
rechecked at all that cycle, and a `MaxAttemptsExceededException` lands in
`failed_jobs`. Real failures get buried in that noise.

## Current state

`app/Jobs/CheckShopPrice.php:55-61`:

```php
class CheckShopPrice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;
```

`app/Jobs/CheckShopPrice.php:106-117`:

```php
try {
    $outcome = $this->fetchAndExtract($shop, $fetcher, $resolver);
} catch (RateLimitedByHost $e) {
    // Per-host budget exhausted (probe path or another worker drained
    // it). Release for retry instead of writing a `rate_limited` check
    // and ticking the failure counter — the bucket refills shortly.
    // Jitter avoids a thundering herd when many queued jobs for the
    // same drained host all wake at the bucket's exact refill instant.
    $this->release(max(1, $e->retryAfterSeconds) + random_int(0, 5));

    return;
}
```

Why `$tries = 1` exists: every other failure path in this job writes a
`price_check` row with a failure status and ticks `consecutive_failures`, which
drives the offer's health toward `failing` then `dead`. Those paths must stay
single-attempt — retrying them would double-count the failure. **Only the
rate-limit path should retry.**

The existing test acknowledges the gap,
`tests/Feature/Shops/CheckShopPriceJobTest.php` (near the rate-limit case):

> "Without a queued job context, `InteractsWithQueue::release()` is a no-op"

so the retry has never been exercised.

## Commands you will need

| Purpose        | Command                                                          | Expected on success |
|----------------|------------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Feature/Shops \|\| true`                    | all pass            |
| Full suite     | `vendor/bin/pest \|\| true`                                        | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                       | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`                 | `"result":"passed"` |

## Scope

**In scope**:
- `app/Jobs/CheckShopPrice.php`
- `tests/Feature/Shops/CheckShopPriceJobTest.php`

**Out of scope**:
- `app/Services/ShopFetcher/ShopFetcher.php` — the per-host throttle itself is
  correct; this plan changes only how the job reacts to it.
- Any change to the failure statuses or the health-transition counters.
- `config/queue.php` and the scheduler in `bootstrap/app.php`.

## Git workflow

- Branch: `advisor/002-rate-limited-recheck-retry`
- Conventional commits (e.g. `fix: let a rate-limited recheck retry instead of failing`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Replace the attempt cap with a deadline

Laravel checks `retryUntil()` **instead of** `$tries` when the method exists.
Verify it yourself in
`vendor/laravel/framework/src/Illuminate/Queue/Worker.php:677-690`:

```php
$retryUntil = $job->retryUntil();

if ($retryUntil && Carbon::now()->getTimestamp() <= $retryUntil) {
    return;                       // <- no failure while the deadline holds
}

if (! $retryUntil && ($maxTries === 0 || $job->attempts() <= $maxTries)) {
    return;                       // <- the $tries path, only when no deadline
}
```

So a deadline lets the rate-limit path release repeatedly, while every other path
still runs once and then falls through to its own failure handling.

In `app/Jobs/CheckShopPrice.php`:

- Delete `public int $tries = 1;`
- Add:

```php
/**
 * A deadline rather than an attempt cap. The rate-limit branch releases the
 * job back to the queue to wait for the host's bucket to refill, and an
 * attempt cap of one turned that release into an immediate
 * MaxAttemptsExceededException. Every other branch writes its own failure
 * status and returns, so it still runs exactly once.
 */
public function retryUntil(): DateTimeInterface
{
    return now()->addMinutes(15);
}
```

Import `DateTimeInterface`.

**Verify**: `composer phpstan || true` → 0 errors.

### Step 2: Bound the release loop

A host that stays saturated for the whole 15 minutes would otherwise have the
job bounce continuously. In the `RateLimitedByHost` branch, stop releasing once
the deadline is close, and fall through to the normal throttled outcome instead:

```php
} catch (RateLimitedByHost $e) {
    $delay = max(1, $e->retryAfterSeconds) + random_int(0, 5);

    // Give up releasing when the retry deadline would pass anyway, so the
    // check is recorded as throttled rather than vanishing.
    if (now()->addSeconds($delay)->lessThan($this->retryUntil())) {
        $this->release($delay);

        return;
    }

    $outcome = $this->genericFailure('rate limited by host');
}
```

Check the exact signature and status of `genericFailure()` in the same file
before wiring it — it must produce a `ScrapeStatus::RateLimited` outcome. If the
existing helper does not offer that status, use whichever helper the file
already uses for a throttled result rather than inventing one.

**Verify**: `vendor/bin/pest tests/Feature/Shops || true` → all pass.

### Step 3: Test the retry with a real queue

**Verify**: see Test plan.

## Test plan

Add to `tests/Feature/Shops/CheckShopPriceJobTest.php`, modelled on the existing
cases in that file (they fake `Http` and resolve the job's dependencies from the
container).

1. **A rate-limited check releases rather than fails.** Use
   `Queue::fake()` / `Bus::fake()` is not enough here — the existing test's own
   comment explains that `release()` is a no-op without a queue context. Drive
   the job through a real queue connection instead: dispatch it, run the worker
   once (`$this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])`),
   and assert the job is back on the queue and `failed_jobs` is empty.
2. **The offer's failure counters do not move** on a rate-limited cycle —
   `consecutive_failures` stays 0 and no `price_check` row is written. This is
   what separates "wait and retry" from "the shop is broken".
3. **A genuine failure still runs once.** An HTTP 500 must write its failure
   `price_check`, tick the counter, and NOT be retried by the new deadline.
   This is the regression the deadline could plausibly introduce.

**Prove case 1 fails without the fix**: restore `public int $tries = 1;`,
confirm the test fails with `MaxAttemptsExceededException` (or a failed job),
then restore `retryUntil()`.

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] Three new tests exist and pass
- [ ] `grep -n 'public int \$tries' app/Jobs/CheckShopPrice.php` → no matches
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- Case 3 (a genuine failure now retries) cannot be made to pass. That would mean
  a deadline is the wrong mechanism here and the decision needs revisiting —
  do not "fix" it by re-adding an attempt cap, which reintroduces the bug.
- The job turns out to already declare `retryUntil()` or `$backoff` somewhere
  this plan did not quote.
- Running a real queue worker inside the test suite is not viable in this
  environment (no queue driver configured for tests). Report what you found
  rather than falling back to a test that cannot exercise `release()`.

## Maintenance notes

- The 15-minute deadline is a judgement call: long enough to outlast a drained
  bucket (which refills in a minute), short enough that a job cannot outlive the
  recheck cadence. If the per-host rate limit
  (`DIPCATCH_FETCHER_RATE_LIMIT_PER_MINUTE`) is lowered substantially, revisit it.
- A reviewer should confirm that no failure branch other than the rate-limit one
  can now run more than once.
