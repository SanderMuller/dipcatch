# Shop Suggestions

<!-- spec:planned-at 01b55bf9cf71b0b1583196f6d1071a83c1b40c79 2026-09-01 +uncommitted -->

## Overview

Suggests other shops that sell the product a user already tracks, so a one-shop product grows into a real price comparison without hunting for URLs. Suggestions come from the checkjebon dataset the app already downloads daily — twelve supermarket chains, about 107k products — matched on name and pack size. Accepting a suggestion runs the normal probe, so the stored price stays live-scraped, never dataset-derived. A second, smaller part captures the GTIN each shop page publishes and warns when tracked offers turn out to be different articles.

## Assumptions

- **Scope: dataset suggestions plus GTIN capture** — confirmed by user. Search-API discovery for non-grocery products (Feliway-class) is explicitly out; see `## Open Questions`.
- **All twelve chains are imported** — confirmed by user. Aldi and Ekoplaza ship zero rows upstream today, so ten chains carry data.
- **Both surfaces get suggestions** — confirmed by user: a panel on the product view page and a list inside the existing add-shop disclosure.
- **Accepting a suggestion probes first** — confirmed by user. The suggestion supplies the URL; `ProbeShopUrl` supplies title, price, currency and stock; the user confirms before the shop is stored.
- **Chains the probe cannot price are shown, marked "not trackable yet"** — confirmed by user. Verified today: Plus, DekaMarkt, Vomar and Poiesz return `no_adapter_matched`; Hoogvliet parses with the generic adapter but returns a wrong price (22.33 for a 3.09 cheese). Their add button is disabled.
- **Match threshold 0.55, best row per chain** — confirmed by user, from the measured scores in `## 2`.
- **Dismissed suggestions are remembered per product** — confirmed by user. Needs a small table.
- **A GTIN mismatch warns, and changes nothing else** — confirmed by user. Cheapest-price computation, thresholds and alerts stay exactly as they are.
- **Suggestions are computed on request, not stored** — AI default. A `LIKE` prefilter over 45,681 rows takes 14 ms on this SQLite database; at 107k rows that is roughly 35 ms per product page. No cache, no scheduled precompute.
- **A chain already tracked on the product is never suggested** — AI default, matched on `shops.host`. A user tracking two Jumbo variants deliberately will not see a third suggested.
- **Suggestion prices are labelled as dataset prices** — AI default. The dataset holds regular prices only: Dirk reads 3.29 there against 1.69 live on promotion, so an unlabelled number would read as a lie.
- **Lidl rows stay in the dataset but rarely match** — AI default, no special-casing. The boodschaapje names are unusable for matching (the best Beemster match was a vacuum-cleaner filter bag at 0.40), and the threshold already rejects them.

---

## 1. Dataset (verified 2026-09-01)

`RefreshCheckjebonDatasetCommand` already downloads `supermarkt/checkjebon`'s `supermarkets.json` daily (`app/Console/Commands/RefreshCheckjebonDatasetCommand.php:19`) but imports only two chains (`SUPERMARKETS = ['ah', 'lidl']`, line 30). The file carries twelve:

| Chain key | Display (`c`) | Rows | Base URL (`u`) | Link (`l`) shape |
|---|---|---|---|---|
| ah | AH | 16,173 | `https://www.ah.nl/producten/product/` | `wi409012/beemster-belegen-30-plakken` |
| jumbo | Jumbo | 17,217 | `https://www.jumbo.com/producten/` | `beemster-30-belegen-plakken-150-g-729228ZK` |
| lidl | Lidl (via boodschaapje.nl) | 22,070 | `https://boodschaapje.nl/product/` | `8128671` |
| plus | PLUS | 16,129 | `https://www.plus.nl/product/` | `beemster-belegen-48-plakken-kg-200-g-365529` |
| dekamarkt | DekaMarkt | 10,728 | `https://www.dekamarkt.nl/boodschappen/x/x/x/` | `444852` |
| spar | SPAR | 7,711 | `https://www.spar.nl/` | `beemster-…-9258359/` |
| dirk | Dirk | 7,438 | `https://www.dirk.nl/boodschappen/x/x/x/` | `115217` |
| hoogvliet | Hoogvliet | 7,410 | `https://www.hoogvliet.com/product/` | `beemster-belegen-30-plakken` |
| poiesz | Poiesz | 1,730 | `https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/` | `589247` |
| vomar | Vomar | 887 | `https://www.vomar.nl/producten/` | `bier-wijn-sterke-drank/x/x/304577` |
| aldi | ALDI | 0 | `https://www.aldi.nl/producten/` | — |
| ekoplaza | Ekoplaza | 0 | `https://www.ekoplaza.nl/producten/product/` | — |

