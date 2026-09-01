# Shop Suggestions

<!-- spec:planned-at 01b55bf9cf71b0b1583196f6d1071a83c1b40c79 2026-09-01 +uncommitted -->

## Overview

Suggests other shops that sell the product a user already tracks, so a one-shop product grows into a real price comparison without hunting for URLs. Suggestions come from the checkjebon dataset the app already downloads daily — twelve supermarket chains, about 107k products — matched on name and pack size. Accepting a suggestion runs the normal probe, so the stored price stays live-scraped, never dataset-derived. A second, smaller part captures the GTIN each shop page publishes and warns when tracked offers turn out to be different articles.

## Assumptions

- **Scope: dataset suggestions plus GTIN capture** — confirmed by user. Search-API discovery for non-grocery products (Feliway-class) is explicitly out; see `## Open Questions`.
- **Every chain that carries rows is imported** — confirmed by user ("all twelve"), narrowed by the data: Aldi and Ekoplaza ship zero rows upstream, so `SUPERMARKETS` lists the ten chains with data. The two empty keys are added the day upstream fills them; nothing about the import is chain-specific.
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

**Staleness reporting has a gap once suggestions exist.** `CheckjebonFreshnessCheck` reports `idle` unless an active shop uses `adapter_key = 'checkjebon'` (`app/Health/CheckjebonFreshnessCheck.php:38-46`). After this change the dataset also feeds suggestions for users with no dataset-priced shop at all, so a stale or empty dataset would silently degrade suggestions with the health check reporting `idle`. The in-use condition widens to "any active checkjebon shop **or** any product that could receive suggestions", and the age it reports becomes the **oldest** chain's `refreshed_at`, not the newest row's — one refreshed chain currently masks nine stale ones. Age alone cannot see a chain with **no** rows at all (a first import where upstream served nothing), so the check compares the configured chain list against the chains actually present and reports the missing ones separately.

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

- **Query text** = the product title plus pack-size tokens. Pack size lives on the **shop**, not the product (`shops.pack_*`, from `specs/unit-pricing.md`), and two shops of one product can disagree — a 150 g and a 250 g pack of the same cheese. So: collect the distinct normalized sizes across the product's shops, score the catalogue once per distinct size, and keep the best score per chain across those runs. A product with no sized shop scores on title tokens alone. Distinct sizes are typically one, at most a handful, so the extra passes are free.
- **Prefilter in SQL** on the longest name token, then score in PHP. The comparison must be explicitly case-insensitive — production runs PostgreSQL (`.env.example`), where `LIKE` is case-sensitive and `name LIKE '%beemster%'` would miss "Beemster Extra Belegen" entirely. Compare on a lowercased column expression (`LOWER(name) LIKE ?`) so SQLite and PostgreSQL behave identically, and add the matching functional index on PostgreSQL if the measurement below regresses there.
- **Benchmark caveat.** The 47-rows-of-45,681-in-14 ms measurement is SQLite on a development machine. Re-measure on PostgreSQL before trusting STOP condition 3.
- **Threshold 0.55**, best-scoring row per chain, chains already tracked on the product excluded, dismissed pairs excluded.
- **Tracked-chain exclusion needs a chain → host *alias set*, not one host.** The dataset key is not the host (`poiesz` serves `webwinkel.poiesz-supermarkten.nl`), and one chain can have two: `lidl` links to `boodschaapje.nl` in the dataset, while `LidlAdapter` now scrapes `lidl.nl` directly (recorded in `specs/unit-pricing.md` Findings). A product already tracking `lidl.nl` must not be offered the boodschaapje row. Each chain therefore declares every host it can be tracked under; comparison runs through `UrlNormalizer::normalizeHost()`, the same normalization `Shop::booted()` applies when it stores `shops.host`, so `www.ah.nl` and `ah.nl` compare equal.
- **Order** by score descending, then chain name, so the list is stable between renders.
- **Freshness gate, per chain.** Drop a chain whose newest `refreshed_at` is older than 96 hours — the threshold `CheckjebonFreshnessCheck` fails at. It must be per chain, not one global maximum: the importer deliberately keeps existing rows when a chain is missing or empty upstream (`RefreshCheckjebonDatasetCommand.php:75-82`), so a successful AH refresh would otherwise make a month-old Jumbo catalogue look fresh. When every chain is stale the panel renders nothing.

## 3. Trackability

