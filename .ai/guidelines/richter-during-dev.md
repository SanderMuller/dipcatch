# Richter during development

Run a filtered blast-radius pass while writing backend PHP. `/richter-review` stays the full PR-time review (invoke-only). Procedure: `richter-during-dev` skill. Slash command: `/richter-during-dev`.

## When

Once after: (1) the first vertical slice that adds or changes an entry surface (controller, Livewire, job, command, route, `AppServiceProvider` binding); (2) each `implement-spec` phase that writes `app/`; (3) evaluate / closeout.

Skip if this already ran in the conversation and no `app/` PHP changed since. Skip comments, Pint, assertion-only, and frontend-only diffs. If JS calls a new backend route, still run the slice (`affectedTests` in the output).

## Scope

`--base` is the **task** parent — the same range evaluate uses. Never default to `origin/main` on a stacked or mixed branch. Uncommitted task: `HEAD`. One commit: `HEAD~1`. Branch is this task: `origin/main`. Unrelated dirty files: `--head=HEAD`.

## Invoke

Artisan is the contract. Do not call `richter:detect-changes`. Do not filter `entryPoints` in the model.

```bash
php artisan richter:task-slice --base=<task-base>
php artisan richter:task-slice --base=<task-base> --head=HEAD
```

MCP `task-slice` and `detect-changes` (`head` included) exist from richter 0.63. Prefer the Artisan slice even when `GetDynamicTools` lists `richter`.

## Keep-set (already applied)

Keep a surface whose own file is in the diff, or that is not reached only through a hub (`config/richter.php` `task_slice`). Unexplained surfaces stay. Drop hub fan-out. `ownReach` is not a keep rule. Feature tokens are not a keep rule.

Zero `kept` is not “nothing to test”. A product loader or DTO is not a route. When `runImpact` is true, run `php artisan richter:impact "FQCN"` for each `runImpactOn` (MCP `impact` only if the namespace exists). A session test that hits the class counts even when Richter does not name it.

## Act

`unreferencedKept`: no test proves the surface — confirm coverage or add a test that names the route or command. Hazards: update callers and tests. Findings: read them. `affectedTests` is never narrowed by hubs. `affectedTestsDeterminable: true`: run `affectedTests`. `false`: run `affectedTests` when the list is not empty, then related tests; full suite at evaluate.

Richter does not read the spec. “No hazards” is not “the feature is correct.” Do not paste the raw detect-changes report.

## Running the selection

`tools/verify/affected-tests.sh` runs exactly the files `richter:affected-tests` names, and
falls back to the whole suite only when the selection is undeterminable. Use it rather than
`php artisan test $(php artisan richter:affected-tests --plain)` — an empty selection passes no
paths to `test`, which then runs everything without saying so. `tools/verify/affected-tests.test.sh`
covers the wrapper itself:

```bash
bash tools/verify/affected-tests.sh
bash tools/verify/affected-tests.test.sh tools/verify/affected-tests.sh
```
