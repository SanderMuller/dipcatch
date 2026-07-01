## Always Capture Command Output

Append `|| true` to all verification commands (tests, linting, type checks) so the output is always captured, even on failure. Without it, a non-zero exit code can hide the output, forcing an expensive second run just to read the errors.

```bash
# CORRECT — output always visible
vendor/bin/pest --filter=testName || true
vendor/bin/pint --dirty --format agent || true

# WRONG — output lost on failure, wastes time re-running
vendor/bin/pest --filter=testName
```

---

## Release Notes vs CHANGELOG

`CHANGELOG.md` is **auto-populated by CI** on release. Do not hand-edit it.

When you need to document a user-facing change for a release, write it to `RELEASE_NOTES_<version>.md` at the repo root (already gitignored via the `RELEASE_NOTES*.md` pattern). The CI release job picks it up and promotes it into `CHANGELOG.md` as part of the tag flow.

If you find yourself editing `CHANGELOG.md` directly, stop — it will be overwritten.

---

## AskUserQuestion Phrasing

When writing an `AskUserQuestion` question, option labels, or option descriptions, **avoid first- and second-person pronouns** — `I`, `me`, `my`, `we`, `our`, `you`, `your`. In that tool the user is reading a question *from* the assistant and answering it, so the roles are inverted and these pronouns are ambiguous: the reader cannot tell whether `I`/`my` means the assistant or themselves, nor whether `you`/`your` means them or the assistant.

Name the actor explicitly instead — "the assistant" (these guidelines are shared across agents, so avoid hard-coding a product name like Claude or Copilot) and "the user" (or a concrete role) for the person answering — or rephrase to drop the pronoun entirely.

```text
❌ "Which approach do you want me to take?"
❌ "Should I keep the existing tests you wrote?"

✅ "Which approach should the assistant take?"
✅ "Keep the existing tests, or replace them?"   (pronoun dropped)
✅ "Should the assistant keep the tests already in the repo?"
```

This applies to every part of the question payload: the `question` text, each option `label`, and each option `description`.

---

## Database Safety

### Never Run Destructive Database Commands

**Do not run commands that drop, wipe, reset, or recreate a database or its tables** — regardless of flags or environment arguments. Destructive operations include, whatever the stack:

- Framework commands that drop and rebuild the schema (a "fresh", "reset", "refresh", or "wipe" migration command).
- Raw SQL `DROP` or `TRUNCATE` against any database.
- Restoring or re-importing a database over an existing one.

These destroy data. An environment flag (`--env=...`, an alternate connection name) is **not** a safety net — it only helps if a separate, correctly configured environment actually exists. If you are unsure which database a destructive command targets, do not run it.

### Test Database

- The test database is owned by the project's test runner. Let the test suite create, migrate, and tear it down — never migrate or refresh it by hand.
- If the test database gets into a broken state, ask the user to fix it rather than running destructive commands.

### Safe Operations

Safe — these advance or add to the schema without destroying data:

- Running pending migrations **forward** on a non-test database — *after* checking that the pending files only add or alter columns. A forward migration is not automatically safe: it can still drop a column or table, or delete data in a backfill. Read it first.
- Running the test suite (it manages its own database lifecycle).
- Seeding additional data without truncating existing tables.

### When a Destructive Operation Is Genuinely Needed

Stop and ask the user to run it themselves, or to confirm it explicitly. Never decide on your own that data loss is acceptable.

---

## JavaScript & TypeScript

### Control Structures

- Always use curly braces for control structures, even for a single statement.
- Never use single-line `if/return`, `if/break`, or `if/continue` statements.
- Each control-structure statement goes on its own line.

```js
// ❌ WRONG — single-line control structures
if (index === -1) break;
if (! element) return 0;
if (query === '') return;

// ✅ CORRECT — curly braces, each statement on its own line
if (index === -1) {
    break;
}

if (! element) {
    return 0;
}

if (query === '') {
    return;
}
```