Each row is `{n: name, l: link, p: price, s: size}`. **Product URL is `u . l`** — every one of the eight constructed URLs above returned HTTP 200 when fetched today, including the `x/x/x` placeholder paths.

`checkjebon_prices` needs one new column: `link`, the raw `l` value, so a suggestion can build its URL from the row alone. Base URL and display name are per chain, not per row, so they belong in a chain map the import refreshes from the dataset's own `u` and `c` fields.

**Existing behaviour to preserve:** `externalIdFromLink()` (line 164) derives the AH `wi…` id and the bare-numeric Lidl id, and `CheckjebonSource::HOSTS` (`app/Services/Checkjebon/CheckjebonSource.php:23`) maps `ah.nl` and `boodschaapje.nl` to their dataset keys for *pricing*. Importing ten chains must not extend the pricing path: only `ah` and `lidl` rows may be read by `CheckjebonSource`. Rows for the other chains exist for matching only.

**Staleness reporting has a gap once suggestions exist.** `CheckjebonFreshnessCheck` reports `idle` unless an active shop uses `adapter_key = 'checkjebon'` (`app/Health/CheckjebonFreshnessCheck.php:38-46`). After this change the dataset also feeds suggestions for users with no dataset-priced shop at all, so a stale or empty dataset would silently degrade suggestions with the health check reporting `idle`. The in-use condition widens to "any active checkjebon shop **or** any product that could receive suggestions".

The `dirk` rows already in the local database are leftovers from before Dirk moved to direct scraping; the prune step only touches listed chains. After this change they are imported deliberately.

## 2. Matching (measured 2026-09-01)

Normalize both sides to a token set: lowercase, strip everything but `a-z0-9+`, split on whitespace, union the name tokens with the size tokens. Score with Jaccard overlap. Measured against the tracked product "Beemster Extra belegen 48+ plakken" (150 g):

```
ah         1.00  Beemster Extra belegen 48+ plakken       150 g     3.49
dekamarkt  0.88  Beemster Kaas extra belegen 48+ plakken  150 g     3.39
dirk       0.88  Beemster Kaas extra belegen 48+ plakken  150 g     3.29
jumbo      0.86  Beemster Extra Belegen Plakken 150 g               3.49
hoogvliet  0.75  Beemster Extra Belegen 48+ Plakken       150 gram  3.29
plus       0.75  Beemster Extra Belegen plakken           Per 150   3.39
spar       0.56  Beemster kaas plakken belegen 48+        150 Gram  3.69
lidl       0.40  Goudse kaas extra belegen plakken        250 g     3.14   ← reject
poiesz     0.27  Uniekaas Goudse kaasplakken 48+ belegen  150 Gram  2.69   ← reject
vomar      0.10  G'woon aardappelkroketjes 750g           750G      1.69   ← reject
```

Rules:

- **Query text** = the product title plus, when the product has a pack size, its normalized size tokens. Pack size comes from the unit-pricing work (`specs/unit-pricing.md`), which normalizes "Per 150", "150 gram" and "150 g" to the same value — use it when present, fall back to raw title tokens when not.
- **Prefilter in SQL** on the longest name token (`name LIKE %beemster%`), then score in PHP. Measured: 47 rows out of 45,681 in 14 ms.
- **Threshold 0.55**, best-scoring row per chain, chains already tracked on the product excluded, dismissed pairs excluded.
- **Tracked-chain exclusion needs a chain → host map.** The dataset key is not the host: `lidl` serves `boodschaapje.nl`, `poiesz` serves `webwinkel.poiesz-supermarkten.nl`. Derive the host from the chain's base URL through `UrlNormalizer::normalizeHost()`, the same normalization `Shop::booted()` applies when it stores `shops.host`, so `www.ah.nl` and `ah.nl` compare equal.
- **Order** by score descending, then chain name, so the list is stable between renders.

