# Unit Pricing (price per kg / per liter)

<!-- spec:planned-at a8331c4d00ff707621fd3f6132788cc28081d35c 2026-08-31 +uncommitted -->

## Overview

Shows a normalized unit price (€/kg or €/l) next to every shop's pack price, so different pack sizes compare honestly — a 200 g AH bag at the €1.69 bonus (€8.45/kg) against a 225 g Lidl bag on one product's shop list. Pack size is parsed from the size data each price source already carries; tracking, thresholds, and notifications stay on the pack price the buyer actually pays.

## Assumptions

- **Units: €/kg, €/l, and €/stuk** — confirmed by user (pieces explicitly wanted for toilet/kitchen paper). Precedence when a size names both: mass > volume > pieces (`"48+ plakken 150 g"` → mass).
- **Surfaces: all four** — confirmed by user: shops table, probe previews, public share page, and the products list (cheapest shop's unit price).
- **Title fallback for scraped shops** — confirmed by user. Unit-anchored matching only; a rare wrong size is visible and correctable.
- **Thresholds and notifications stay on the pack price** — carried from the accepted feature assessment; unit price is display-only.
- **A null parse on recheck keeps the existing pack columns** — AI default: a source hiccup must not wipe a known size. Cosmetic.
- **Unit price formatting** — AI default: two decimals, `€ 8.45 /kg`, `tabular-nums`, `—` when absent. Cosmetic.
- **Codex-review hardening, rounds 1+2 (2026-09-01)** — applied without re-grilling (all strictly-safer parser/update semantics): positive-quantity guard, alias vocabulary incl. written-out Dutch units, piece multipacks + `-pack`, ambiguity-parses-to-null for ranges/additive sizes, `plakken`/`+`-suffix exclusion, Livewire `pack_size` transport, structured-empty clears vs title-null keeps, URL-edit clears pack columns; snapshot authority flag (three size states), authoritative-unparseable clears, exact five-step parse algorithm with vel-drop for paper products, plain `unitPrice()` methods instead of magic accessors.

---

## 1. Size Data Per Source (verified 2026-08-31)

| Source | Size data | Example |
|---|---|---|
| AH mobile API | `productCard.salesUnitSize` (live) | `"200 g"` for wi526381 |
| checkjebon dataset (Lidl, AH fallback) | `checkjebon_prices.size` — already stored | `"150 g"`, `"30 g"`, `"0,75 l"`, sometimes `""` |
| Jumbo (direct scrape) | no structured field; the JSON-LD `name` usually carries it | `"HiPRO Protein Drink Mango 300ml"` |
| Dirk (direct scrape) | none — JSON-LD `name` usually lacks a size | `"Beemster Kaas extra belegen 48+ plakken"` |
| Generic scraped shops | occasionally in the title; otherwise none | — |

Consequence: AH and Lidl get reliable unit prices; scraped shops get them only when the title names a size; Dirk mostly shows none. A shop without a parseable size simply shows no unit price.

## 2. Pack-Size Parser

`App\Support\PackSize` — a small readonly value object + parser:

- `PackSize::parse(?string $text): ?PackSize` → `quantity` (float, normalized) + `unit` (`'g'` | `'ml'` | `'piece'`).
- **Quantity must be finite and > 0** — `"0 g"`, `"0 stuks"` parse to null (no division by zero). Piece counts must be whole numbers.
- Unit aliases, case-insensitive, attached forms allowed (`"500gram"`, `"330ML"`): mass `g|gr|gram|kg|kilo|kilogram`; volume `ml|milliliter|cl|centiliter|dl|deciliter|l|ltr|liter`. Normalization: kg → ×1000 g, l → ×1000 ml, dl → ×100 ml, cl → ×10 ml. Comma decimals accepted (`"0,75 l"`).
- Multipacks — for mass, volume, AND pieces: `"6 x 250 ml"` → 1500 ml; `"2 x 4 rollen"` → 8 pieces; `"3x10 stuks"` → 30 pieces; `"6 blikjes à 330 ml"` → 1980 ml. Separators `x`, `×`, or `à` (count before the separator for `à`: `<count> <optional word> à <size>`), optional spaces. A bare `"6-pack"` → 6 pieces, but `-pack` never outranks an accompanying mass/volume token: `"6-pack 330 ml"` → 1980 ml (the pack count multiplies the size); `-pack` alone resolves to pieces only when no mass/volume token exists. **Two or more multipack patterns in one string parse to null.**
- Piece vocabulary, singular + plural: `stuk|stuks|st|rol|rollen|vel|vellen|tablet|tabletten|zakje|zakjes|pack`. `plakken` is deliberately excluded — cheese names carry fat percentages (`"48+ plakken"`) that must never become 48 pieces. A number suffixed with `+` or `%` never starts a size token.
- **Exact algorithm** (replaces any looser precedence prose):
  1. Whole-string rejects first: a range (`"500-600 g"`, `"ca. 500–600 g"`) or an additive size (`"200 g + 150 g"`) parses to null.
  2. Exactly one multipack pattern (`<count> [x×à] <size-or-count>`, or `<count>-pack` — combined with a lone mass/volume token when present) resolves and wins; two or more multipack patterns → null.
  3. Otherwise collect all size tokens and bucket them: mass, volume, pieces. `vel|vellen` tokens are dropped whenever any other piece token is present (`"8 rollen à 200 vel"` → 8 rolls; sheets are per-roll detail); alone, sheets count.
  4. Pick the highest-precedence non-empty bucket: mass > volume > pieces (`"6 stuks 300 g"` → 300 g; `"48+ plakken 150 g"` → 150 g).
  5. The chosen bucket must hold exactly one distinct token — two distinct masses (outside step 2) parse to null. Tokens in lower buckets are ignored, never ambiguity.
- Title fallback: the same parser over the product title, same rules, so `"40% minder zout"` never matches.
- Unit price: mass/volume = `price / quantity × 1000` rendered `€ 8.45 /kg` / `€ 1.23 /l`; pieces = `price / count` rendered `€ 0.45 /stuk`. Two decimals, `tabular-nums`.

## 3. Data Model

Migration `add_pack_size_to_shops_table` (self-contained literals, appended columns):

- `pack_quantity` decimal(10,2) nullable — normalized grams, milliliters, or piece count
- `pack_unit` string nullable — `'g'` | `'ml'` | `'piece'`

`Shop` gains plain domain methods — not magic accessors — `unitPrice(): ?string` (null unless `current_price` + pack columns present) and `unitPriceLabel(): ?string` (`/kg`, `/l`, or `/stuk`). UI callers invoke them explicitly (Filament columns via a `state`/`getStateUsing` closure, Blade via `$shop->unitPrice()`), so no attribute-name magic can silently render blank.

## 4. Plumbing

- `ShopSnapshot` gains two fields: `public ?string $packSize = null` (the raw size string) and `public bool $packSizeAuthoritative = false` — true when a structured source supplied the size field at all (even empty), so the three states are representable: authoritative value, authoritative empty, and no structured source (title fallback allowed). Sources fill them:
  - `AhApiSource::snapshotFrom()` — `salesUnitSize`, authoritative **only when the key is present** (`array_key_exists`, not a nullable read): a partial API response with the field missing is non-authoritative, so it can never clear stored pack data (app/Services/AhApi/AhApiSource.php).
  - `CheckjebonSource::resolve()` — the `size` column, authoritative (app/Services/Checkjebon/CheckjebonSource.php).
  - Scraped adapters leave both defaults (not authoritative); persistence falls back to parsing the snapshot title.
- **Livewire transport**: `DrivesShopProbe::showPreview()` serializes the snapshot into an array (app/Livewire/Concerns/DrivesShopProbe.php:159) — add BOTH `pack_size` and `pack_size_authoritative`, or the confirm methods read nothing. The authority rules apply identically at confirm time: an authoritative snapshot never falls back to the title (an authoritative empty/unparseable size stores null pack columns); only non-authoritative snapshots title-parse. Test the probe-to-confirm round trip for both states.
- Persistence parses once and stores normalized values:
  - Probe confirm: `AddShop::confirm()` and `CreateProductFromUrl::confirm()` add `pack_quantity` / `pack_unit` to the `shops()->create()` payload (parse the transported `pack_size`; title fallback ONLY when `pack_size_authoritative` is false).
  - Recheck update semantics on `Ok` checks, driven by `packSizeAuthoritative`:
    - **Authoritative** snapshot → the parse result is written verbatim: a value overwrites, and an empty or unparseable authoritative size **clears** the pack columns (a stale wrong unit price is worse than none).
    - **Not authoritative** (title fallback) → only a successful parse writes; a null parse keeps existing values (a flaky title must not wipe a known size).
  - **URL edit**: `Shop::updateUrl()` (app/Models/Shop.php:61) can point a shop at a different product — clear both pack columns there so a stale size never prices the new product.
- `PriceCheck` stays untouched — unit price derives from the shop's current state; history stays pack-price-based.

## 5. UI

- Shops table on the product page (`ShopsRelationManager`, after the `current_price` column at app/Filament/App/Resources/Products/RelationManagers/ShopsRelationManager.php:72): a `Unit price` column — `€ 8.45 /kg`, empty state `—`.
- Products list (`ProductsTable`): the cheapest shop's unit price next to the cheapest price. Eager-load the cheapest-shop relation for the table query so the column adds no N+1.
- Probe previews (add-shop + create-from-URL blades): show the unit price under the pack price when parseable, so the comparison is visible before saving. The preview computes it on the fly from the snapshot via the same `packSize`-then-title parse used at persist time — one helper, both callers.
- Public share page shop list: unit price under each shop's price, same format. **`PublicProductController` selects shop columns explicitly** (app/Http/Controllers/PublicProductController.php:38) — add `pack_quantity` + `pack_unit` to that select, or the accessor silently renders nothing (the cheapest-badge bug class).
- Eye-verify: product with mixed pack sizes shows comparable /kg values; a Dirk shop without size shows `—`.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Size string empty or missing | No unit price; UI shows `—` (parser + UI Tests) |
| Piece-based size (`"20 stuks"`, `"4 rollen"`) | → `€/stuk`; a size naming both pieces and mass prefers mass (parser Tests) |
| Multipack (`"6 x 250 ml"`) | Total volume 1500 ml → correct €/l (parser Tests) |
| Comma decimal (`"0,75 l"`) | 750 ml (parser Tests) |
| Title contains misleading numbers (`"48+"`, `"40%"`) | Unit-anchored regex never matches them (parser Tests) |
| Title with several size tokens (`"6 x 33 cl krat 24"`, `"6 stuks 300 g"`) | Multipack wins; else bucket precedence, single-token bucket rule (parser Tests) |
| Recheck title-fallback parses null for a shop that has a size | Existing pack columns kept (plumbing Tests) |
| Structured source returns an explicitly empty size | Pack columns cleared (plumbing Tests) |
| AH response missing the `salesUnitSize` key entirely | Non-authoritative — stored pack data kept (plumbing Tests) |
| Shop URL manually edited to another product (`Shop::updateUrl()`) | Pack columns cleared until the next check refills them (plumbing Tests) |
| Zero quantity (`"0 g"`) or fractional piece count | Parses null — never divides by zero (parser Tests) |
| Range or additive size (`"500-600 g"`, `"200 g + 150 g"`) | Parses null — no plausible-but-wrong unit price (parser Tests) |
| Paper products (`"8 rollen à 200 vel"`) | 8 pieces — `vel(len)` dropped when another piece token exists; `"200 vellen"` alone counts (parser Tests) |
| Piece multipack (`"2 x 4 rollen"`, `"6-pack"`) | 8 pieces / 6 pieces (parser Tests) |
| `à` multipack (`"6 blikjes à 330 ml"`) | 1980 ml (parser Tests) |
| `-pack` next to a size (`"6-pack 330 ml"`) | 1980 ml — pack count multiplies the size, never beats it (parser Tests) |
| Two multipack patterns (`"2 x 100 g en 2 x 50 g"`) | Parses null (parser Tests) |
| Cheese fat marker (`"48+ plakken"` alone) | Parses null — `plakken` excluded, `+`-suffixed numbers never match (parser Tests) |
| Packaging changes upstream (200 g → 250 g) | Next `Ok` recheck overwrites the pack columns (plumbing Tests) |
| Mixed units on one product (`/kg` vs `/l` vs `/stuk`) | Each row shows its own unit; no cross-unit comparison implied (UI Tests) |
| Existing shops predate the feature | Columns null until their next successful recheck fills them (plumbing Tests) |
| Public page's explicit column select | `pack_quantity`/`pack_unit` added to the select; page test asserts a rendered unit price (UI Tests) |

## Implementation

### Phase 1: Pack-size parser (Priority: HIGH)

**ID:** parser · **Depends:** none

- [x] `App\Support\PackSize` value object + `parse()` — Section 2's five-step algorithm incl. multipacks (`x`/`×`/`à`/`-pack`), comma decimals, unit anchoring.
- [x] Unit-price math + label helper (`/kg`, `/l`, `/stuk`) on the value object — price string in, formatted unit price out.
- [x] Tests — every Section 2 example incl. multipacks, comma decimals, piece vocabulary, the exact five-step algorithm (buckets, vel-drop, single-token rule), and the misleading-number rows.

### Phase 2: Columns + source plumbing (Priority: HIGH)

**ID:** plumbing · **Depends:** parser

- [x] Migration `add_pack_size_to_shops_table` + `Shop::unitPrice()` / `unitPriceLabel()` methods + docblock — Section 3.
- [x] `ShopSnapshot::$packSize` + `$packSizeAuthoritative` + fills in `AhApiSource` / `CheckjebonSource` + both keys in `DrivesShopProbe::showPreview()`'s snapshot array — Section 4.
- [x] Persist on probe confirm (both Livewire components) with title fallback — Section 4.
- [x] Recheck outcomes carry pack fields + authority flag; `persist()` applies the Section 4 semantics (authoritative writes verbatim incl. clears; fallback only fills); `Shop::updateUrl()` clears both columns — Section 4.
- [x] Tests — AH probe stores 200 g from `salesUnitSize`; checkjebon probe stores the dataset size; Jumbo recheck parses the title; probe-to-confirm round trip persists `pack_size`; title-null keeps values; structured-empty and structured-unparseable clear; URL edit clears; packaging change overwrites.

### Phase 3: UI (Priority: HIGH)

**ID:** ui · **Depends:** plumbing

- [x] Shops-table `Unit price` column + products-list cheapest unit price — Section 5.
- [x] Probe-preview unit price in both blades — Section 5.
- [x] Public share page unit price per shop row incl. the controller's explicit column select — Section 5.
- [x] Tests — Livewire/page tests assert the rendered unit price and the `—` empty state; browser eye-verify per Section 5.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`salesUnitSize` is present on AH detail responses** — verified live once; if the field is missing or shaped differently in practice, the AH fill needs a new field mapping before Phase 2 ships.
2. **`checkjebon_prices.size` strings stay in the observed `"150 g"` vocabulary** — if the dataset uses other formats at scale, extend the parser first; silent nulls across Lidl would gut the feature.

---

## Open Questions

None.

---

## Resolved Questions

1. **Which units get a unit price?** **Decision:** €/kg, €/l, and €/stuk. **Rationale:** toilet paper and kitchen paper are core tracked products; piece pricing is the only meaningful comparison there.
2. **Which surfaces show it?** **Decision:** shops table, probe previews, public share page, and the products list. **Rationale:** comparison value everywhere a price already shows.
3. **Title fallback for scraped shops?** **Decision:** Yes, with unit-anchored matching. **Rationale:** coverage on Jumbo/Dirk/generic beats the rare visible-and-correctable wrong size.

## Findings

- **Phase 3.** Previews call the protected `DrivesShopProbe::snapshotPackSize()` from Blade (valid: Livewire binds the view closure to the component scope — verified against Livewire source). Products list eager-loads `cheapestShop` via `modifyQueryUsing`. Filament column-state closure left without its own render test; math covered by Phase 1/2, wiring mirrors the `current_price` column. Browser eye-verify deferred to the orchestrator.
- **Phase 2.** `PackSize` gained two additive helpers: `of(float, string)` (rebuild from stored columns) and `resolve(?string, bool, ?string)` — the single implementation of the authority/title-fallback rule shared by the Livewire trait, the recheck job, and the Phase 3 preview. `ahApiProductFakes()` expresses key-absence by omitting `salesUnitSize` when passed null. A checkjebon row with a null/empty `size` is authoritative and clears pack columns on recheck, per Section 4.
- **Phase 1 (vel-drop ordering).** Spec step 2 vs step 3 contradict for `"8 rollen à 200 vel"` (à-multipack syntax vs the stated 8-pieces result). Resolved in favour of the spec's worked examples: when any non-`vel` piece token exists, `<number> vel/vellen` occurrences are stripped BEFORE multipack detection, not only at bucket collection. `"200 vellen"` alone still counts. 42 parser tests green.
