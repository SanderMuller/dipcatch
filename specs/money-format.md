# Money Format: `€1.69`

<!-- spec:planned-at f9c8dc5579371f341074a342fc6b8594d9a44ecb 2026-09-02 +uncommitted -->

## Overview

Every price in DipCatch renders as `EUR 1.69` today — the ISO code, a space, a dot decimal. Dutch users read supermarket prices as `€1,69`; non-Dutch beta testers read `€1.69`. The interview settled on **currency symbol + dot decimal** (`€1.69`, `$1.69`, `£1.69`) applied everywhere: Filament tables and infolists, unit prices, probe previews, the public share page, emails and the digest, push notifications, chart labels, and the homepage mock. One formatter owns the rule; the five places that concatenate the code by hand are routed through it.

## Assumptions

- **Symbols come from PHP `intl`** (`NumberFormatter` with the `en_US` locale in `CURRENCY` style, then the currency code passed per call) — installed in this project (`php -m` lists `intl`). A currency intl has no symbol for renders as its code plus a space (`CHF 1.69`), which is intl's own fallback, so unknown codes never break.
- **Dot decimal, comma thousands, always two decimals**: `€1,234.56`. This is the `en_US` pattern; no locale switch per user, because the user's `default_currency` is a currency, not a locale, and the app UI is English.
- **No space between symbol and amount** (`€1.69`, not `€ 1.69`) — the `en_US` intl pattern; matches the user's chosen option label.
- **Unit-price labels keep their form**: `€8.45 /kg`, `€0.42 /stuk` — the formatter renders the amount, `Shop::unitPriceLabel()` supplies the suffix, unchanged.
- **Null stays `—`** (`MoneyFormatter::format(null, …)` today) and nothing else changes for missing prices.
- **Chart datasets keep numeric values**; only *labels* and *tooltips* change (`Cheapest (€)` instead of `Cheapest (EUR)`, tooltip `€1.69`). Axis tick formatting is left to Chart.js defaults, as today.
- **No feature flag** (Resolved Question 2): pure rendering change, reverted by one commit.

---

## 1. Current State

- `app/Support/MoneyFormatter.php:7-14` — `format(?string $amount, string $currency): string` returns `'—'` for null, else `$currency . ' ' . number_format((float) $amount, 2, '.', ',')`. Callers (7 files): `ProductsTable`, `ProductInfolist`, `ShopsRelationManager`, admin `ShopsTable`, `create-product-from-url.blade.php`, `add-shop.blade.php`, `public/product.blade.php`.
- Hand-concatenated money (bypassing the formatter):
  - `app/Filament/App/Widgets/ActiveDropsTableWidget.php:35,38` — `$r->currency . ' ' . ($r->cheapest_price ?? '—')` (also renders `EUR —` for null, unlike the formatter).
  - `app/Notifications/PriceDropNotification.php:72` — web-push body `$this->product->currency . ' ' . $this->snapshotPrice`.
  - `resources/views/emails/price-drop-digest.blade.php:25` — `{{ $event->currency }} {{ number_format(...) }}` twice (new price, absolute drop).
  - `app/Filament/App/Resources/Products/Widgets/PriceHistoryChart.php:82` — dataset label `'Cheapest (' . $product->currency . ')'`.
  - `app/Filament/App/Widgets/SavingsByMonthChartWidget.php:61` — dataset label is the bare currency code.
- `StatsOverviewWidget` (`app/Filament/App/Widgets/StatsOverviewWidget.php`) builds `$defaultCurrency . ' ' . number_format(...)` for lifetime savings and an "Also: USD 12.00 · …" description — two more hand-rolled sites.
- Homepage mock (`resources/views/welcome.blade.php`) hard-codes `€:new` strings; `specs/homepage-relaunch.md` Phase 1 routes it through the formatter.

## 2. Proposed Changes

### 2.1 `MoneyFormatter`

```php
final class MoneyFormatter
{
    public static function format(?string $amount, string $currency): string   // '—' for null; '€1.69'
    public static function symbol(string $currency): string                     // '€', '$', or 'CHF' when intl has no symbol
}
```

