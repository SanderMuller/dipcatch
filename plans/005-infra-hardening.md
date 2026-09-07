# Plan 005: Index the drop-event lookup, document the env, and refuse the SSRF escape hatch in production

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/Services/ShopFetcher/UrlSafetyGuard.php .env.example config/dipcatch.php config/plans.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf + dx + security
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

Three small, independent hardening items that share nothing but their size:

1. **A missing index.** `price_drop_events.product_id` is declared with
   `foreignUuid(...)->constrained()`. Postgres — the production database — does
   **not** create an index for the referencing side of a foreign key. The nightly
   prune queries this column once per product, the chart's notification markers
   query it per product view, and deleting a product cascades through it. Every
   one of those is a sequential scan today.
2. **Undocumented configuration.** 25 of the app's 48 own environment variables
   are absent from `.env.example`, including `RESEND_API_KEY` and
   `VAPID_PEM_FILE`. A fresh deploy comes up looking healthy with mail and web
   push silently dead.
3. **A single env var disables SSRF protection.** `UrlSafetyGuard::assertSafe()`
   returns before any check when `allow_private_ips` is set. The docblock says
   "Never enable in production" — nothing enforces it, and the variable is not in
   `.env.example` either, so the only record of it is the source.

## Current state

**1. The migration**, `database/migrations/2026_04_26_162648_create_price_drop_events_table.php`:

```php
$table->uuid('id')->primary();
$table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
...
$table->index(['user_id', 'fired_at']);
$table->index('fired_at');
```