A suggestion is *trackable* when the app can price that chain's URL today. Verified by running the adapter chain against a constructed URL per chain on 2026-09-01:

| Chain | Result | Trackable |
|---|---|---|
| ah | AH mobile API (live, bonus-aware) | yes |
| lidl | dataset path for the `boodschaapje.nl` URL the dataset links to; `LidlAdapter` prices `lidl.nl` directly when a user adds that host themselves | yes |
| dirk | `DirkAdapter`, direct scrape | yes |
| jumbo | `JumboAdapter`, direct scrape | yes |
| spar | JSON-LD, 3.39 correct | yes |
| hoogvliet | generic adapter returns **22.33** for a 3.09 product | **no** |
| plus, dekamarkt, vomar, poiesz | `no_adapter_matched` | no |

Trackability is a static map keyed by chain, not a probe at render time — a per-suggestion probe would burn the 6/min per-user probe budget in `ProbeShopUrl` (`app/Actions/Shops/ProbeShopUrl.php:46`) before the user clicked anything. Hoogvliet is listed as untrackable *despite* parsing, because a confidently wrong price is worse than no price.

Untrackable suggestions render with a muted "not trackable yet" label, the dataset price, a link that opens the shop page in a new tab, and a disabled add button.

## 4. Accepting a Suggestion

Accepting hands the constructed URL to the existing add-shop flow — no new persistence path:

1. The suggestion component dispatches a `suggest-shop` event carrying the URL, targeted at the page's single `AddShop` instance (`app/Livewire/Shops/AddShop.php:17`), which already owns probe → preview → confirm. `AddShop` gains the listener — and an ownership check: it holds a hydrated `public Product $product` that Livewire re-queries by key on every request without authorizing it, so `confirm()` writes through whatever product id the client sends. Authorizing only the suggestion component would protect the sender and leave the writer open. This hardens a pre-existing hole; it is not caused by suggestions, but suggestions add a second entry point to it.
2. `ProbeShopUrl` normalizes, dedupes against the product's shops, enforces the per-user rate limit, resolves the adapter and checks the currency.
3. The user sees the live price, title and image, then confirms. `AddShop::confirm()` stores the shop and the first `PriceCheck`, and recomputes the cheapest offer.

A probe failure surfaces with the existing error copy. The suggestion stays in the list; it is not auto-dismissed, since the failure is usually transient (rate limit, temporary block).

## 5. Dismissals

