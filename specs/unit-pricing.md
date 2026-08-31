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
- Normalization: `kg` → ×1000 g, `l` → ×1000 ml, `cl` → ×10 ml. Comma decimals accepted (`"0,75 l"`).
- Multipacks: `"6 x 250 ml"` → 1500 ml; `"24 x 300 ml"` → 7200 ml. `x` or `×`, optional spaces.
- Pieces: `"20 stuks"`, `"3 st."`, `"4 rollen"`, `"12 zakjes"`, `"8 tabletten"` → piece count. Precedence mass > volume > pieces, so `"48+ plakken 150 g"` is 150 g. Bare unitless numbers (`"48+"`) parse to null.
- Title fallback: the same parser runs over a product title; it must anchor on `\d+(?:[.,]\d+)?\s?(g|kg|ml|cl|l)\b` tokens so `"48+ plakken"` or `"40% minder zout"` never match. When a title contains several size tokens, take the last (Dutch product names end with the pack size).
- Unit price: mass/volume = `price / quantity × 1000` rendered `€ 8.45 /kg` / `€ 1.23 /l`; pieces = `price / count` rendered `€ 0.45 /stuk`. Two decimals, `tabular-nums`.

## 3. Data Model

Migration `add_pack_size_to_shops_table` (self-contained literals, appended columns):

- `pack_quantity` decimal(10,2) nullable — normalized grams, milliliters, or piece count
- `pack_unit` string nullable — `'g'` | `'ml'` | `'piece'`

`Shop` gains a computed accessor `unit_price(): ?string` (null unless `current_price` + pack columns present) plus a `unit_price_label` (`/kg`, `/l`, or `/stuk`). Docblock properties per the model conventions.

## 4. Plumbing

- `ShopSnapshot` gains `public ?string $packSize = null` (the raw size string). Sources fill it:
  - `AhApiSource::snapshotFrom()` — `salesUnitSize` (app/Services/AhApi/AhApiSource.php).
  - `CheckjebonSource::resolve()` — the `size` column (app/Services/Checkjebon/CheckjebonSource.php).
  - Scraped adapters leave it null; persistence falls back to parsing the snapshot title.
- Persistence parses once and stores normalized values:
  - Probe confirm: `AddShop::confirm()` and `CreateProductFromUrl::confirm()` add `pack_quantity` / `pack_unit` to the `shops()->create()` payload (parse `snapshot.packSize`, falling back to the title).
  - Recheck: `CheckShopPrice` outcomes carry the parsed pack fields; `persist()` updates them on `Ok` checks so a packaging change heals itself. A null parse on recheck leaves existing values untouched (a source hiccup must not wipe a known size).
- `PriceCheck` stays untouched — unit price derives from the shop's current state; history stays pack-price-based.

## 5. UI

- Shops table on the product page (`ShopsRelationManager`, after the `current_price` column at app/Filament/App/Resources/Products/RelationManagers/ShopsRelationManager.php:72): a `Unit price` column — `€ 8.45 /kg`, empty state `—`.
- Products list (`ProductsTable`): the cheapest shop's unit price next to the cheapest price.
- Probe previews (add-shop + create-from-URL blades): show the unit price under the pack price when parseable, so the comparison is visible before saving.
- Public share page shop list: unit price under each shop's price, same format.
- Eye-verify: product with mixed pack sizes shows comparable /kg values; a Dirk shop without size shows `—`.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Size string empty or missing | No unit price; UI shows `—` (parser + UI Tests) |
| Piece-based size (`"20 stuks"`, `"4 rollen"`) | → `€/stuk`; a size naming both pieces and mass prefers mass (parser Tests) |
| Multipack (`"6 x 250 ml"`) | Total volume 1500 ml → correct €/l (parser Tests) |
| Comma decimal (`"0,75 l"`) | 750 ml (parser Tests) |
| Title contains misleading numbers (`"48+"`, `"40%"`) | Unit-anchored regex never matches them (parser Tests) |
| Title with several size tokens (`"6 x 33 cl krat 24"`) | Multipack pattern wins; otherwise last size token (parser Tests) |
| Recheck returns no parseable size for a shop that has one | Existing pack columns kept (plumbing Tests) |
| Packaging changes upstream (200 g → 250 g) | Next `Ok` recheck overwrites the pack columns (plumbing Tests) |
| Mixed units on one product (`/kg` vs `/l` vs `/stuk`) | Each row shows its own unit; no cross-unit comparison implied (UI Tests) |
| Existing shops predate the feature | Columns null until their next successful recheck fills them (plumbing Tests) |

## Implementation

### Phase 1: Pack-size parser (Priority: HIGH)

**ID:** parser · **Depends:** none

- [ ] `App\Support\PackSize` value object + `parse()` — Section 2 rules incl. multipacks, comma decimals, unit anchoring, last-token pick.
- [ ] Unit-price math + label helper (`/kg`, `/l`, `/stuk`) on the value object — price string in, formatted unit price out.
- [ ] Tests — every Section 2 example incl. multipacks, comma decimals, piece vocabulary, precedence, and the misleading-number rows.

### Phase 2: Columns + source plumbing (Priority: HIGH)

**ID:** plumbing · **Depends:** parser

- [ ] Migration `add_pack_size_to_shops_table` + `Shop` accessor + docblock — Section 3.
- [ ] `ShopSnapshot::$packSize` + fills in `AhApiSource` and `CheckjebonSource` — Section 4.
- [ ] Persist on probe confirm (both Livewire components) with title fallback — Section 4.
- [ ] Recheck outcomes carry pack fields; `persist()` updates on `Ok`, keeps existing on null — Section 4.
- [ ] Tests — AH probe stores 200 g from `salesUnitSize`; checkjebon probe stores the dataset size; Jumbo recheck parses the title; null-parse keeps existing values; packaging change overwrites.

### Phase 3: UI (Priority: HIGH)

**ID:** ui · **Depends:** plumbing

- [ ] Shops-table `Unit price` column + products-list cheapest unit price — Section 5.
- [ ] Probe-preview unit price in both blades — Section 5.
- [ ] Public share page unit price per shop row — Section 5.
- [ ] Tests — Livewire/page tests assert the rendered unit price and the `—` empty state; browser eye-verify per Section 5.

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

<!-- Notes added during implementation. Do not remove this section. -->
