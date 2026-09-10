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

## Shared Redis — Keep the Key Prefix

Every project on this Laravel Cloud account shares one Redis instance. The key prefix is the only thing that separates them, so a lost prefix means one project reads and overwrites another project's cache, sessions and queues.

- Keep `REDIS_PREFIX` and `CACHE_PREFIX` app-specific. Never set either to an empty value, and keep the `Str::slug(APP_NAME)` defaults in `config/database.php` and `config/cache.php`.
- Every connection under `database.redis` inherits the top-level `options.prefix`. A per-connection `prefix` or `options` key overrides it — do not add one that clears the prefix.
- Do not use a database index for isolation. Managed Redis may allow index 0 only, and an index is not a namespace.
- A package or client that reaches Redis outside `Redis::connection()` never gets that prefix. Give it an app-scoped prefix in its own config — `queue-insights.key_prefix` is one such setting.

---

# Laravel Boost

## Project Rules
- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.


## Artisan
- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker
- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

---

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

---

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

---

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context
This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.



## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `yarn run build`, `yarn run dev`, or `composer run dev`. Ask them.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

---

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

---

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

@scoped(['app/Models/**'])
### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.
@endscoped

@scoped(['app/Http/**', 'routes/**'])
## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.
@endscoped

## URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

@scoped(['tests/**'])
## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.
@endscoped

## Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `yarn run build` or ask the user to run `yarn run dev` or `composer run dev`.

---

@scoped(['app/Livewire/**', 'resources/views/**'])
# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.
@endscoped

---

@scoped(['tests/**'])
# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.
@endscoped

---

## PHP 8.4

Use these array functions instead of manual loops when not using Laravel collections:
- `array_find(array $array, callable $callback): mixed` - first matching element
- `array_find_key(array $array, callable $callback): int|string|null` - first matching key
- `array_any(array $array, callable $callback): bool` - true if any element matches
- `array_all(array $array, callable $callback): bool` - true if all elements match

Chain directly on new instances without wrapping in parentheses:
```php
// Before: $response = (new JsonResponse(['data' => $data]))->setStatusCode(201);
$response = new JsonResponse(['data' => $data])->setStatusCode(201);
```

---

## PHP 8.5

Use these array functions instead of manual loops when not using Laravel collections:

- `array_first(array $array): mixed` - first value or `null` if empty
- `array_last(array $array): mixed` - last value or `null` if empty

Use the pipe operator (`|>`) to chain function calls left-to-right instead of nesting:

```php
// Before: $slug = strtolower(str_replace(' ', '-', trim($title)));
$slug = $title |> trim(...) |> (fn($s) => str_replace(' ', '-', $s)) |> strtolower(...);
```

Use `clone($object, ['property' => $value])` to modify properties during cloning. Ideal for readonly classes.

---

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

---

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

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
  ships one (commonly under `tools/verify/`, with a setup doc loaded on demand); otherwise the
  `frontend-quality` skill's shipped harness (`scripts/`) or a Playwright MCP server.
  DOM/console first; screenshots back up visual claims.
- **Cover every testable, name the gaps.** Derive the checklist first (ticket steps, edge
  cases, design annotations), assert one testable per check, drive full flows and mutations
  (create → round-trip → delete) — not just the happy path — and list anything you couldn't
  drive as NOT-VERIFIED. A green run that quietly skipped cases is the failure mode to avoid.
- **Verify behaviour, not just geometry** — a fixed/sticky element must also not be painted
  over, and pop-out content (dropdowns / tooltips / modals) must still escape.
- **Drive the failure path.** Most "works locally" bugs live where an endpoint fails — force
  it to fail, assert the UI shows a visible error and a way forward (not a silent hang), then
  clear the fault and assert recovery.
- **In an ephemeral clone or git worktree**, the app may be served at a different host/port
  than the canonical checkout, so the harness can silently verify the *wrong* tree — confirm
  it targets *this* checkout, and sanity-check the host serves a real page before trusting a
  green. A hard 404 on the expected page is the signature of hitting the wrong host.
- If a harness genuinely can't run this session (no seeded data, wrong host served, no login),
  say so — record it as an explicit deferral rather than substituting reasoning for the browser
  or reporting an unqualified green.

The coverage contract, the traps that fake a green run, and fault injection are detailed in the
`frontend-quality` skill's `references/eye-verify.md`.

### Verify against the design, per element

When the change has an approved design (a mockup, a Figma frame, a ticket attachment), don't
eyeball the whole image and call it close — *"looks about right"* is how visual regressions
ship (a 4px-vs-8px radius, a lost gradient, a control 3px off-centre). Verify it **element by
element, attribute by attribute**, and record each delta as a fix or a question for the
designer. The full attribute rubric and the per-element scoring table live in the
`frontend-quality` skill's `references/design-verification.md` — that skill walks it as a
suggested step, and the `pull-requests` skill flags it before a PR.

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