New table `shop_suggestion_dismissals`: `product_id`, `chain` (dataset key), `external_id`, `dismissed_at`. Unique on `(product_id, chain, external_id)`, written with `insertOrIgnore` so a double-click or two tabs cannot raise a unique-key exception. Dismissing hides that specific dataset row for that product; a later dataset refresh that replaces the row with a new `external_id` surfaces it again, which is the intended behaviour — the catalogue changed.

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
- **Extraction is independent of variant-key selection.** `JsonLdEntitySearcher::KEY_FIELDS` (`app/PriceAdapters/JsonLdEntitySearcher.php:15`) is `productID`, `sku`, `gtin13`, `gtin` — it picks *which entity* a variant URL refers to, and it names neither `gtin14` nor `gtin8`. GTIN capture reads its own field list, `gtin13` → `gtin14` → `gtin12` → `gtin8` → `gtin`, **from the entity the searcher already selected** (the tracked variant), never from the first product on the page. Zooplus proves why: one page, three variants, three GTINs, and only `169589.19` is tracked. Digits only, length 8/12/13/14, **and a valid GS1 check digit** — a wrong-length-but-numeric string or a typo'd digit would otherwise persist and raise a false mismatch warning. Anything else stores null.
- **Scope both readers to one product entity.** `MicrodataAdapter` currently selects price, name and image independently, each as the first match anywhere on the page (`app/PriceAdapters/MicrodataAdapter.php:19-45`). Reading the GTIN the same way would pair a related product's identifier with the tracked offer and manufacture a mismatch warning. Read it from the enclosing `itemscope` of the price node, and null when that scope carries none.
- **Host wrappers must carry the fields through.** `DirkAdapter` and `LidlAdapter` delegate to `JsonLdAdapter` and then rebuild a `ShopSnapshot` field by field to attach the pack size (`app/PriceAdapters/Hosts/DirkAdapter.php:48-57`, `LidlAdapter.php:48`). A rebuild that forgets `gtin` silently drops it for the two chains that use these adapters — the same trap `imageUrl` and `packSize` already sit in.
- **Authority, mirroring `packSizeAuthoritative`.** A snapshot from an adapter that reads GTIN fields (`JsonLdAdapter`, `MicrodataAdapter`) is authoritative: a page that no longer publishes a GTIN clears the stored value, so a mismatch warning cannot outlive the data it was based on. A snapshot from a source with no GTIN concept (AH API, checkjebon, generic, user-selector) is non-authoritative and leaves the stored value untouched.
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
| Shop page stops publishing its GTIN | An authoritative snapshot with no GTIN clears the stored value, so the mismatch warning disappears with it. Covered by the GTIN phase Tests. |
| Product already tracks `lidl.nl`, dataset links `boodschaapje.nl` | The Lidl host alias set covers both, so no duplicate suggestion. Covered by the matching phase Tests. |
| Two tabs accept the same suggestion at once | Pre-existing `AddShop` behaviour: both probes pass the duplicate check, and the second `confirm()` hits the `(product_id, url_hash)` unique key. Out of scope here — see `## Open Questions`. |
| Two users track the same product | Dismissals are per product, and products are per user, so no cross-user leakage. Covered by the dismissal Tests. |
| A user addresses a Livewire component with another user's product id | Both `ShopSuggestions` and `AddShop` resolve their product through one owner-scoped resolver on **every** public action, not on `mount()` alone — Livewire re-hydrates public state per request. In `AddShop` that common path is `DrivesShopProbe::runProbe()`, reached by `probe()`, `probeWithSelectors()` and `selectVariant()` alike (`app/Livewire/Concerns/DrivesShopProbe.php:67-84`), plus `confirm()`. Covered by the suggestions-component and add-shop Tests. |
| A user accepts several suggestions in a row | The seventh probe inside a minute hits the existing 6/min per-user budget in `ProbeShopUrl` and returns its rate-limit message. The suggestion stays listed and can be retried. Covered by the accept-flow Tests. |
| Shop reports a GTIN during the probe preview, before the shop row exists | The value travels in the Livewire preview snapshot alongside `image_url`, the same transport `DrivesShopProbe` already uses, and is written by `confirm()`. Covered by the GTIN phase Tests. |

## Implementation

### Phase 1: Import every chain (Priority: HIGH)

**ID:** dataset · **Depends:** none

- [x] Extend `SUPERMARKETS` to the ten chains that carry rows, keeping `aldi` / `ekoplaza` out until upstream fills them — `RefreshCheckjebonDatasetCommand.php:30`.
- [x] Add `link` to `checkjebon_prices` (nullable text, appended) and store the raw `l` value per row, so a suggestion can build its URL without re-fetching the dataset.
- [x] Import the per-chain `u` (base URL) and `c` (display name) into a chain map the app can read at render time; refresh it on every import so an upstream base-URL change follows automatically.
- [x] Keep `externalIdFromLink()` correct for the new chains — slug chains keep the full slug as their id, numeric chains the number; the unique key stays `(supermarket, external_id)`.
- [x] Confirm `CheckjebonSource` still resolves prices for `ah` and `lidl` only, so importing eight more chains changes no pricing behaviour.
- [x] Widen `CheckjebonFreshnessCheck`: report when any product could receive suggestions, and age it on the **oldest** chain rather than the newest row, so one refreshed chain stops masking stale ones.
- [x] Tests — import fixture with all twelve chains; asserts row counts per chain, `link` stored, chain map populated, empty chains skipped without pruning existing rows, `CheckjebonSource::supports()` unchanged, the freshness check reporting an empty dataset when no shop uses the pricing path, a partial refresh reporting the oldest chain's age, and a configured chain that has never produced a row being reported as missing.

### Phase 2: Matching service (Priority: HIGH)

**ID:** matching · **Depends:** dataset