`user_id` gets a composite index, `product_id` gets none. Production is Postgres
(`.env.example:24` → `DB_CONNECTION=pgsql`; `README.md:29` → "Postgres on Laravel
Cloud"), so no index is created implicitly.

The queries that need it, `app/Console/Commands/PruneOldChecksCommand.php`:

```php
$keepIds = PriceDropEvent::query()
    ->where('product_id', $productId)
    ->latest('fired_at')
    ->limit(self::RETAIN_MIN_DROP_EVENTS_PER_PRODUCT)
    ->pluck('id')
    ->all();
```

**2. The env gap.** Reproduce the list with:

```bash
grep -rho "env('[A-Z_0-9]*'" config/ | sed "s/env('//;s/'//" | sort -u > /tmp/cfg.txt
grep -o "^[A-Z_0-9]*" .env.example | sort -u > /tmp/ex.txt
comm -23 /tmp/cfg.txt /tmp/ex.txt | grep -E "^(DIPCATCH|PLAN|STRIPE|BILLING|SITE|ADMIN|VAPID|RESEND|FAILED)"
```

At the planned-at commit that returns 25 names, including `DIPCATCH_FETCHER_*`
(10 of them), `PLAN_FREE_*` (3), `RESEND_API_KEY`, `VAPID_PEM_FILE`,
`SITE_PRODUCTION_URL`, `STRIPE_WEBHOOK_TOLERANCE`, `FAILED_JOB_CHANNELS`.

**3. The guard**, `app/Services/ShopFetcher/UrlSafetyGuard.php:29-33`:

```php
public function assertSafe(string $url): void
{
    if (self::allowPrivateIps()) {
        return;
    }
```

with, further down:

```php
private static function allowPrivateIps(): bool
{
    return (bool) config('dipcatch.fetcher.allow_private_ips', false);
}
```

The toggle exists for a real reason: local development uses Herd `.test` hosts
that resolve to `127.0.0.1`, and the test suite uses synthetic hostnames. Both
must keep working.

## Commands you will need

| Purpose         | Command                                                    | Expected on success |
|-----------------|------------------------------------------------------------|---------------------|
| Migrate (local) | `php artisan migrate`                                       | exit 0              |
| Tests           | `vendor/bin/pest \|\| true`                                  | 0 failures          |
| Static analysis | `composer phpstan \|\| true`                                 | 0 errors            |
| Code style      | `vendor/bin/pint --dirty --format agent \|\| true`           | `"result":"passed"` |

**Do not** run any command that drops, wipes, resets, refreshes or re-imports a
database. `php artisan migrate` forward-only is the only database command this
plan needs.

## Scope

**In scope**:
- A new migration under `database/migrations/` (add the index)
- `.env.example`
- `app/Services/ShopFetcher/UrlSafetyGuard.php`
- `tests/` — one new test for the production refusal (find the existing guard
  test with `grep -rln "UrlSafetyGuard" tests/` and add there)

**Out of scope**:
- Editing the original `create_price_drop_events_table` migration. It has
  already run in production; add a new migration instead.
- `config/dipcatch.php` — the config keys are fine; only their documentation and
  the production guard change.
- Any change to what the guard considers a private range.

## Git workflow

- Branch: `advisor/005-infra-hardening`
- Conventional commits, one per step
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Index `price_drop_events.product_id`

Create a new migration (`php artisan make:migration add_product_id_index_to_price_drop_events_table`).

Project convention, from `CLAUDE.md`: migrations must be **self-contained** —
plain string literals, no application constants — and columns are appended, never
positioned mid-table. This migration only adds an index, so both rules are easy
to honour.

```php
public function up(): void
{
    Schema::table('price_drop_events', function (Blueprint $table): void {
        // Postgres does not index the referencing side of a foreign key, and
        // the nightly prune plus the chart's markers both filter on this
        // column per product.
        $table->index('product_id');
    });
}

public function down(): void
{
    Schema::table('price_drop_events', function (Blueprint $table): void {
        $table->dropIndex(['product_id']);
    });
}
```

**Verify**: `php artisan migrate` → exit 0, then `vendor/bin/pest || true` → 0
failures (the suite builds its schema from the migrations, so a broken migration
fails loudly here).

### Step 2: Document the missing environment variables

Add every name from the "Current state" list to `.env.example`, grouped with the
existing sections and each with a real default or an empty placeholder.

Rules:
- **Never write a real secret.** `RESEND_API_KEY=` and `VAPID_PEM_FILE=` get
  empty values, not example keys.
- Mirror the defaults already in `config/dipcatch.php` and `config/plans.php` so
  the file documents actual behaviour rather than aspiration.
- For the two fetcher safety toggles, add a one-line comment stating they are
  development-only.

**Verify**: re-run the `comm` command from "Current state" → returns nothing.

### Step 3: Refuse the SSRF escape hatch in production

In `app/Services/ShopFetcher/UrlSafetyGuard.php`, make the bypass conditional on
the environment:

```php
private static function allowPrivateIps(): bool
{
    // The bypass exists for Herd's .test hosts on 127.0.0.1 and for the
    // suite's synthetic hostnames. In production it would turn every user-
    // supplied shop URL into a request the server will make to its own
    // network, so the flag is ignored there however the environment is set.
    if (app()->isProduction()) {
        return false;
    }

    return (bool) config('dipcatch.fetcher.allow_private_ips', false);
}
```

Apply the same treatment to `allowUnresolved()` — a DNS miss failing open is the
same class of hole.

**Verify**: `vendor/bin/pest || true` → 0 failures. The local suite does not run
as `production`, so existing tests must be unaffected; if they break, the change
is wrong.

## Test plan

One new test beside the existing guard tests:

- **The bypass is ignored in production.** Set the app environment to
  `production` (`app()->detectEnvironment(fn () => 'production')` or the project's
  existing pattern — check how other tests do it) and set
  `config(['dipcatch.fetcher.allow_private_ips' => true])`. Assert
  `assertSafe('http://127.0.0.1/')` still throws.
- **The bypass still works locally.** Same config, default test environment,
  assert no exception. This is what keeps local development working.

Prove the first test fails without the change.

## Done criteria

ALL must hold:

- [ ] `php artisan migrate` exits 0 and a new migration file exists
- [ ] The `comm` command from step 2 returns no app-specific names
- [ ] `grep -n "isProduction" app/Services/ShopFetcher/UrlSafetyGuard.php` → 2 matches
- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures, including 2 new tests
- [ ] No secret value appears anywhere in the diff
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpts in "Current state" do not match the live code.
- The index migration fails because an index on `product_id` already exists —
  that means someone added it since this plan was written; drop the step and say so.
- Existing fetcher tests fail after step 3. That would mean the suite relies on
  running as `production`, which this plan assumes it does not.
- You cannot determine a real default for one of the undocumented variables.
  Leave it out and report it rather than inventing a value that looks
  authoritative.

## Maintenance notes

- Step 1's index is the only one this audit found missing. If a new column starts
  being filtered on per row, check whether Postgres has an index for it — the
  `foreignId()->constrained()` idiom reads like it creates one and does not.
- `.env.example` drifts silently. A cheap follow-up is a test that fails when a
  `config/` file reads an app-specific `env()` name absent from `.env.example`.
  Deliberately not included here; it needs a rule for framework-owned names.