## 3. Trackability

A suggestion is *trackable* when the app can price that chain's URL today. Verified by running the adapter chain against a constructed URL per chain on 2026-09-01:

| Chain | Result | Trackable |
|---|---|---|
| ah | AH mobile API (live, bonus-aware) | yes |
| lidl | checkjebon dataset path | yes |
| dirk | direct scrape, JSON-LD | yes |
| jumbo | direct scrape, JSON-LD | yes |
| spar | JSON-LD, 3.39 correct | yes |
| hoogvliet | generic adapter returns **22.33** for a 3.09 product | **no** |
| plus, dekamarkt, vomar, poiesz | `no_adapter_matched` | no |

Trackability is a static map keyed by chain, not a probe at render time — a per-suggestion probe would burn the 6/min per-user probe budget in `ProbeShopUrl` (`app/Actions/Shops/ProbeShopUrl.php:46`) before the user clicked anything. Hoogvliet is listed as untrackable *despite* parsing, because a confidently wrong price is worse than no price.

Untrackable suggestions render with a muted "not trackable yet" label, the dataset price, a link that opens the shop page in a new tab, and a disabled add button.

## 4. Accepting a Suggestion

Accepting hands the constructed URL to the existing add-shop flow — no new persistence path:

1. The suggestion component dispatches the URL into `AddShop` (`app/Livewire/Shops/AddShop.php:17`), which already owns probe → preview → confirm.
2. `ProbeShopUrl` normalizes, dedupes against the product's shops, enforces the per-user rate limit, resolves the adapter and checks the currency.
3. The user sees the live price, title and image, then confirms. `AddShop::confirm()` stores the shop and the first `PriceCheck`, and recomputes the cheapest offer.

A probe failure surfaces with the existing error copy. The suggestion stays in the list; it is not auto-dismissed, since the failure is usually transient (rate limit, temporary block).

## 5. Dismissals

New table `shop_suggestion_dismissals`: `product_id`, `chain` (dataset key), `external_id`, `dismissed_at`. Unique on `(product_id, chain, external_id)`. Dismissing hides that specific dataset row for that product; a later dataset refresh that replaces the row with a new `external_id` surfaces it again, which is the intended behaviour — the catalogue changed.

Deleting a product cascades. No UI to undo a dismissal in this spec; see `## Open Questions`.

## 6. GTIN Capture

All three shops of the Feliway product publish a GTIN, and all three differ — verified 2026-09-01:

| Shop | Where | Value |
|---|---|---|
| dierapotheker.nl | microdata `<meta itemprop="gtin13">` | 3411112291649 |
| pharmacy4pets.nl | JSON-LD `gtin` | 3411112987955 |
| zooplus.nl | JSON-LD per variant; tracked variant `169589.19` | 3411113099565 |

That spread, with prices 51.90 / 57.72 / 27.99, is the signal worth surfacing: these are probably not the same article. AH's mobile API publishes **no** GTIN (`detail/v4` returns `webshopId` and `hqId` only) and Dirk's page carries none, so capture is best-effort per shop.

- `ShopSnapshot` gains `?string $gtin` alongside the existing `imageUrl` / `packSize` fields (`app/PriceAdapters/ShopSnapshot.php:17-29`).
- `JsonLdAdapter` and `MicrodataAdapter` read it; `JsonLdEntitySearcher::KEY_FIELDS` already names `gtin13` / `gtin` for variant matching (`app/PriceAdapters/JsonLdEntitySearcher.php:15`), so the field vocabulary is settled: `gtin13`, `gtin`, `gtin14`, `gtin8`, in that order. Digits only, 8/12/13/14 length, otherwise null.
- `shops.gtin` is written on probe and on every successful check, cleared by `Shop::updateUrl()` like the other URL-blind hints.
- The shops table shows one warning line when the product's shops report two or more distinct non-null GTINs. Nothing else changes: `recomputeCheapestShop()` is untouched.