- [x] Add `SuggestShops` action: build one query per distinct tracked pack size (title-only when none), prefilter with a lowercased, case-insensitive `LIKE` on the longest token, score with Jaccard, drop below 0.55, keep the best row per chain across the runs.
- [x] Exclude chains already tracked on the product — compare `shops.host` against the chain's host alias set (Lidl covers `boodschaapje.nl` and `lidl.nl`), normalized through `UrlNormalizer::normalizeHost()` — and exclude rows dismissed for that product.
- [x] Drop any chain whose newest `refreshed_at` is older than 96 hours; render nothing when that leaves no chain.
- [x] Return a typed suggestion object per row: chain key, display name, product name, size, dataset price, constructed URL, score, trackable flag.
- [x] Add the trackable chain map (`ah`, `lidl`, `dirk`, `jumbo`, `spar`), with the Hoogvliet exclusion documented in the code.
- [x] Add `shop_suggestion_dismissals` (migration + model + cascade on product delete) and the dismiss method the UI calls, written idempotently with `insertOrIgnore`.
- [x] Return nothing when the product currency is not EUR, or when the dataset holds no rows.
- [x] Tests — a repeated dismissal of the same row succeeding twice, scoring fixtures reproducing the Beemster table (seven accepts, three rejects), tracked-chain exclusion for both Lidl hosts, dismissal exclusion, tie-break stability, empty dataset, a partial refresh where one chain is fresh and another is four days old, non-EUR product, a product whose shops carry two different pack sizes, mixed-case catalogue names, and a query built from title only.

### Phase 3: Suggestions component (Priority: HIGH)

**ID:** suggestions-ui · **Depends:** matching

- [x] Authorize on **every** request, not only `mount()` — Livewire re-hydrates public state between calls, so `dismiss` and `accept` each re-resolve the product through an owner-scoped query (`ProductPolicy@view`). `mount()`-only checks are bypassable by tampering with the hydrated id.
- [x] Add a `ShopSuggestions` Livewire component that renders the list for a product: image-free row with chain name, product name, size, dataset price labelled as a dataset price, score not shown.
- [x] Trackable row: an "Add" button that hands the URL to `AddShop`, which then runs its normal probe → preview → confirm. **Define the contract here, in this phase, so both surfaces stay write-disjoint:** a `suggest-shop` event carrying `url`, dispatched `to` the `AddShop` component; `AddShop` gains a listener that sets `url` and calls `probe()`, plus one owner-scoped product resolver in the common path — `runProbe()` covers `probe()`, `probeWithSelectors()` and `selectVariant()` — and the same check in `confirm()`. Both surfaces render an `AddShop` on the same page in principle, so the dispatch must target one instance — the panel scrolls to and opens the disclosure rather than mounting a second `AddShop`.
- [x] Untrackable row: muted "not trackable yet" label, an external link to the shop page, disabled add button.
- [x] Dismiss control per row, writing through the action from phase 2.
- [x] Empty state: "No other shops found" when the query returns nothing; the component hides entirely when the dataset is empty.
- [x] Tests — render with mixed trackable and untrackable rows, add dispatches the expected event and payload, the `AddShop` listener enters preview state from that event, dismiss removes the row and persists, empty state, hidden state, a forbidden response for another user's product on `mount`, `dismiss` and `accept`, and direct `AddShop` tests that tamper with the hydrated product id and are refused on `probe()`, `probeWithSelectors()`, `selectVariant()` and `confirm()`.

### Phase 4: Product view surface (Priority: HIGH)

**ID:** ui-view · **Depends:** suggestions-ui

- [x] Mount `ShopSuggestions` in a panel above the shops table on the product view page — the header slot already hosts the add-shop disclosure (`ShopsRelationManager.php:64`, `resources/views/filament/partials/add-shop-header.blade.php`).
- [x] Refresh the panel when a shop is added or removed, so an accepted suggestion disappears from the list.
- [x] Eye-verify in a browser: panel renders, add opens the probe preview, dismiss removes the row, empty state on a product whose chains are all tracked.
- [x] Tests — the panel renders on the view page for a product with matches, and disappears for a product with none.

### Phase 5: Add-shop surface (Priority: HIGH)

**ID:** ui-add-shop · **Depends:** suggestions-ui

- [x] Mount `ShopSuggestions` inside the add-shop disclosure next to the URL field (`resources/views/livewire/shops/add-shop.blade.php`), so a user who opened it to paste a URL sees the alternatives first.
- [x] Hide the list once the component is in preview or confirm state, so the suggestion list does not compete with the probe result.
- [x] Eye-verify in a browser: list shows on open, accepting fills the probe preview, list hides during preview and returns after reset.
- [x] Tests — suggestions render inside the add-shop component and disappear in preview state.

### Phase 6: GTIN capture and mismatch warning (Priority: MEDIUM)

Depends on both UI phases, not because it needs their output, but because all three touch the add-shop flow and its test file (`tests/Feature/Shops/AddShopLivewireTest.php`); serialising them keeps every parallel pair write-disjoint.

