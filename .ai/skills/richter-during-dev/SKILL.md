---
name: richter-during-dev
description: >-
  MUST USE when implementing or evaluating backend PHP — a filtered Richter
  blast-radius pass (not /richter-review). Activates on spec phases, new jobs,
  Livewire, controllers, commands, or service bindings; evaluate / closeout;
  the first vertical slice; or mentions of blast radius, affected tests,
  ownReach, entryPointAttribution, task-slice, or parts we missed.
---

# Richter during development

Always-on rules: `.ai/guidelines/richter-during-dev.md`. If they disagree, the guideline wins.

This project pins richter **0.63+**. Run `php artisan richter:task-slice`. Do not call `richter:detect-changes`. Do not read or filter `entryPoints`.

## Procedure

1. **`--base`** is evaluate's task parent. Uncommitted → `HEAD`. One commit → `HEAD~1`. Branch is this task → `origin/main`. Mixed stack → this task's parent. Unrelated dirty files → `--head=HEAD`.

2. **Slice** — Artisan is the contract. One graph walk, keep-set already applied.

   ```bash
   php artisan richter:task-slice --base=<task-base>
   php artisan richter:task-slice --base=<task-base> --head=HEAD
   ```

   MCP `task-slice` (and `detect-changes` with `head`) exist from 0.63. Prefer the Artisan slice even when `GetDynamicTools` lists `richter`.

3. **Read the slice JSON only.** Keep-set is already applied:

   | Field | Act |
   |---|---|
   | `kept` | Surfaces this task owns (own file in the diff, or not hub-attributed; unexplained frontend routes stay) |
   | `unreferencedKept` | No test proves it (unreferenced, or the only tests have no recognised assertion). Add a test that names the route or command |
   | `hazards` | Contract changes on this diff — update callers and tests |
   | `findings` | Read them in the changed source |
   | `droppedHubCount` | Ignore. Do not open Filament / SuperAdmin / `Company` / `CrmEBlinqx` fan-out |
   | `runImpact` / `runImpactOn` | Required when `kept` is empty (a loader or DTO is not a route) |

   `via` is the explaining file. Feature tokens and `ownReach` are not keep rules. Hubs live in `config/richter.php` `task_slice`.

4. **`runImpact`** — for each FQCN in `runImpactOn`:

   ```bash
   php artisan richter:impact "App\Builders\CrmLoader\EBlinqxLoader\Products\AovLoader"
   ```

   MCP `impact` only if the `richter` namespace exists. A session test that hits the class counts even when Richter does not name it. Confirm `verificationFalse` is covered.

5. **Tests** — `affectedTests` is never narrowed by hubs. `affectedTestsDeterminable: true`: run `affectedTests` (isolated DB). `false`: the list is for the whole diff, not the keep set — run it when it is not empty, then related tests; full suite at evaluate.

6. **Tell the user** in one short block: `kept`, `unreferencedKept` and what you did, hazards applied, `droppedHubCount` only as a count. Do not paste the raw detect-changes report.

Spec edge cases, empty-vs-null, and fallbacks stay on evaluate / tests.
