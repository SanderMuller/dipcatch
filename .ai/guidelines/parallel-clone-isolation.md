## Parallel Agents — Per-Clone Test Database

`phpunit.xml.dist` pins `DB_DATABASE=dipcatch_test`, and every clone of this repository on one machine shares it. Two agents running the suite at once both `migrate:fresh`-wipe that database, and each corrupts the other mid-run. The signature is errors that walk the schema as it is rebuilt — `relation "users" does not exist`, then a missing column, then a different missing column — with a different set of tests failing every run. It is not a defect in the code under test.

When you work in a **Polyscope clone or git worktree**, name the test database after the clone. The `<env>` entry carries no `force` attribute, so an exported `DB_DATABASE` wins over it:

```bash
DB_DATABASE="dipcatch_test_$(basename "$(git rev-parse --show-toplevel)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')" vendor/bin/pest --compact || true
```

- A **serial** run creates its own database: `migrate:fresh` passes `--force` to `migrate`, which issues `CREATE DATABASE` when it is missing.
- A **parallel** run (`composer test`) does not. Laravel's parallel-testing support connects to the base database to recreate its per-worker ones, so create the base once per clone first: `psql -h 127.0.0.1 -U postgres -c 'CREATE DATABASE "dipcatch_test_<slug>"'`.
- `--parallel` is **not** a substitute — it isolates workers inside one run, not across checkouts.
- The name is derived from the clone, never the run, so it is reused rather than multiplied. In the primary checkout keep the bare `dipcatch_test`.

Two other resources are shared and are **not** covered by this: the Herd site (a clone's `.env` still says `APP_URL=http://dipcatch.test`, so eye-verify drives the main checkout) and the `dipcatch` dev database, which carries the cache, sessions and queue as well as the app's data. Browser setup, what not to run against dev data, and teardown including an orphan sweep: **`.ai/docs/worktree-clone-isolation.md`**.