**ID:** gtin · **Depends:** ui-view, ui-add-shop

- [x] Add `?string $gtin` and `bool $gtinAuthoritative` to `ShopSnapshot`, alongside the existing pack-size pair.
- [x] Read `gtin13` → `gtin14` → `gtin12` → `gtin8` → `gtin` in `JsonLdAdapter` (from the entity the variant search already selected) and in `MicrodataAdapter` (from the enclosing `itemscope` of the price node), digits-only, length 8/12/13/14, GS1 check digit valid.
- [x] Carry both fields through `DirkAdapter` and `LidlAdapter`, which rebuild the snapshot to attach the pack size.
- [x] Carry the value through the Livewire preview snapshot in `DrivesShopProbe`, the transport `image_url` already uses, so `AddShop::confirm()` and `CreateProductFromUrl::confirm()` can persist it.
- [x] Add `shops.gtin` (nullable string, appended); write it on probe confirm and on every successful check; clear it in `Shop::updateUrl()` next to the other URL-blind hints.
- [x] Show one warning line above the shops table when the product's shops report two or more distinct non-null GTINs, naming the differing hosts.
- [x] Leave `recomputeCheapestShop()`, thresholds and notifications untouched.
- [x] Tests — JSON-LD and microdata extraction including malformed values and a wrong check digit, a multi-variant page where the tracked variant's GTIN wins, a microdata page whose first product scope is a different product, a Dirk or Lidl page proving the wrapper keeps the field, persistence on probe and on recheck, an authoritative snapshot with no GTIN clearing the stored value, a non-authoritative source (AH API) leaving it untouched, clearing on URL change, and the warning shown for two distinct GTINs and hidden for one or none.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The dataset keeps its per-chain `u` / `c` / `l` fields** — the whole URL-construction design rests on `u . l`. If upstream drops or renames them, every non-AH/Lidl suggestion loses its link and the phase needs a different source.
2. **Importing eight extra chains does not touch pricing** — if `CheckjebonSource` starts resolving prices for a newly imported chain, shops would silently switch from live scraping to daily dataset prices. Stop; the chain map must stay pricing-blind.
3. **Matching stays inside a page-render budget** — 14 ms at 45,681 rows, measured on SQLite. Re-measure on PostgreSQL, which production uses. If the ten-chain table pushes a suggestion query past roughly 150 ms there, stop and move to a cached or precomputed design rather than shipping a slow product page.
4. **`ProductPolicy` authorizes a product for its owner** — the suggestions component depends on it for its only access check. If the policy does not cover the `view` ability for a product, stop and add the check explicitly rather than relying on page scoping.
5. **`ShopSnapshot` is extended, not restructured** — the unit-pricing work adds `packSize` / `packSizeAuthoritative` to the same constructor. If that work has changed the class shape by the time phase 6 starts, re-read it before adding `gtin`.

---

## Open Questions

1. **Should untrackable chains become trackable through the dataset?** Lidl is already priced from the dataset, so Plus, DekaMarkt, Vomar and Poiesz could follow the same path. It buys four chains at the cost of daily regular prices with no bonus detection. Deliberately out of scope here; worth its own spec.
2. **Non-grocery products get nothing from this feature.** Research on the Feliway product showed that EAN search fails outside food retail — bol.com returns "0 resultaten" for an EAN it stocks by name, and beslist / kieskeurig / idealo are bot-walled or JS-only. A search-API candidate discovery (query = brand + model + size, then probe each candidate) is the only route found, and it needs an API key, a per-product budget and a confirm step. Separate spec.
3. **Can a dismissal be undone?** No undo UI is specified. If a user dismisses by accident the suggestion is gone until the dataset row changes.
4. **Should `AddShop::confirm()` handle a concurrent duplicate?** Two tabs can both clear `ProbeShopUrl`'s duplicate check and the second insert then violates the `(product_id, url_hash)` unique key. Suggestions make the double-accept path easier to hit, but the bug is pre-existing and lives in `AddShop`, not here. Fix separately.
5. **Should the panel show a price delta?** The dataset price is regular-price only, so "€0.20 cheaper" could be wrong on a promotion day. Currently the raw dataset price is shown, labelled. A delta needs a product decision.

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