## Edge Cases

| Scenario | Handling |
|---|---|
| Product has no shop yet (manual product) | Match on the product title alone; suggestions still render. Covered by the matching phase Tests. |
| Every chain is already tracked | Panel renders the empty state "No other shops found", not an empty box. Covered by the view-surface Tests. |
| Dataset is empty or stale (refresh failed) | Panel hides itself. Reporting comes from the widened `CheckjebonFreshnessCheck` — its current condition reports `idle` when no shop uses the dataset for pricing. Covered by the matching phase and dataset phase Tests. |
| Two shops of one chain match equally well | Best score wins; ties break on `external_id` so the render is stable. Covered by the matching phase Tests. |
| Suggested URL 404s or the shop blocks the probe | Existing probe error copy; the suggestion stays listed. Covered by the accept-flow Tests. |
| Suggested URL is already tracked under a different path | `ProbeShopUrl` returns its duplicate outcome and the existing "already tracked" message shows. Covered by the accept-flow Tests. |
| Product currency is not EUR | No suggestions — the dataset is EUR-only. Covered by the matching phase Tests. |
| Dataset row disappears after a refresh, dismissal remains | Orphan dismissal rows are harmless and pruned by nothing; they are keyed on `external_id`, so they simply never match again. |
| Shop publishes a malformed GTIN (letters, wrong length) | Stored as null. Covered by the GTIN phase Tests. |
| Only one shop reports a GTIN | No warning — a warning needs two distinct non-null values. Covered by the GTIN phase Tests. |
| Two users track the same product | Dismissals are per product, and products are per user, so no cross-user leakage. Covered by the dismissal Tests. |
| A user addresses the suggestions component with another user's product id | The component authorizes the product on mount through `ProductPolicy`, the same guard `ProductResource::getEloquentQuery()` relies on. A Livewire component is publicly addressable, so the panel cannot inherit the page's scoping. Covered by the suggestions-component Tests. |
| A user accepts several suggestions in a row | The seventh probe inside a minute hits the existing 6/min per-user budget in `ProbeShopUrl` and returns its rate-limit message. The suggestion stays listed and can be retried. Covered by the accept-flow Tests. |
| Shop reports a GTIN during the probe preview, before the shop row exists | The value travels in the Livewire preview snapshot alongside `image_url`, the same transport `DrivesShopProbe` already uses, and is written by `confirm()`. Covered by the GTIN phase Tests. |

## Implementation

### Phase 1: Import every chain (Priority: HIGH)

**ID:** dataset · **Depends:** none

- [ ] Extend `SUPERMARKETS` to the ten chains that carry rows, keeping `aldi` / `ekoplaza` out until upstream fills them — `RefreshCheckjebonDatasetCommand.php:30`.
- [ ] Add `link` to `checkjebon_prices` (nullable text, appended) and store the raw `l` value per row, so a suggestion can build its URL without re-fetching the dataset.
- [ ] Import the per-chain `u` (base URL) and `c` (display name) into a chain map the app can read at render time; refresh it on every import so an upstream base-URL change follows automatically.
- [ ] Keep `externalIdFromLink()` correct for the new chains — slug chains keep the full slug as their id, numeric chains the number; the unique key stays `(supermarket, external_id)`.
- [ ] Confirm `CheckjebonSource` still resolves prices for `ah` and `lidl` only, so importing eight more chains changes no pricing behaviour.
- [ ] Widen `CheckjebonFreshnessCheck`'s in-use condition so a stale or empty dataset is reported even when no shop is priced from it — suggestions now depend on the same rows.
- [ ] Tests — import fixture with all twelve chains; asserts row counts per chain, `link` stored, chain map populated, empty chains skipped without pruning existing rows, `CheckjebonSource::supports()` unchanged, and the freshness check reporting an empty dataset when no shop uses the pricing path.

### Phase 2: Matching service (Priority: HIGH)

**ID:** matching · **Depends:** dataset

