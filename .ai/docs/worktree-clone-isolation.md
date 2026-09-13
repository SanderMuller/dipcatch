# Running as one of several parallel agents (Polyscope clones / git worktrees)

> Loaded on demand — not inlined into CLAUDE.md. Read this when you work in a
> **Polyscope CoW clone or a git worktree** and might run alongside other agents on
> the same machine. The code is isolated (separate checkouts); three **machine-level
> resources are not**: the one PostgreSQL server, the one Herd site table, and the one
> Redis instance. This doc isolates the first per clone, links the second, and says
> what to do about the third.
>
> Written against PostgreSQL 18.0 (Laravel Herd) and the `pest --parallel` setup in
> `composer.json`. Every command below was run in this repository.

The three shared resources that bite:

1. **The test database.** `phpunit.xml.dist` hardcodes `DB_DATABASE=dipcatch_test`.
   Two agents running the suite at once both `migrate:fresh`-wipe that same database,
   and each run corrupts the other's mid-flight.
2. **The browser host.** Herd serves `dipcatch.test` to whichever checkout owns the
   link. A fresh clone's `.env` still says `APP_URL=http://dipcatch.test`, so an
   eye-verify run silently drives the **main** checkout.
3. **Redis.** Every clone has `APP_NAME=DipCatch`, so `Str::slug(APP_NAME)` yields the
   same `dipcatch` prefix in all of them — dev cache, sessions and queues collide.
   Tests are unaffected; see section C.

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

`composer test` is `pest --parallel`. That path is **not** self-creating: paratest
connects to the base database in order to `drop database if exists
"<base>_test_1"` for each worker, and that connection fails before any migration can
run. The observed error is

```
FATAL: database "dipcatch_test_rosy_goose" does not exist
(… SQL: drop database if exists "dipcatch_test_rosy_goose_test_1")
```

Create it once per clone, then parallel works for every later run:

```bash
slug="$(basename "$(git rev-parse --show-toplevel)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')"
PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -c "CREATE DATABASE \"dipcatch_test_${slug}\""
DB_DATABASE="dipcatch_test_${slug}" composer test
```

Paratest then appends `_test_N` per worker — `dipcatch_test_rosy_goose_test_1` … — all
namespaced under the clone, so still isolated. The credentials are the local defaults
already committed in `phpunit.xml.dist`; they are not secrets.

**`--parallel` is not a substitute for the override.** It isolates workers inside one
run and does nothing across checkouts: two clones both running it collide on
`dipcatch_test_test_1`. The two compose; neither replaces the other.

**When to use this:** only in a clone or worktree. The primary checkout keeps the bare
`dipcatch_test` — do not rename it. You are in a clone if the repo root is under
`.polyscope/clones/`, or `git rev-parse --git-common-dir` is not just `.git`.

## B. Serving the clone in the browser (eye-verify only)

Needed **only** for eye-verify — seeing a change render. Backend tests never need it.

**Herd does not reliably auto-link this project's clones.** Check first, and grep the
**path**, not the name: a link name can diverge from its directory (this repository
already has a `jade-owl` clone linked as `jade-owl-flux`).

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

and pass the host to the scripts, which default to `https://dipcatch.test`:

```bash
BASE="https://rosy-goose.test" node .github/eye-verify/drive.mjs
```

Without both, a clone impersonates the main checkout. The scripts guard the crude case
— each checks the page title and exits 2 if it is not a DipCatch page (see
`.github/eye-verify/README.md`) — but that guard cannot tell **this** DipCatch tree
from the main one, so a wrong-host run passes the title check and verifies the wrong
code. Getting `BASE` and `APP_URL` right is the only defence.

**Build the frontend for this clone.** `/public/build` is git-ignored, so a clone never
inherits one, and without it every page 500s on a missing Vite manifest:

```bash
yarn install
yarn build      # use build, not dev, on a clone
```

## C. Redis and the dev database

**Redis.** All clones share one instance and one `dipcatch` prefix, because
`APP_NAME=DipCatch` everywhere. Tests do not care — `phpunit.xml.dist` sets
`CACHE_STORE=array`, `SESSION_DRIVER=array` and `QUEUE_CONNECTION=sync` — but a clone
you drive in the browser shares dev cache, sessions and queues with every other clone
and with the main checkout. If that matters for what you are verifying, give the clone
its own prefix in its `.env`:

```dotenv
REDIS_PREFIX=dipcatch_rosy_goose_
CACHE_PREFIX=dipcatch_rosy_goose_
```

Never set either to an empty value — see `shared-redis-prefix.md` for why.

**The dev database is shared too, and it holds real data.** A clone's `.env` ships with
`DB_DATABASE=dipcatch`, the same database the main checkout uses. Do not run
`migrate:fresh`, `db:wipe`, a seeder that truncates, or any other destructive command
against it from a clone. If eye-verify needs seeded data, a separate per-clone dev
database is a **user-authorised** step, not something to arrange on your own.

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
PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres \
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
PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -At \
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