- **DekaMarkt links do not resolve (post-ship check).** Verifying suggestion URLs against the live sites showed every DekaMarkt link is dead. Its dataset base URL points at `/boodschappen/…`, where each id answers "Het artikel is niet gevonden"; the real pages live at `/producten/x/x/x/<id>`, but that id space is different again — five random dataset ids all miss there, while an id copied from dekamarkt.nl itself resolves. DekaMarkt is therefore excluded from suggestions until the ids can be mapped: a row nobody can open or track is noise. Dirk, Poiesz, Spar and Vomar links were verified to reach the real product (Vomar renders client-side, so the page is only complete in a browser).

- **Codex review (implementation, round 3, final).** The importer no longer carries a hard-coded chain list: it walks the payload and imports every chain that declares rows, storing that chain's own base URL and label. This settles the "all twelve" decision better than a list would — a chain upstream fills tomorrow arrives without a code change — while a chain that ships empty gets no metadata row, so the health check cannot report ALDI and Ekoplaza as missing forever. `MicrodataAdapter` no longer falls back to a page-wide read once the price sits in a scope; only a price outside any scope may read page-wide, which was the last way two products' fields could be combined. Event tests now assert the dispatch *target* (`assertDispatchedTo('shops.add-shop', …)`), and a page-level test dismisses a suggestion and re-renders the relation manager to prove the row is gone.

- **Codex review (implementation, round 2).** Five more findings, all applied. `AddShop::mount()` accepted any hydrated product — authorized now, with a direct forbidden-mount test. A dismissal only re-rendered the instance that received it, so the hidden copy could show the row again when it reappeared; the component now broadcasts `shop-suggestions-changed` and both instances listen. `Gtin::normalize()` stripped *every* non-digit, which silently turned `8712ABC243044506` into a valid GTIN — only separators (space, dash, dot, non-breaking space) are stripped now, anything else is rejected. `MicrodataAdapter` searched all descendants of an enclosing scope, so a *nested* product could donate its GTIN; property lookup is restricted to nodes the scope owns (nearest enclosing `itemscope` identity, compared with `isSameNode()` — PHP hands out a fresh object per DOM node access). Reading a scoped value also had to keep the `href`/`src` fallbacks, which the first scoping pass dropped for images. The importer fixture covered five chains, so removing six of the ten from `SUPERMARKETS` would not have failed a test; it now carries all twelve dataset entries and asserts the imported chain keys.

- **Codex review (implementation, round 1).** Four findings, all applied. `showManualSelector()`, `cancel()` and `useSuggestion()` mutated component state before any ownership check — authorized now, and the tampering test covers all six public actions. The empty state said "No other shops found" when every chain was *stale*, which is a claim about the product rather than about the catalogue; `SuggestShops::hasUsableCatalogue()` now drives that flag with the same per-chain freshness rule. Both suggestion components render server-side even though only one is visible, so the ~90 ms catalogue scan was paid twice per page — the action is bound per request and memoizes by product, dropping the memo on dismissal. And `MicrodataAdapter` read the price from one scope while reading title, image, currency and availability from the first match anywhere on the page: a related-products block could pair this offer's price with a neighbour's title. Every field now reads from the scopes enclosing the price, nearest first, with the page-wide read as the last resort.

- **Microdata scoping, corrected against a live page.** Reading only the *nearest* enclosing `itemscope` returned null on dierapotheker.nl: the price sits in an `Offer` scope nested inside the `Product` scope that carries `gtin13`. The reader now walks the enclosing scopes outwards, nearest first, and stops at the first GTIN — still never a sibling scope. Verified live: dierapotheker.nl 3411112291649 (microdata), pharmacy4pets.nl 3411112987955 (JSON-LD), zooplus.nl 3411112169566, and the warning renders on the product page. The zooplus value is the first variant's, not the tracked variant's, because that shop row has no `variant_key`; variant selection is pre-existing behaviour and unchanged here.

- **Requirements walk.** Two gaps the task checkboxes did not catch: the component hid itself whenever there were no suggestions, which erased the spec's "No other shops found" empty state — it now distinguishes an empty *result* (says so) from an empty *catalogue* (stays silent, because that is an operational problem, not an answer); and the edge case "a user accepts several suggestions in a row" had no test, now covered by driving the seventh probe into `ProbeShopUrl`'s 6/min per-user budget.