- [ ] Add `SuggestShops` action: normalize the query text, prefilter with `LIKE` on the longest token, score with Jaccard, drop below 0.55, keep the best row per chain.
- [ ] Exclude chains already tracked on the product — compare `shops.host` against the chain host derived from the base URL through `UrlNormalizer::normalizeHost()` — and exclude rows dismissed for that product.
- [ ] Return a typed suggestion object per row: chain key, display name, product name, size, dataset price, constructed URL, score, trackable flag.
- [ ] Add the trackable chain map (`ah`, `lidl`, `dirk`, `jumbo`, `spar`), with the Hoogvliet exclusion documented in the code.
- [ ] Add `shop_suggestion_dismissals` (migration + model + cascade on product delete) and the dismiss/undismiss methods the UI calls.
- [ ] Return nothing when the product currency is not EUR, or when the dataset holds no rows.
- [ ] Tests — scoring fixtures reproducing the Beemster table (seven accepts, three rejects), tracked-chain exclusion, dismissal exclusion, tie-break stability, empty dataset, non-EUR product, and a query built from title only.

### Phase 3: Suggestions component (Priority: HIGH)

**ID:** suggestions-ui · **Depends:** matching

- [ ] Authorize the product on mount through `ProductPolicy` — the component takes a product id and is publicly addressable, so it cannot rely on the page's ownership scoping.
- [ ] Add a `ShopSuggestions` Livewire component that renders the list for a product: image-free row with chain name, product name, size, dataset price labelled as a dataset price, score not shown.
- [ ] Trackable row: an "Add" button that dispatches the URL into `AddShop`, which then runs its normal probe → preview → confirm.
- [ ] Untrackable row: muted "not trackable yet" label, an external link to the shop page, disabled add button.
- [ ] Dismiss control per row, writing through the action from phase 2.
- [ ] Empty state: "No other shops found" when the query returns nothing; the component hides entirely when the dataset is empty.
- [ ] Tests — render with mixed trackable and untrackable rows, add dispatches the expected URL, dismiss removes the row and persists, empty state, hidden state, and a forbidden response for another user's product.

### Phase 4: Product view surface (Priority: HIGH)

**ID:** ui-view · **Depends:** suggestions-ui

- [ ] Mount `ShopSuggestions` in a panel above the shops table on the product view page — the header slot already hosts the add-shop disclosure (`ShopsRelationManager.php:64`, `resources/views/filament/partials/add-shop-header.blade.php`).
- [ ] Refresh the panel when a shop is added or removed, so an accepted suggestion disappears from the list.
- [ ] Eye-verify in a browser: panel renders, add opens the probe preview, dismiss removes the row, empty state on a product whose chains are all tracked.
- [ ] Tests — the panel renders on the view page for a product with matches, and disappears for a product with none.

### Phase 5: Add-shop surface (Priority: HIGH)

**ID:** ui-add-shop · **Depends:** suggestions-ui

- [ ] Mount `ShopSuggestions` inside the add-shop disclosure next to the URL field (`resources/views/livewire/shops/add-shop.blade.php`), so a user who opened it to paste a URL sees the alternatives first.
- [ ] Hide the list once the component is in preview or confirm state, so the suggestion list does not compete with the probe result.
- [ ] Eye-verify in a browser: list shows on open, accepting fills the probe preview, list hides during preview and returns after reset.
- [ ] Tests — suggestions render inside the add-shop component and disappear in preview state.

### Phase 6: GTIN capture and mismatch warning (Priority: MEDIUM)

**ID:** gtin · **Depends:** ui-view

