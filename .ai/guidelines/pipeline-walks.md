# Verification pipelines

This project declares three pipelines in `.config/pipeline.php`. Run
`php artisan pipeline:list` for the question each one answers, the steps it
walks, and the values `--only` accepts. It reads the config, so it cannot drift
from what runs — which a table here would.

Drive them with the `pipeline` MCP server: `open_run(pipeline: "<name>")`, then
`next_step` until the walk finishes. Name the pipeline on every call. Do not
commit, amend or check out while a walk is open — the fingerprint covers `HEAD`.

After a failed step, fix the cause and call `open_run` again, not `next_step`.

`php artisan pipeline:verify --pipeline=closeout` is the pre-PR gate. A green
`change` receipt does not answer a closeout question.

Scope with `only: "backend"` or `only: "frontend"` only when exactly one side of
the diff has something to say. Pass the same `--only=` to `pipeline:verify`. A
mistyped tag refuses the run rather than passing.

**For the change-level check, open a `change` run rather than running a suite by
hand.** Its test step is `tools/verify/affected-tests.sh`, so a bare
`vendor/bin/pest` over the suite answers a narrower question and records no
receipt.

This is about the gate, not about iterating. Running one file or one
`--filter=testName` while you work on that test is the narrowest set that covers
the change, and "Running Tests" below still governs it. Run
`affected-tests.sh` directly only for a richter-derived selection outside a walk,
and never as `php artisan test $(php artisan richter:affected-tests --plain)`,
because an empty selection from richter exits 0 and prints nothing, so a bare
substitution runs the whole suite.