### Guard Each Statement On an Engine Without Transactional DDL

A migration runner records a migration only after its whole `up()` returns, and it wraps the run in a transaction only for engines it treats as supporting transactional DDL. Laravel does that for PostgreSQL and SQL Server, and not for MySQL, MariaDB or SQLite. Without that transaction, a run that dies halfway leaves the applied statements in place with nothing recorded, and the retry fails on the first statement it already applied, blocking every later migration. Check which side your engine and runner fall on before assuming a failed migration rolled back.

Make each statement in a multi-statement migration skippable when it is already applied, using the runner's own column and index checks.

```php
// ❌ WRONG — a retry after a half-applied run dies on "Duplicate column name"
Schema::table('orders', function (Blueprint $table) {
    $table->string('reference');
    $table->index('reference');
});

// ✅ CORRECT — each statement checks for itself first
if (! Schema::hasColumn('orders', 'reference')) {
    Schema::table('orders', fn (Blueprint $table) => $table->string('reference'));
}

if (! Schema::hasIndex('orders', 'orders_reference_index')) {
    Schema::table('orders', fn (Blueprint $table) => $table->index('reference'));
}
```

Keep slow work out of the migration on a large table: build an index, backfill a column, or add a foreign key as a separate job or an out-of-band task, not inside the deploy step.

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

### Annotate Rather Than Suppress

Some errors are PHPStan reading a signature that says less than the code does — a return type a parameter decides, a bool helper that proves a type. The `backend-quality` skill carries the two annotations that state the missing fact, and the rules for when each one lies.

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

## Task Scope and Edits

For session, branch, and PR scope, see the `single-issue-scope` guideline when the project enables it.

### The Task Sets the Scope

- Do not fix a pre-existing bug, a performance problem, or unrelated behaviour you find on the way, unless the requested behaviour cannot work without it. The same holds for refactors, cleanup, and documentation nobody asked for. A defect your own change introduces is not pre-existing: fix it.
- A sibling rule that requires an update on a line you already change still applies. The rule removes extras, not obligations.
- Report the rest as a follow-up in your summary. Propose an issue when the project tracks work that way, and let the user decide whether to file it. Report it; do not fix it.
- Implement every behaviour the task does ask for, completely. This rule cuts extras, never the requested scope.

### One Reading of an Ambiguous Ask

Implement the reading that the wording and the surrounding code support most directly. State that assumption in your summary. Do not build for both readings.

Materially different work is the test. When two readings would produce the same change, pick one and carry on. When they would not, or when a wrong guess is unsafe or makes the work useless, ask before building — through the `clarify` skill where the whole ask is fuzzy, otherwise with a direct question.

### Edit in Place

Change only the lines that must change. Rewrite a whole file only when the file is short, or when most of it changes. A rewrite churns lines the task never touched and can drop content by accident.

---

## Verification Before Completion

Before claiming any work is complete or successful, run the verification command fresh and confirm the output. Evidence before claims, always.

### Claims About How the Code Behaves — Trace, Don't Assume

