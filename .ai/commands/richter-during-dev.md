---
description: Filtered Richter blast-radius pass for the current backend task — hub fan-out dropped. Not /richter-review.
argument-hint: '[base-ref | nothing → task parent]'
disable-model-invocation: true
---

ROLE
Run the during-dev Richter task slice for this backend change. This is not `/richter-review`.

NON-NEGOTIABLE
- Run `php artisan richter:task-slice`. Do not call `richter:detect-changes`.
- Do not read or filter the full `entryPoints` array. The slice already applied the keep-set.
- Artisan is the contract. MCP `task-slice` exists from 0.63; prefer Artisan even when the `richter` namespace is listed.

STEP 1 — BASE
`--base` is the task parent (the same range evaluate uses). Uncommitted work: `HEAD`. One commit: `HEAD~1`. Branch is this task: `origin/main`. Unrelated dirty files: add `--head=HEAD`.
If the user typed a git ref after this command, use it as `--base`.

STEP 2 — SLICE

```bash
php artisan richter:task-slice --base=<task-base>
```

Add `--head=HEAD` when unrelated dirty files must stay out of the diff.

STEP 3 — ACT
Follow `.ai/skills/richter-during-dev/SKILL.md` on the slice JSON:
- `kept` / `unreferencedKept` (no test proves) / `hazards` / `findings`
- `droppedHubCount` is a count only — do not open hub fan-out
- `runImpact` true: `php artisan richter:impact "FQCN"` for each `runImpactOn`
- `affectedTestsDeterminable` true: run `affectedTests` (isolated DB). False: run `affectedTests` when the list is not empty, then related tests; full suite at evaluate

Tell the user in one short block. Do not paste a detect-changes report.
