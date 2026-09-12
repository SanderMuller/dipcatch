# Plan 004: Stop `canonicalizeDecimal` misreading two real price shapes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat c9daac7..HEAD -- app/PriceAdapters/PriceNormalizer.php`
> If the file changed since this plan was written, compare the "Current state"
> excerpt against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `c9daac7`, 2026-09-07

## Why this matters

`PriceNormalizer::canonicalizeDecimal()` guesses which separator is the decimal
point. Two guesses are wrong:

- `"1,2"` → `"12"`. The comma tail is 1 digit, which is not 2, so the code
  treats the comma as a thousands separator and strips it. **10× too high.**
- `"1.099"` → `"1.099"`, which the `decimal:2` cast on `shops.current_price`
  stores as `1.10`. In the European thousands form that string means 1099.
  **1000× too low.**

A wrong price is worse than a failed scrape. It is written to `price_checks`,
becomes `current_price`, can win `recomputeCheapestShop()`, and fires a price-drop
notification — every downstream guard passes, because the value is a valid
number. The `1.099 → 1.10` case is a guaranteed false drop alert; the
`1,2 → 12` case silently suppresses real drops by inflating the price.

**No test anywhere touches this function.** `grep -rn "canonicalizeDecimal\|PriceNormalizer" tests/`
returns nothing, so both bugs are uncovered.

## Current state

`app/PriceAdapters/PriceNormalizer.php:30-59`:

```php
/**
 * Normalize ambiguous decimal separators: prefer '.' as the decimal sep.
 *  - "1.234,56" → "1234.56"
 *  - "1,234.56" → "1234.56"
 *  - "1234.56"  → "1234.56"
 *  - "1234,56"  → "1234.56" (when tail = 2 digits)
 *  - "1,234"    → "1234"    (when tail = 3 digits, treat as thousands)
 */
public static function canonicalizeDecimal(string $value): string
{
    $hasComma = str_contains($value, ',');
    $hasDot = str_contains($value, '.');

    if ($hasComma && $hasDot) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasComma && ! $hasDot) {
        $tail = substr($value, strrpos($value, ',') + 1);
        if (strlen($tail) === 2) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    }

    return $value;
}
```

Note what is already correct and must stay correct: the both-separators branch
(`"1.234,56"` and `"1,234.56"`) resolves by which separator comes last, which is
sound. Only the single-separator branches are wrong.

Conventions: `<?php declare(strict_types=1);` header, comments explain **why**.
Unit tests for `app/Support` live in `tests/Unit/Support/` — this class is under
`app/PriceAdapters`, so create `tests/Unit/PriceAdapters/PriceNormalizerTest.php`
following the structure of any file in `tests/Unit/Support/`.

## Commands you will need

| Purpose        | Command                                                        | Expected on success |
|----------------|----------------------------------------------------------------|---------------------|
| Tests (scoped) | `vendor/bin/pest tests/Unit tests/Feature/PriceAdapters \|\| true` | all pass         |
| Full suite     | `vendor/bin/pest \|\| true`                                      | 0 failures          |
| Static analysis| `composer phpstan \|\| true`                                     | 0 errors            |
| Code style     | `vendor/bin/pint --dirty --format agent \|\| true`               | `"result":"passed"` |

## Scope

**In scope**:
- `app/PriceAdapters/PriceNormalizer.php`
- `tests/Unit/PriceAdapters/PriceNormalizerTest.php` (create)

**Out of scope**:
- Every adapter under `app/PriceAdapters/Hosts/` — they call this helper; none
  needs changing.
- The `decimal:2` cast on `shops.current_price`. Two decimal places is correct
  for money here; the bug is upstream of the cast.

## Git workflow

- Branch: `advisor/004-price-normalizer-ambiguous-decimals`
- Conventional commits (e.g. `fix: stop misreading one-digit and thousands price forms`)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the characterization tests first

Create `tests/Unit/PriceAdapters/PriceNormalizerTest.php` and cover the shapes
the docblock already claims, **before changing any behaviour**:

`"1.234,56"` → `1234.56` · `"1,234.56"` → `1234.56` · `"1234.56"` → `1234.56` ·
`"1234,56"` → `1234.56` · `"1,234"` → `1234` · `"9.99"` → `9.99` · `"12"` → `12`

Use a Pest dataset so each shape is its own case.