- `format()` uses a per-request-memoised `NumberFormatter('en_US', NumberFormatter::CURRENCY)` and `formatCurrency((float) $amount, strtoupper($currency))`. intl yields `€1.69`, `$1.69`, `£1.69`, `CHF 1.69`, `¥2` (JPY has zero decimals — intl handles minor units; the spec accepts intl's per-currency decimals rather than forcing two).
- Non-numeric `$amount` (defensive; callers pass DB decimals) renders `—`, same as null.
- `symbol()` derives from the same formatter (`getSymbol(NumberFormatter::CURRENCY_SYMBOL)` after `setTextAttribute(CURRENCY_CODE, …)`), used by the chart labels.
- Under Octane the memoised formatter is a plain static; `NumberFormatter` is stateless between calls except the currency code, which `formatCurrency()` sets per call, so sharing is safe.

### 2.2 Route every site through it

| Site | Change |
|---|---|
| `ActiveDropsTableWidget:35,38` | `MoneyFormatter::format($r->cheapest_price, $r->currency)` / `last_notified_price` — also fixes `EUR —` |
| `PriceDropNotification:72` | `$priceLine = MoneyFormatter::format($this->snapshotPrice, $this->product->currency)` |
| `price-drop-digest.blade.php:25` | both cells via the formatter (new price, absolute drop); percentage unchanged |
| `PriceHistoryChart:82` | `'Cheapest (' . MoneyFormatter::symbol($product->currency) . ')'`; tooltip callback formats the point through the same rule client-side — see 2.3 |
| `SavingsByMonthChartWidget:61` | dataset label `MoneyFormatter::symbol($currency) . ' saved'` (was the bare code) |
| `StatsOverviewWidget` | lifetime savings and the "Also:" list through `format()` |
| Homepage mock | covered by `specs/homepage-relaunch.md` Phase 1 (uses the formatter) |

### 2.3 Chart tooltips

Filament chart widgets pass Chart.js options as an array; tooltips are client-side. Each chart widget sets `options.plugins.tooltip.callbacks.label` to a small JS snippet that prefixes the dataset's symbol — the symbol travels in the dataset (`'symbol' => MoneyFormatter::symbol(...)`) so the JS never needs a currency table. Implementer verifies in the browser that a hovered point reads `€1.69`.

### 2.4 Tests

- Unit (`tests/Unit/Support/MoneyFormatterTest.php`): `EUR → €1.69`, `USD → $1,234.56`, `GBP → £0.99`, `JPY → ¥200`, `CHF → CHF 1.69` (code fallback with intl's own spacing), null → `—`, non-numeric → `—`, `symbol('EUR') === '€'`, lowercase code accepted.
- Feature: the shops table, products table, infolist "Cheapest now", public share page, add-shop preview, active-drops widget (including the null case now rendering `—`, not `EUR —`), digest mail markdown, and the web-push body all contain `€1.69`-style strings and no `EUR 1.69`. A repo-wide test asserts no Blade or PHP file under `app/` and `resources/views/` (excluding `MoneyFormatter` itself) concatenates `->currency . ' '` or `{{ $…->currency }} {{` — the pattern that produced the five bypasses.

## 3. Mobile

Symbol-first amounts are narrower than `EUR 1.69`, so no layout risk; the implementer still checks the shops table and the public share page at 390 px, where the shop row already stacks the unit price under the price.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Currency intl has no symbol for (e.g. `CHF`) | intl's fallback: code + space + amount (`CHF 1.69`); unit test pins it |
| Zero-decimal currency (`JPY`) | intl's minor units win: `¥200`; unit test pins it |
| Null price in the active-drops widget | `—` alone (today `EUR —`) |
| Non-numeric string reaches the formatter | `—`, no exception |
| Lowercase currency code from old data | Upper-cased before formatting |
| Mixed currencies in the savings chart | One dataset per currency; label shows each symbol (`€ saved`, `$ saved`) |
| Push body length | `€1.69` is shorter than `EUR 1.69`; no truncation concern |

## Implementation

- [ ] Rewrite `MoneyFormatter::format()` on `NumberFormatter` (en_US, CURRENCY, memoised) and add `symbol()` — Section 2.1.
- [ ] Route the seven bypass sites through the formatter — Section 2.2 table.
- [ ] Chart tooltips/labels via dataset-carried symbol — Section 2.3; browser-verify a tooltip on the price-history chart and the savings chart.
- [ ] Tests — Section 2.4, including the repo-wide anti-bypass test.
- [ ] Browser check at 390 px and 1280 px: shops table, products list, infolist, public share page, add-shop preview, dashboard widgets; light and dark.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **PHP `intl` is available in production (Laravel Cloud) as it is locally** — the whole design rests on `NumberFormatter`; without it, fall back to a hand-written symbol map and report.
2. **Filament chart widgets accept a JS tooltip callback via `getOptions()` in this Filament version** — if options are JSON-only (no functions), tooltips keep the default and only labels change; report the gap.

---

## Open Questions

None.

---

## Resolved Questions

1. **`€ 1,69` (Dutch) vs `€1.69` vs `EUR 1.69`?** **Decision:** `€1.69` — symbol, dot decimal. **Rationale:** the symbol reads naturally for Dutch users; the dot decimal keeps the English app consistent for non-Dutch testers and avoids touching any number parsing.
2. **Feature flag?** **Decision:** No. **Rationale:** pure rendering change with no data impact; revert is one commit.
3. **Scope?** **Decision:** Everywhere — UI, emails, push, charts, share page, homepage mock. **Rationale:** one rule, one formatter; partial application would leave the app inconsistent between surfaces.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