- **Phase 6.** GTIN normalization lives in `App\Support\Gtin`: digits only, a GS1 length (8/12/13/14), and a valid check digit — a typo'd digit would otherwise raise a mismatch warning about nothing. `JsonLdAdapter` reads the field from the entity the variant search already selected (so a multi-variant page yields the tracked variant's GTIN), `MicrodataAdapter` from the `itemscope` enclosing the price node (so a related-products block cannot donate its identifier). `DirkAdapter` and `LidlAdapter` rebuild the snapshot for pack size and now carry both new fields through. Persistence follows the authority rule, which is the opposite of the image rule: an adapter that reads GTIN fields and finds none clears the stored value, while a source with no GTIN concept (AH API, checkjebon) leaves it alone. The warning renders above the shops table from `Product::mismatchedGtinHosts()`, with hosts sorted so the line is stable; pricing, thresholds and notifications are untouched.

- **Phases 4 + 5 (deviation, documented).** Rendering both surfaces on the product page stacked the same list twice — the panel sits directly above the disclosure that contains the second copy. Exactly one is now visible at a time: an Alpine flag on the wrapper hides the panel while the `<details>` is open. Both surfaces stay real, neither duplicates the other. Accepting from the panel also dispatches `open-add-shop`, because the probe preview would otherwise render inside a collapsed disclosure and the click would look like it did nothing. Removing a shop now dispatches `shop-removed` from the relation manager's delete actions, so the panel can offer the chain again. Eye-verified live on the Beemster product: panel → Add (SPAR) → live probe preview (€ 3.69) → Confirm → shop stored and the SPAR row gone from the list; Hide on PLUS persisted and the next-best PLUS row took its place; the Feliway product (non-grocery) renders no panel at all; a 404 URL shows "The shop returned HTTP 404" with a "Try a different URL" recovery button while the list stays put. New blade files need `npm run build` — the first render was unstyled because Tailwind had not scanned them.

- **Phase 3.** Authorization lands in two places: `ShopSuggestions` re-resolves its product by id and calls `Gate::authorize('view', …)` on every request, and `AddShop::probeSubject()` does the same — `runProbe()` calls it, so `probe()`, `probeWithSelectors()` and `selectVariant()` are all covered, plus an explicit check in `confirm()`. `probeWithSelectors()` and `selectVariant()` return early before `runProbe()` when their input is empty, so the trait now resolves the subject first; without that, a tampered id could mutate component state before the check. Livewire renders an `AuthorizationException` as a 403 response rather than throwing, so the tests assert `assertForbidden()`. Existing `AddShop` tests authenticated as an unrelated user (the flow was previously unguarded) and now act as the product owner — the component is only ever rendered for the owner. The suggestion list is stateless, so the `shop-added` / `shop-removed` listeners need no body: re-rendering recomputes it.

- **Phase 2.** `SuggestShops` scores canonical *pack-size tokens* on both sides (`PackSize` normalizes to g/ml/piece), not raw size text — so "150 g", "150 gram" and "Per 150 g" all reduce to `150 g` and score identically. That lifts real scores above the spec's measured table (Hoogvliet 0.75 → 1.00 on the Beemster product), and the fixture reproduces the seven-accept / three-reject split with the product's own 150 g pack size in the query. Two known limitations, both visible to the user rather than hidden: a wrong-size row can still score high (Dirk's 300 g Lay's scores 0.83 against a 200 g product), and one genuine false positive appears at the specified 0.55 threshold on live data (PLUS "Compaxo Amigo's naturel" at 0.57 for Lay's Naturel). The suggestion row shows the catalogue size and name, and adding still runs a probe, so both are recoverable. `Shop::packSize()` is private, so the action reads `pack_quantity`/`pack_unit` and rebuilds through `PackSize::of()`. Measured 46 ms per product against the full 107k-row local dataset.

- **Phase 1.** Chain metadata lives in a new `checkjebon_chains` table (chain, label, base_url, refreshed_at), not a config map — the importer refreshes it from the dataset's own `u`/`c` fields, so an upstream base-URL change follows automatically. `external_id` for the eight match-only chains is the raw link (slug or number); `ah` keeps its `wi…` id and `lidl` its numeric id, because `CheckjebonSource` looks those up from a URL. `CheckjebonFreshnessCheck` now ages on the oldest chain (`max(refreshed_at)` grouped by chain, ordered ascending) and reports chains with no rows separately; its in-use condition is "a checkjebon shop **or** any product". Three existing importer tests asserted "Jumbo and Dirk are never imported" — that contract is intentionally reversed, and the tests now assert the new one.
