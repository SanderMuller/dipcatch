# Money Format: `€1.69`

<!-- spec:planned-at f9c8dc5579371f341074a342fc6b8594d9a44ecb 2026-09-02 +uncommitted -->

## Overview

Every price in DipCatch renders as `EUR 1.69` today — the ISO code, a space, a dot decimal. Dutch users read supermarket prices as `€1,69`; non-Dutch beta testers read `€1.69`. The interview settled on **currency symbol + dot decimal** (`€1.69`, `$1.69`, `£1.69`) applied everywhere: Filament tables and infolists, unit prices, probe previews, the public share page, emails and the digest, push notifications, chart labels, and the homepage mock. One formatter owns the rule; the eleven places that concatenate the code by hand are routed through it.

## Assumptions

- **Symbols come from PHP `intl`** (`NumberFormatter` with the `en_US` locale in `CURRENCY` style, then the currency code passed per call) — installed in this project (`php -m` lists `intl`). A currency intl has no symbol for renders as its code plus a space (`CHF 1.69`).
- **Whitespace contract**: ICU separates a code from the amount with a **non-breaking space** (U+00A0, verified: `CHF\xC2\xA0 1.69`). `MoneyFormatter::format()` normalises U+00A0 (and U+202F) to an ASCII space so HTML, plain-text mail, push bodies and test assertions all see `CHF 1.69` with one byte-stable form; the unit test asserts the ASCII-space string and that no `\u00A0` remains.
- **Input contract**: the currency must be a code in `Iso4217::CODES` (`Iso4217::isValid()`); ICU itself accepts any three letters (`ZZZ 1.69`, `U_ZERO_ERROR`) and renders an empty code as `¤1.69` (verified), so ICU's error code is *not* validation. An invalid or empty code renders the trimmed upper-cased code (or nothing when empty) plus a space plus `number_format(…, 2, '.', ',')`, and is pinned by unit tests for `ZZZ`, `''` and lowercase `eur`.
- **Dot decimal, comma thousands, intl's minor units per currency** (two for EUR/USD/GBP, zero for JPY): `€1,234.56`, `¥200`. This is the `en_US` pattern; no locale switch per user, because the user's `default_currency` is a currency, not a locale, and the app UI is English.
- **Storage stays two decimals** — every money column is `decimal(…, 2)`. Minor-unit handling is display-only: a two-decimal stored `200.00` JPY renders `¥200`; a three-minor-unit currency (BHD, KWD, …) would render a third digit intl invents from rounded data, so `Iso4217::CODES` must not gain such a currency without widening storage first (STOP condition 3).
- **Negative and zero amounts render as intl does**: `-€1.69`, `€0.00` (verified). Today no rendered amount is negative — `DropEvaluator` can compute a negative `dropAbsolute` but non-negative thresholds keep it from becoming a fired event — so the rule exists for future imports/corrections and is pinned by a unit test.
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
- Livewire probe previews render the **main** price by interpolation while their unit-price line already uses the formatter: `resources/views/livewire/shops/add-shop.blade.php:72` and `resources/views/livewire/products/create-product-from-url.blade.php:80` (`{{ $snapshot['currency'] }} {{ $snapshot['price'] }}`), and the shared `resources/views/livewire/shops/partials/variant-chooser.blade.php:29` (`{{ $variant['currency'] }} {{ $variant['price'] }}`).
- `app/Filament/App/Widgets/RecentNotificationsTableWidget.php:95-101` — private `formatMoney()` builds `($currency ?? '') . ' ' . number_format(abs(...), 2, '.', ',')` for the absolute drop.
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

