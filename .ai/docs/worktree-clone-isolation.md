# Running as one of several parallel agents (Polyscope clones / git worktrees)

> Loaded on demand — not inlined into CLAUDE.md. Read this when you work in a
> **Polyscope CoW clone or a git worktree** and might run alongside other agents on
> the same machine. The code is isolated (separate checkouts); three **machine-level
> resources are not**: the one PostgreSQL server, the one Herd site table, and the one
> shared dev database. This doc isolates the test database per clone, links the site,
> and says what not to do to the dev data.
>
> Written against PostgreSQL 18.0 (Laravel Herd) and the `pest --parallel` setup in
> `composer.json`. Every command below was run in this repository.

The three shared resources that bite:

1. **The test database.** `phpunit.xml.dist` hardcodes `DB_DATABASE=dipcatch_test`.
   Two agents running the suite at once both `migrate:fresh`-wipe that same database,
   and each run corrupts the other's mid-flight.
2. **The browser host.** Herd serves `dipcatch.test` to whichever checkout owns the
   link. A Polyscope clone gets its own copy of `.env`, which still says
   `APP_URL=http://dipcatch.test`, so an eye-verify run silently drives the **main**
   checkout. (`.env` is git-ignored, so a *worktree* starts with none at all — copy one
   in before anything boots.)
3. **The dev database.** `DB_DATABASE=dipcatch` in every clone, and it carries the
   cache, sessions and queue as well as the app's data. Tests are unaffected; see
   section C.

## The clone slug

Every isolated resource is named from the clone's own directory. Two forms:

```bash
site="$(basename "$(git rev-parse --show-toplevel)")"                          # Herd site / APP_URL host, e.g. "rosy-goose"
slug="$(echo "$site" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')"    # DB identifier, e.g. "rosy_goose"
```

- **Herd site / `APP_URL`:** `$site` → `rosy-goose.test`
- **Database name:** `$slug` → `dipcatch_test_rosy_goose`

In a git worktree `git rev-parse --show-toplevel` is the worktree's own directory, not
the main repository — which is what you want. A Polyscope clone is always a clean
lowercase `adjective-animal` name, so `$slug` is just its hyphens-to-underscores form;
the wider sanitisation matters for a worktree directory you named yourself.

The name comes from the clone, **never from the run or a timestamp**. That determinism
is what stops the machine filling with dead databases — one clone reuses one database
across every run, however many runs there are.

## A. Isolating the test database

`phpunit.xml.dist`'s `<env name="DB_DATABASE" value="dipcatch_test"/>` has **no `force`
attribute**, so an exported `DB_DATABASE` wins over it.

### Serial runs — nothing to pre-create

```bash
DB_DATABASE="dipcatch_test_$(basename "$(git rev-parse --show-toplevel)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')" vendor/bin/pest --compact || true
```

`RefreshDatabase` runs `migrate:fresh`, which calls `migrate` with `--force`, and
`MigrateCommand::createMissingMySqlOrPgsqlDatabase()` then issues
`CREATE DATABASE "<name>"` when the database is missing. So a serial run creates its
own database on first use. Append `--filter=…` or a path exactly as normal.

### Parallel runs — create the base database once

`composer test` is `pest --parallel`. That path is **not** self-creating. Laravel's own
parallel-testing support does it — `Illuminate\Testing\Concerns\TestDatabases::ensureTestDatabaseExists()`
probes the worker database, and on failure connects to the **base** database to drop and
recreate the worker one. That second connection fails before any migration can run. The
observed error is

```
FATAL: database "dipcatch_test_rosy_goose" does not exist
(… SQL: drop database if exists "dipcatch_test_rosy_goose_test_1")
```

Create it once per clone, then parallel works for every later run:

```bash
slug="$(basename "$(git rev-parse --show-toplevel)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')"
psql -h 127.0.0.1 -U postgres -c "CREATE DATABASE \"dipcatch_test_${slug}\""
DB_DATABASE="dipcatch_test_${slug}" composer test
```

Laravel then appends `_test_N` per worker — `dipcatch_test_rosy_goose_test_1` … — all
namespaced under the clone, so still isolated. Herd's PostgreSQL trusts local
connections, so `psql` needs no password; if yours is configured otherwise it will
prompt.

**`--parallel` is not a substitute for the override.** It isolates workers inside one
run and does nothing across checkouts: two clones both running it collide on
`dipcatch_test_test_1`. The two compose; neither replaces the other.

**When to use this:** only in a clone or worktree. The primary checkout keeps the bare
`dipcatch_test` — do not rename it. You are in a clone if the repo root is under
`.polyscope/clones/`, or `git rev-parse --git-common-dir` is not just `.git`.

## B. Serving the clone in the browser (eye-verify only)

Needed **only** for eye-verify — seeing a change render. Backend tests never need it.

**Herd does not reliably auto-link this project's clones.** Check first, and grep the
**path**, not the name — a site can be linked under a name that does not match its
directory, so a name search reports "not linked" for a clone that is.