**Verify**: `vendor/bin/pest tests/Unit/PriceAdapters || true` → all pass
against the **unmodified** code. If any fails, the documented behaviour is
already broken elsewhere — STOP and report rather than changing the function.

### Step 2: Fix the one-digit comma tail

A 1-digit group is not a thousands group in any locale, so `"1,2"` is a decimal.
Change the condition to treat a tail of 1 or 2 digits as a decimal separator and
only 3 digits as a thousands group:

```php
} elseif ($hasComma && ! $hasDot) {
    $tail = substr($value, strrpos($value, ',') + 1);

    // A 3-digit group is a thousands separator ("1,234"); 1 or 2 digits is a
    // decimal ("1,2" is €1.20, not €12). Anything else is not a price shape
    // this parser can read.
    $value = match (strlen($tail)) {
        1, 2 => str_replace(',', '.', $value),
        3 => str_replace(',', '', $value),
        default => $value,
    };
}
```

Add `"1,2" → 1.2` to the test dataset.

**Verify**: `vendor/bin/pest tests/Unit/PriceAdapters || true` → all pass.

### Step 3: Refuse the ambiguous thousands form instead of guessing

`"1.099"` is genuinely ambiguous: it is 1.099 in one locale and 1099 in another.
Guessing produces a wrong price with full confidence, which is the failure mode
this plan exists to remove. Refuse it, and let the caller degrade to a parse
error:

```php
if (! $hasComma && $hasDot) {
    $tail = substr($value, strrpos($value, '.') + 1);

    // "1.099" is €1.099 in one locale and €1,099 in another, and a price with
    // three decimals is not a thing shops quote. Guessing here wrote 1.10 to
    // the database and fired a false drop alert, so refuse instead: the caller
    // records a parse error and the offer's health reflects reality.
    if (strlen($tail) === 3) {
        return '';
    }
}
```

Then confirm how the caller reacts to an unparseable value. Read
`PriceNormalizer::fromMixed()` (and whatever calls `canonicalizeDecimal`) in the
same file: it must end up returning `null` for this input, not `0.00`. If
returning `''` does not produce `null`, adjust so it does — the contract is
"unparseable", never "free".

Add `"1.099" → null` (via the public entry point) to the tests.

**Verify**: `vendor/bin/pest tests/Feature/PriceAdapters || true` → all pass.
This is the important run: the adapter suite is where a real page fixture would
regress if this refusal is too aggressive.

## Test plan

`tests/Unit/PriceAdapters/PriceNormalizerTest.php`, dataset-driven:

- The seven documented shapes from step 1 (characterization — must pass before
  and after).
- `"1,2"` → `1.2` — the 10× bug.
- `"1.099"` → unparseable — the 1000× bug.
- `"1.0"` and `"1.09"` → unchanged, proving step 3 only refuses 3-digit tails.
- `"€ 1.234,56"` and other strings with surrounding noise, if the public entry
  point is documented to accept them — check the docblock before asserting.

Prove the two bug cases fail against the original implementation (git stash the
source change, run, restore).

## Done criteria

ALL must hold:

- [ ] `composer phpstan || true` → 0 errors
- [ ] `vendor/bin/pint --dirty --format agent || true` → `"result":"passed"`
- [ ] `vendor/bin/pest || true` → 0 failures
- [ ] `tests/Unit/PriceAdapters/PriceNormalizerTest.php` exists and covers every
      shape listed in the test plan
- [ ] `git status --porcelain` lists no file outside the in-scope list
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The excerpt in "Current state" does not match the live code.
- Any step-1 characterization test fails against the unmodified function.
- The adapter suite (`tests/Feature/PriceAdapters`) fails after step 3. That
  means a real shop fixture quotes a 3-digit-tail price and refusing it breaks a
  working adapter. Report the fixture and the host — the trade-off then needs a
  human decision, because refusing a real price is its own harm.
- `fromMixed()` turns out to return `0.00` rather than `null` for an
  unparseable value and you cannot change that without touching an adapter.

## Maintenance notes

- The real fix for the ambiguity is a per-host locale hint: a Dutch supermarket's
  `"1.099"` is unambiguous once you know the host's locale. Deferred here because
  it needs an adapter-level contract change; this plan removes the wrong answer
  without inventing that contract.
- A reviewer should check that step 3 refuses rather than guesses. A future
  change that "helpfully" picks a locale reintroduces silent wrong prices.