## Eye-verify frontend changes (browser/runtime)

A change that renders UI calls for **seeing it run in a real browser** — type-check and linting
can't see runtime/visual bugs: stale state, dead toggles, broken scroll / sticky / fixed
behaviour, z-index show-through, async races, untranslated-key leaks.

- **When:** the diff touches code that renders to users — JS/TS that drives the DOM, or a
  server-rendered template/component.
- **How:** drive it in a real browser. Use the project's browser eye-verify harness if it
  ships one (commonly under `tools/verify/`, with a setup doc loaded on demand); otherwise a
  browser-automation tool (Playwright, or a Playwright MCP server). DOM/console first;
  screenshots back up visual claims.
- **Verify behaviour, not just geometry** — a fixed/sticky element must also not be painted
  over, and pop-out content (dropdowns / tooltips / modals) must still escape.
- **In an ephemeral clone or git worktree**, the app may be served at a different host/port
  than the canonical checkout, so the harness can silently verify the *wrong* tree — confirm
  it targets *this* checkout, and sanity-check the host serves a real page before trusting a
  green. A hard 404 on the expected page is the signature of hitting the wrong host.
- If the harness can't run (no seeded data, wrong host served, no login), **stop and ask** —
  don't substitute reasoning for the browser.

### Verify against the design, per element

When the change has an approved design (a mockup, a Figma frame, a ticket attachment), don't
eyeball the whole image and call it close — *"looks about right"* is how visual regressions
ship. Verify it **element by element**:

- List each changed element, plus the element as a whole; exclude anything documented as
  out of scope.
- Check each against the design attribute by attribute: alignment (horizontal and vertical),
  size, text and background colour (including gradients), border presence / colour / width,
  border-radius, icon, typography (family, weight, size), and spacing.
- Record the deltas. Each mismatch is either a fix or a question for the designer — a
  whole-image glance misses a 4px-vs-8px radius or a lost gradient.
- When you crop a screenshot to a single element, keep a small margin (~15px) around it — a
  flush crop hides the alignment and spacing errors at the element's own edges.

The `frontend-quality` skill walks this as a suggested step; the `pull-requests` skill flags
it before a PR.

---

## Migrations

Conventions for schema migration files, whatever the migration tool. Examples use a schema-builder DSL for illustration; the principles apply to raw-SQL migrations too.

### Self-Contained Migrations

- Migrations must be fully self-contained. Never reference application code — model constants, enums, config values, or helper functions.
- Use plain string and scalar literals for column names, table names, and other identifiers directly in the migration file.
- This keeps migrations stable and runnable regardless of future application code changes — a migration written today must still run years later, even if the code it once referenced has been renamed or deleted.
- Legacy migrations may still reference application code; only update them to follow this guideline when you are otherwise modifying those migrations.

```php
// ❌ WRONG — references an application constant
$table->boolean(Feature::FLAG_ENABLED)->nullable();

// ✅ CORRECT — plain string literal
$table->boolean('flag_enabled')->nullable();
```

### Column Ordering

- Add new columns at the **end** of the table — do not insert one into the middle of an existing table.
- On MySQL/MariaDB, positioning a column mid-table (an `AFTER` clause) can disable instant/online DDL and force a full table copy — a significant hit on large tables. Other engines such as PostgreSQL have no column-position concept at all, so a position clause is meaningless there. Appending is safe and portable everywhere.

```php
// ❌ WRONG — mid-table positioning can force a full table rebuild on MySQL/MariaDB
$table->string('description')->after('name');

// ✅ CORRECT — just append the column
$table->string('description');
```

---

## Fixing PHPStan Errors

When fixing a PHPStan error, first decide whether it represents a runtime bug a test could catch — and if so, write that test before the fix.

### Process