```bash
herd links | grep "$(git rev-parse --show-toplevel)"   # linked? matches on path
herd link "$site"                                       # only if missing — run from the clone root
```

Do **not** `herd park` a parent directory: park turns every sibling clone into a site
at once, which is what makes them collide.

Then point both the app and the harness at this tree, in **this clone's `.env`**:

```dotenv
APP_URL=http://rosy-goose.test
```

and pass the host to the scripts, most of which default to `https://dipcatch.test` and
honour a `BASE` override:

```bash
BASE="https://rosy-goose.test" node .github/eye-verify/markup-cleanup.mjs
```

**Two scripts hardcode the host and ignore `BASE`** — `drive.mjs` and
`history-depth.mjs`. Check the `const BASE` line before trusting a run:
`grep -n 'const BASE' .github/eye-verify/*.mjs`. A hardcoded one will drive the main
checkout from inside your clone and report a confident green.

Without both `BASE` and `APP_URL`, a clone impersonates the main checkout. Several
scripts check the page title and exit 2 on a non-DipCatch page, but that guard cannot
tell **this** DipCatch tree from the main one — a wrong-host run passes it and verifies
the wrong code. Getting the host right is the only real defence.

`drive.mjs` also imports from `.claude/skills/frontend-quality/scripts/`, which is
git-ignored. A git worktree carries no ignored files, so it fails there until those
skills are synced.

**Build the frontend for this clone.** `/public/build` is git-ignored, so it is not
checked out with the code and a fresh clone has none — without it every page 500s on a
missing Vite manifest. A clone you have already built keeps its build:

```bash
yarn install
yarn build      # use build, not dev, on a clone
```

## C. The shared dev database

**It holds real data, and it holds more than you think.** `.env.example` sets
`CACHE_STORE=database`, `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database`, and
`DB_DATABASE=dipcatch` — the same database the main checkout uses. So a clone you drive
in the browser shares not only the app's data but its cache, sessions and queued jobs
with every other clone.

Tests never touch it: `phpunit.xml.dist` sets `CACHE_STORE=array`,
`SESSION_DRIVER=array` and `QUEUE_CONNECTION=sync`, and section A moves the test
database off the shared name.

Do not run `migrate:fresh`, `db:wipe`, a seeder that truncates, or any other
destructive command against `dipcatch` from a clone. If eye-verify needs seeded data, a
separate per-clone dev database is a **user-authorised** step, not something to arrange
on your own.

**If your `.env` routes any of those to Redis instead**, the prefix becomes the thing
that separates clones, and it is derived from `APP_NAME` — which is `DipCatch` in every
clone, so they all resolve to `dipcatch-database-` (`config/database.php`) and
`dipcatch-cache-` (`config/cache.php`). Override both in the clone's `.env`:

```dotenv
REDIS_PREFIX=dipcatch-rosy-goose-database-
CACHE_PREFIX=dipcatch-rosy-goose-cache-
```

Never set either to an empty value — see `shared-redis-prefix.md` for why.

## Teardown — reclaim a removed clone

Deleting a clone removes neither its databases nor its Herd site.

- **Herd:** `herd unlink <site>` — the hyphen form, `rosy-goose`.
- **Database:** dropping a disposable `dipcatch_test_<slug>` belonging to a clone that
  no longer exists is the one sanctioned `DROP`. Never the shared `dipcatch_test`, and
  never the `dipcatch` dev database. Keep it **user-run**; agents do not drop databases
  as part of normal work.

PostgreSQL refuses to drop a database while a session is connected to it, so use
`WITH (FORCE)` (PostgreSQL 13+):

```bash
psql -h 127.0.0.1 -U postgres \
  -c 'DROP DATABASE IF EXISTS "dipcatch_test_<slug>" WITH (FORCE)'
```

Its `_test_N` worker databases need dropping too; the sweep below lists them.

### Periodic sweep — user-run, review before dropping anything

Lists per-clone test databases whose **clone directory no longer exists**. It strips a
`_test_N` worker suffix first, then skips anything that reduces to the bare
`dipcatch_test` — otherwise the primary checkout's own workers
(`dipcatch_test_test_1` …) would be reported as orphans, which is the obvious way to
get this wrong.

```bash
live=$(ls "$(dirname "$(git rev-parse --show-toplevel)")" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g' | sort -u)
psql -h 127.0.0.1 -U postgres -At \
  -c "select datname from pg_database where datname like 'dipcatch\_test\_%' order by 1" |
while read -r db; do
    base=$(printf '%s' "$db" | sed -E 's/_test_[0-9]+$//')
    [ "$base" = "dipcatch_test" ] && continue
    slug=${base#dipcatch_test_}
    grep -qx "$slug" <<<"$live" || echo "orphan -> $db"
done
```

The sweep only sees clones in the **same** Polyscope group, and no worktrees elsewhere,
so confirm a flagged clone's directory is really gone before dropping it.