A claim about **how the code currently behaves** — a root cause, an existing mechanism, or present behavior — in a spec, PR, commit message, code-review finding, issue, comment, or answer must be traced to the actual code (or observed at runtime) **before** you write it, never asserted from plausibility. (This governs statements of *fact about the present code*; the *intended* future behavior a spec or PR proposes is fine when it's clearly framed as a requirement, proposal, or decision — not disguised as a fact about what already exists.) Every illustrative example must be one you actually observed, never invented to fit a guess. A wrong "why" is worse than none: reproduction steps, tests, QA testables, and the fix itself all get built on the stated cause, so one unverified guess corrupts everything derived from it. When you have not traced it, say so — mark it `NEEDS-CONFIRMATION` or ask — rather than asserting. (A ticket once claimed a list was "sorted by display name" and backed it with an example that could not occur; the sort actually keyed on an internal identifier — one grep away. The trace is cheap; the false premise is not.)

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

### A Commit Is a Claim Too

Commit a change once its own checks pass against the tree as it stands, not while the approach is still being tried. A commit reads as a decision. The next defect then gets patched on top of the approach instead of the approach being dropped, and each extra commit raises the cost of the revert that was the right answer.

Deferring is not "never commit". Uncommitted work is unprotected, and a commit is still the safe way to set work aside or to hand it over. A measurement loop inverts the rule on purpose — it commits before it measures, so a rejected experiment reverts in one step. Where a skill states that it commits first, that skill wins for its own flow.

### Never Use Without Evidence

- "should work now"
- "that should fix it"
- "looks correct"
- "I'm confident this works"

These phrases indicate missing verification. Run the command first, then report what actually happened.

### Say What You Did Not Verify

State the limits of your own check. A reader cannot tell a gap you did not mention from a check you ran, so an unmentioned gap counts as a claim you did not make good on.

When you report a result, name what you ran and what you did not. "The unit suite passes; I did not run the browser tests" is a complete report. "Tests pass" is not, when you ran one suite of three. The same holds for a claim you carried over from an earlier step: if you did not re-run it against the tree as it stands now, say so.

This is the outward half of the `NEEDS-CONFIRMATION` rule above. That rule stops you asserting an untraced cause. This one stops a traced, true statement from implying more than it covers.

---

## Voice — Which Rule, Which Surface

This table decides which rule applies to a piece of text. Never apply both to the same words, and never guess.

| Surface | Rule |
|---|---|
| Chat replies to the user | Simplified Technical English |
| PR titles, descriptions, checklists | Simplified Technical English |
| PR review comments and replies to reviewers | Simplified Technical English |
| Issue and ticket descriptions, comments, QA testables | Simplified Technical English |
| Spec files | Simplified Technical English |
| `AskUserQuestion` questions, options, descriptions | Simplified Technical English — plus the pronoun rules in the `AskUserQuestion Phrasing` guideline, when the project has it |
| Commit messages | Simplified Technical English — an issue key the project's commit format requires stays as it is |
| Text an end user reads — in-app copy, translations, release notes, help text, seed content | The project's own tone-of-voice rules, not this guideline |
| Suggested translation strings inside an issue or ticket | The project's own tone-of-voice rules — the prose around them stays Simplified Technical English |
| Code and code comments | Neither — the language guidelines own those |
| Prose the user asks for in a named style, or an artifact whose own skill defines its voice — `humanizer`, `readme`, `release-notes` | That instruction or skill wins. This guideline does not override it |

A surface the table does not list gets Simplified Technical English, unless an end user reads it. Then it gets the project's tone-of-voice rules. A project without documented tone-of-voice rules gets Simplified Technical English everywhere.

This guideline governs **how a sentence is built**, and how much you write. It never overrides what a document is allowed to say: an issue-format doc still owns issue content, and a PR template still owns its sections.

### Simplified Technical English

**Write in ASD-STE100 Simplified Technical English.** Say the same thing in fewer, simpler words.

- One idea per sentence. Keep procedural sentences to 20 words or less, descriptive sentences to 25 or less.
- Use the active voice. Name the actor. Use the passive only when the actor is unknown.
- Use simple tenses only — simple present, simple past, simple future, infinitive, imperative. No complex constructions built from auxiliary verbs.
- Use one word for one meaning. Use the same word for the same thing every time — do not vary it for style.
- Keep articles (`the`, `a`, `an`) and other small words that make a sentence clear. Simplified is not clipped.
- One topic per paragraph, six sentences at most. Use a list when there is more than one item.
- Cut filler, hedging, and repetition. Do not restate the question or summarise what you are about to say.
- Give the answer first. Add detail after it, and only if the reader needs it.
- Use everyday words. Write "use", not "utilise"; "help", not "facilitate". Keep technical terms exact — a class name, a flag, or an error message is quoted as it is.
- Write Latin abbreviations out: "for example", not "eg"; "that is", not "ie"; "and so on", not "etc".
- Do not shout. No exclamation marks, no capitals for emphasis, and no bold used only to raise the volume. Structural bold that a template defines — `**Before:**`, `**Expected:**`, a table header, a labelled line — is not emphasis and stays.
- No metaphors, no clichés, no jokes that carry meaning the plain sentence does not.

The sentence limits, the tense list, the article rule, and the paragraph limit come from the ASD-STE100 writing rules. The everyday-words, Latin-abbreviation, no-shouting, and no-metaphor rules come from the GOV.UK content style guide.

### Register and Volume

Simplified Technical English decides how a sentence is built. This section decides how much you write and how you format it. A reply can pass every rule above and still read as machine output, because it is ten times the size of the question and formatted as a report.

**Answer at the size of the question.** A one-line question gets a one-line answer. A yes/no question gets "yes" or "no", and a reason only if the reader cannot act without it. Do not pad a short answer to look thorough. A longer answer does not show more care, and the reader has to find the answer inside it. A sentence that names what you did not verify is content, not padding, and stays.

**Do not format someone else's thread like a document.** In a PR comment, an issue comment, a chat reply, or a review reply, do not use bold section headings, tables, or fenced evidence blocks unless the reader asked for detail or the content cannot be read without them. A code block that quotes real output or a real diff is content and stays. The rest reads as machine output whatever the words are worth.

**One answer per turn.** Do not answer a question and then add a closing observation about what the work taught you. Stop when the answer is complete.

**Read the thread again as the last step before you post.** The thread can move while you draft. A reply to a question the other person already withdrew costs more than a slow reply.

Apply these rules most strictly outside your own repository. A maintainer who does not know you can reject a contribution on this basis alone, and the change itself is then no longer read on merit.

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