1. **Assess testability** — does the error represent a runtime bug a test could reproduce (a wrong argument type, a missing method, an incorrect return type used downstream)?
2. **Write the test first** — if a test can catch it, write a failing test that reproduces the error before applying the fix.
3. **Fix the code** — apply the fix so both the PHPStan error and the new test pass.
4. **Verify both** — confirm PHPStan reports no error and the test passes.

### When to Write a Test

Write a test when the PHPStan error indicates a fault that would surface at runtime:

- A method call on a value of the wrong type
- Missing or incorrect arguments to a function or method
- A return-type mismatch that would break callers
- Accessing a property or method that does not exist
- Any type error that would manifest as a runtime exception

### When to Skip the Test

Skip the test when the error is purely static and cannot cause a runtime failure:

- Missing return-type declarations
- PHPDoc mismatches with no runtime impact
- Unused variables or imports
- Generic-type parameter issues

---

## Signed Commits

Applies **only when the repository has commit signing enabled** (e.g. `git config commit.gpgsign` is `true`, or a `user.signingkey` / `gpg.format` is set). If signing is not enabled, this guideline does not apply — commit normally.

### Never fall back to an unsigned commit

When signing is enabled, every commit must be signed. If the signing backend or agent (1Password, `gpg-agent`, `ssh-agent`, a hardware key, etc.) is unavailable, locked, or not responding:

- **Stop and surface the failure** to the user with the exact error.
- **Do not** retry with `--no-gpg-sign`, unset `commit.gpgsign`, or otherwise produce an unsigned commit to "get past" the problem.

A missing signature is a blocker to resolve (unlock the agent, re-authenticate 1Password, plug in the key), not a step to skip. Let the user fix the signing setup, then commit signed.

---

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Required Before Any Completion Claim

1. **Run** the relevant command (in the current message, not from memory)
2. **Read** the full output
3. **Confirm** it supports the claim
4. **Then** state the result with evidence

| Claim            | Required verification                                            |
|------------------|------------------------------------------------------------------|
| Tests pass       | The project's test command, output showing 0 failures            |
| Code style clean | The project's formatter/style checker, output showing no changes |
| Linting clean    | The project's linter, output showing 0 errors                    |
| Types check      | The project's type checker, output showing 0 errors              |
| Bug fixed        | The previously failing test now passes                           |
| Feature complete | All related tests pass                                           |

Use the project's own commands — check its `composer.json` / `package.json` scripts, CI config, or sibling docs to find them. Do not assume a specific tool.

### Delegating the checks

Where the project has dedicated quality-check skills synced, delegate to them — `backend-quality` for backend files, `frontend-quality` for frontend files, both when a change spans both. Otherwise, run the project's own equivalent commands directly.

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.

---

## FluentRule Validation

- This project uses `sandermuller/laravel-fluent-validation` for type-safe validation rules. Use `FluentRule::` instead of string rules or `Rule::` where possible.
- FormRequests MUST use `HasFluentRules` trait. Livewire components MUST use `HasFluentValidation` trait.
- Do NOT use `->rule('string_rule')` when a native FluentRule method exists. Check the skill references before using escape hatches.
- Available types: `FluentRule::string()`, `integer()`, `numeric()`, `email()`, `date()`, `dateTime()`, `boolean()`, `array()`, `file()`, `image()`, `password()`, `field()`.
- Convenience shortcuts: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()` — shorthand for `FluentRule::string()->url()`, etc.
- `email()` and `password()` use app defaults (`Email::default()`, `Password::default()`). Pass `defaults: false` to opt out.
- All conditional modifiers (`requiredIf`, `excludeIf`, `prohibitedIf`, etc.) accept both `(string $field, ...$values)` AND `(Closure|bool)` — do NOT wrap in `Rule::requiredIf()`.
- For converting validation rules, activate the `fluent-validation-optimize` skill which has a complete method reference.
- For Livewire-specific guidance, activate the `fluent-validation-livewire` skill.