- [ ] Add `?string $gtin` to `ShopSnapshot`; read `gtin13` / `gtin` / `gtin14` / `gtin8` in `JsonLdAdapter` and `MicrodataAdapter`, digits-only with an 8/12/13/14 length check.
- [ ] Carry the value through the Livewire preview snapshot in `DrivesShopProbe`, the transport `image_url` already uses, so `AddShop::confirm()` and `CreateProductFromUrl::confirm()` can persist it.
- [ ] Add `shops.gtin` (nullable string, appended); write it on probe confirm and on every successful check; clear it in `Shop::updateUrl()` next to the other URL-blind hints.
- [ ] Show one warning line above the shops table when the product's shops report two or more distinct non-null GTINs, naming the differing hosts.
- [ ] Leave `recomputeCheapestShop()`, thresholds and notifications untouched.
- [ ] Tests — JSON-LD and microdata extraction including the malformed cases, persistence on probe and on recheck, clearing on URL change, warning shown for two distinct GTINs and hidden for one or none.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The dataset keeps its per-chain `u` / `c` / `l` fields** — the whole URL-construction design rests on `u . l`. If upstream drops or renames them, every non-AH/Lidl suggestion loses its link and the phase needs a different source.
2. **Importing eight extra chains does not touch pricing** — if `CheckjebonSource` starts resolving prices for a newly imported chain, shops would silently switch from live scraping to daily dataset prices. Stop; the chain map must stay pricing-blind.
3. **Matching stays inside a page-render budget** — measured 14 ms at 45,681 rows. If the ten-chain table pushes a suggestion query past roughly 150 ms, stop and move to a cached or precomputed design rather than shipping a slow product page.
4. **`ProductPolicy` authorizes a product for its owner** — the suggestions component depends on it for its only access check. If the policy does not cover the `view` ability for a product, stop and add the check explicitly rather than relying on page scoping.
5. **`ShopSnapshot` is extended, not restructured** — the unit-pricing work adds `packSize` / `packSizeAuthoritative` to the same constructor. If that work has changed the class shape by the time phase 6 starts, re-read it before adding `gtin`.

---

## Open Questions

1. **Should untrackable chains become trackable through the dataset?** Lidl is already priced from the dataset, so Plus, DekaMarkt, Vomar and Poiesz could follow the same path. It buys four chains at the cost of daily regular prices with no bonus detection. Deliberately out of scope here; worth its own spec.
2. **Non-grocery products get nothing from this feature.** Research on the Feliway product showed that EAN search fails outside food retail — bol.com returns "0 resultaten" for an EAN it stocks by name, and beslist / kieskeurig / idealo are bot-walled or JS-only. A search-API candidate discovery (query = brand + model + size, then probe each candidate) is the only route found, and it needs an API key, a per-product budget and a confirm step. Separate spec.
3. **Can a dismissal be undone?** No undo UI is specified. If a user dismisses by accident the suggestion is gone until the dataset row changes.
4. **Should the panel show a price delta?** The dataset price is regular-price only, so "€0.20 cheaper" could be wrong on a promotion day. Currently the raw dataset price is shown, labelled. A delta needs a product decision.

---

## Resolved Questions

1. **Which scope should the spec cover?** **Decision:** Dataset suggestions plus GTIN capture; no search-API discovery. **Rationale:** The dataset is already downloaded daily and covers the grocery case completely. Search-API discovery needs a key, a budget and a confirm loop — a different feature.
2. **Where do suggestions appear?** **Decision:** Both the product view page and the add-shop disclosure. **Rationale:** The view page is where shops are managed; the disclosure is where a user goes when they intend to add one.
3. **What happens when a suggestion is accepted?** **Decision:** Probe first, then confirm. **Rationale:** The dataset holds regular prices only, so storing one would put a wrong price on the product. The probe already owns dedupe, rate limiting and currency checks.
4. **Which chains does the import cover?** **Decision:** All chains that carry rows upstream — ten today. **Rationale:** Discovery value comes from breadth; trackability is a separate, honest label.
5. **Are suggestions shown for chains the app cannot price?** **Decision:** Yes, marked "not trackable yet", with the add button disabled. **Rationale:** The comparison is useful even when tracking is not possible yet, and hiding them would hide the reason four chains are missing.
6. **What match threshold?** **Decision:** 0.55, best row per chain. **Rationale:** It accepts all seven correct chains on the measured product, including the loosely-worded Spar entry, while the wrong products score 0.40 and below.
7. **Do dismissed suggestions stay dismissed?** **Decision:** Yes, per product. **Rationale:** A recomputed list would re-offer a rejected match on every page visit.
8. **What does a GTIN mismatch do?** **Decision:** Warn on the product page; change nothing about pricing or alerts. **Rationale:** On the Feliway product all three shops carry different EANs at 27.99–57.72, which is worth surfacing — but excluding offers from the cheapest calculation would silently drop offers that may be correct.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