- `format()` uses a **worker-local** (static, lives for the Octane worker's lifetime — not per request) `NumberFormatter('en_US', NumberFormatter::CURRENCY)` and `formatCurrency((float) $amount, strtoupper($currency))`. `formatCurrency()` takes the code as an argument and does not depend on the formatter's currency attribute, so the shared instance is safe for `format()`. intl yields `€1.69`, `$1.69`, `£1.69`, `CHF 1.69`, `¥200` (JPY has zero decimals — intl handles minor units; the spec accepts intl's per-currency decimals rather than forcing two).
- Non-numeric `$amount` (defensive; callers pass DB decimals) renders `—`, same as null.
- `symbol()` uses a **second, private** `NumberFormatter` instance (also worker-local) on which it calls `setTextAttribute(CURRENCY_CODE, …)` then `getSymbol(NumberFormatter::CURRENCY_SYMBOL)`; keeping the mutating call off the `format()` instance means neither method can observe the other's state. Verified locally: `EUR → €`, `CHF → CHF`. Both methods validate the code with `Iso4217::isValid()` first (see the input contract in Assumptions); ICU error codes are additionally checked only as a last-resort guard for a broken ICU build, with the same code-plus-`number_format` fallback.
- Under Octane both statics persist across requests; nothing request-specific is stored in them (locale is fixed `en_US`, currency is passed per call), so the persistence is harmless. The unit test alternates `symbol('EUR') → format('1.69','USD') → symbol('CHF') → format('200','JPY')` and asserts each result in sequence.

### 2.2 Route every site through it

| Site | Change |
|---|---|
| `ActiveDropsTableWidget:35,38` | `MoneyFormatter::format($r->cheapest_price, $r->currency)` / `last_notified_price` — also fixes `EUR —` |
| `PriceDropNotification:72` | `$priceLine = MoneyFormatter::format($this->snapshotPrice, $this->product->currency)` |
| `price-drop-digest.blade.php:25` | both cells via the formatter (new price, absolute drop); percentage unchanged |
| `PriceHistoryChart:82` | `'Cheapest (' . MoneyFormatter::symbol($product->currency) . ')'`; tooltip callback formats the point through the same rule client-side — see 2.3 |
| `SavingsByMonthChartWidget:61` | dataset label `MoneyFormatter::symbol($currency) . ' saved'` (was the bare code) |
| `StatsOverviewWidget` | lifetime savings and the "Also:" list through `format()` |
| `add-shop.blade.php:72`, `create-product-from-url.blade.php:80` | main preview price via `MoneyFormatter::format($snapshot['price'], $snapshot['currency'])` |
| `variant-chooser.blade.php:29` | each variant's price via the formatter |
| `RecentNotificationsTableWidget:95-101` | delete the private `formatMoney()`; call `MoneyFormatter::format(abs-value-as-string, $currency)` — the `abs()` stays because the column shows the size of the drop |
| Homepage mock | covered by `specs/homepage-relaunch.md` Phase 1 (uses the formatter) |

### 2.3 Chart tooltips

Tooltips are client-side. A PHP array cannot carry a JS function, so each chart widget returns its options as `Filament\Support\RawJs::make(<<<'JS' … JS)` (the `getOptions(): array | RawJs | null` signature in `vendor/filament/widgets/src/ChartWidget.php:103` allows it); any existing array options move into the same block. Every dataset — including `PriceHistoryChart`'s `Notified` marker dataset (`PriceHistoryChart.php:91-98`), which carries no currency today — gets a `currency` field with the ISO code. The tooltip `label` callback formats with the browser's own ICU:

```js
label: (ctx) => {
    const value = ctx.parsed.y;
    if (value === null || value === undefined) { return ctx.dataset.label; }
    const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: ctx.dataset.currency }).format(value);
    return `${ctx.dataset.label}: ${money}`;
}
```

`Intl.NumberFormat('en-US', currency)` applies the same CLDR *rules* as PHP intl's `en_US` currency style — grouping, minor units (`¥200`), negative placement (`-€1.69`), symbol fallback (`CHF 1.69`) — without a currency table in JS. The two ICU builds (PHP's and the browser's) are not guaranteed byte-identical (a symbol or spacing can differ between CLDR versions), and tooltips never enter a test assertion against server output; the browser check reads them by eye for EUR, JPY and CHF. If an exact match is ever required, ship server-formatted strings in the dataset instead. Dataset labels still use `MoneyFormatter::symbol()` server-side (`Cheapest (€)`). Browser assertions: hover a EUR point (`€1.69`), a JPY point on a test product (`¥200`), and a `Notified` marker (label plus the formatted price, never `undefined`).

### 2.4 Tests

- Unit (`tests/Unit/Support/MoneyFormatterTest.php`): `EUR → €1.69`, `USD → $1,234.56`, `GBP → £0.99`, `JPY → ¥200`, `CHF → CHF 1.69` (ASCII space after normalisation; asserts no U+00A0), `ZZZ → ZZZ 1.69` and `'' → 1.69` via the invalid-code path, null → `—`, non-numeric → `—`, `-1.69 → -€1.69`, `0 → €0.00`, `symbol('EUR') === '€'`, `symbol('CHF') === 'CHF'`, lowercase code accepted.
- Feature: the shops table, products table, infolist "Cheapest now", public share page (body and `og:description`), add-shop preview, active-drops widget (including the null case now rendering `—`, not `EUR —`), recent-notifications widget (absolute drop), stats widget (empty state `€0.00`; mixed currencies `Also: $30.00`), digest mail markdown, and the web-push body all contain `€1.69`-style strings and no `EUR 1.69` / `USD 30.00`. A **presentation-layer tripwire test** (not a guarantee — the per-surface feature tests above are the real coverage) scans only presentation code: `app/Filament/**`, `app/Livewire/**`, `app/Notifications/**`, `app/Mail/**`, `resources/views/**`. Two searchable patterns are prohibited there: `number_format(` and a currency token immediately followed by an amount in Blade (`{{ $x['currency'] }} {{`, `{{ $x->currency }} {{`, `->currency . ' '`). Each allowlist entry is keyed by **file + a source fragment** (not a line number) plus a one-line reason — for example `RecentNotificationsTableWidget.php` / `sprintf('-%0.1f%%'` / "percentage, not money"; `price-drop-digest.blade.php` / `drop_pct` / "percentage"; `CreateProductFromUrl.php` / `number_format((float) $` in the threshold defaults / "serialises decimals, not display" — so unrelated edits do not break it and a fragment that disappears fails the test (stale entry). Non-presentation code (`app/Actions`, `app/Console`, `app/Services`, `app/Support`) is out of scope — `SuggestShops.php:322`, `RefreshCheckjebonDatasetCommand.php:148`, `QueryTokens.php:90`, `PackSize.php:197` format numbers for matching or storage and must not go through `MoneyFormatter`. A new `number_format` in presentation code fails the suite naming the file; the implementer then either routes it through the formatter or extends the allowlist with a one-line reason.

## 3. Mobile

Symbol-first amounts are narrower than `EUR 1.69`, so no layout risk; the implementer still checks the shops table and the public share page at 390 px, where the shop row already stacks the unit price under the price.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Currency intl has no symbol for (e.g. `CHF`) | code + ASCII space + amount (`CHF 1.69`) after U+00A0 normalisation; unit test pins it |
| Code not in `Iso4217::CODES` (`ZZZ`) or empty | Invalid-code path: code (if any) + space + `number_format`; never `¤`; unit test pins it |
| Zero-decimal currency (`JPY`) | intl's minor units win: `¥200`; unit test pins it |
| Null price in the active-drops widget | `—` alone (today `EUR —`) |
| Non-numeric string reaches the formatter | `—`, no exception |
| Lowercase currency code from old data | Upper-cased before formatting |
| Mixed currencies in the savings chart | One dataset per currency; label shows each symbol (`€ saved`, `$ saved`) |
| Push body length | `€1.69` is shorter than `EUR 1.69`; no truncation concern |
| Negative amount | `-€1.69` (intl sign placement); unit test pins it |
| Zero amount | `€0.00`; unit test pins it (lifetime savings before any drop) |

## Implementation

- [ ] Rewrite `MoneyFormatter::format()` on `NumberFormatter` (en_US, CURRENCY, memoised) and add `symbol()` — Section 2.1.
- [ ] Route the eleven bypass sites through the formatter — Section 2.2 table.
- [ ] Chart tooltips/labels via `RawJs` + dataset-carried currency — Section 2.3; browser-verify EUR, JPY and the `Notified` marker tooltip.
- [ ] Tests — Section 2.4, including the presentation-layer tripwire test; update every existing assertion on money strings and currency labels: grep `tests/` for every code in `Iso4217::CODES` followed by a space (`EUR `, `USD 30.00` at `StatsOverviewWidgetTest.php:80`, …) **and** for bare-code labels (`Cheapest (EUR)` in `PriceHistoryChartTest.php:47`, dataset label `EUR` in `SavingsByMonthChartWidgetTest.php:84`, `RecentNotificationsTableWidgetTest`).
- [ ] Browser check at 390 px and 1280 px: shops table, products list, infolist, public share page, add-shop preview, dashboard widgets; light and dark.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **PHP `intl` is available in production (Laravel Cloud) as it is locally** — the whole design rests on `NumberFormatter`; without it, fall back to a hand-written symbol map and report.
2. **No currency in `Iso4217::CODES` has more than two minor units** — storage is `decimal(…, 2)`; if one is present, stop and widen storage before display.
3. **`RawJs` options render on both chart widgets without breaking their existing behaviour** (verified possible in the installed Filament: `getOptions(): array | RawJs | null`) — if a widget's current options cannot be expressed in the `RawJs` block, tooltips keep the default and only labels change; report the gap.

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
