# Promotion Window

<!-- spec:planned-at 64c3700ff7b6ae485989a9b4dee48b223ead8880 2026-09-02 +uncommitted -->

## Overview

Shops tell us when a promotion runs, and the app throws that away. Albert Heijn's API answers `bonusStartDate: "2026-08-31"`, `bonusEndDate: "2026-09-06"`, `bonusMechanism: "VOOR 1.69"` beside the price; Dirk, DekaMarkt, Aldi and schema.org pages all state a window too. This spec stores the running promotion on the shop and shows it under the price, so a shopper can see that 1.69 lasts until 6 September rather than guessing whether a low number is permanent.

The window describes the tracked price. It is not the same thing as the conditional offer added in `74cd894`, which is a price only some shoppers can pay.

## Assumptions

Each bullet is an inference this spec makes. Skim this section to sign the spec off.

- **Source scope covers every source that already has the data** — AH API, Dirk, DekaMarkt, Aldi and generic JSON-LD. Confirmed by the user.
- **The window lives in columns on `shops`, overwritten each check** — no per-check history. Confirmed by the user against the numbers: `price_checks` holds 46 rows today and grows roughly 22k rows a year at 15 shops on the 6-hour interval, and the price chart reads `product_cheapest_history`, so per-check windows would not reach it without further work.
- **An expired window renders as ended rather than disappearing** — "Bonus ended 6 Sep", so a stale price on screen is visibly suspect until the next check. Confirmed by the user.
- **The end date does not influence scheduling** — display only, re-checks stay on the fixed interval. Confirmed by the user.
- **Column names are `promotion_starts_at`, `promotion_ends_at`, `promotion_label`** — AI-chosen. "Promotion" rather than "bonus" because only Albert Heijn calls it Bonus; see Terminology.
- **Date-only sources are stored as Europe/Amsterdam day boundaries** — AI-chosen. `bonusEndDate: "2026-09-06"` becomes `2026-09-06 23:59:59` in that zone, stored as the UTC instant `2026-09-06 21:59:59` (`config('app.timezone')` is `UTC`). These are Dutch retail dates: reading them as UTC would shift both edges two hours into the next local day, so a promotion would still read as running at 01:30 on the 7th.
- **The label is the shop's own words, not ours** — AI-chosen. AH's `bonusMechanism` ("VOOR 1.69"), DekaMarkt/Dirk have no label field and store none. The UI supplies the framing ("until 6 Sep"); the label is shown only when the source gave one.
- **The window follows the GTIN clearing rule, and authority is decided per source** — AI-chosen, matching `74cd894`. AH is authoritative whenever the card carries `isBonus` at all, true or false: a normal product answers `isBonus: false` and omits the date keys, so keying authority on the dates would strand an ended bonus on screen forever. JSON-LD is authoritative whenever an offer supplied the price, so a page that drops `priceValidUntil` clears the window. A source with no promotion concept (checkjebon, Lidl, Jumbo) leaves it untouched.
- **A window belongs to the price it applies to** — AI-chosen. DekaMarkt reports a window only when it selected `offerPrice`; when the offer has expired the adapter falls back to `normalPrice`, and attaching the expired window to that price would label the ordinary price as a promotion. Aldi refuses the price outright outside its window, so the question does not arise there.
- **A window with no end date is not stored** — AI-chosen. "Until when" is the entire point; an open-ended promotion has nothing to show.
- **A `priceValidUntil` more than 90 days out is ignored as a placeholder** — confirmed by the user, with a known expiry: the cutoff is measured from the check date, so pharmacy4pets' `2027-12-31` stops being filtered around **2 October 2027**, after which that shop would advertise its ordinary price as a promotion. No public signal distinguishes a placeholder from a real date, so this is a heuristic with a date attached rather than a rule. See Open Questions. Shops use the field both ways: spar.nl states `2026-09-03` (a real weekly end) and zooplus `2026-09-09`, while pharmacy4pets states `2027-12-31`, which would otherwise render as "in discount until 31 Dec 2027". The 90-day cutoff is a judgement call — Dutch retail promotions run in weeks — and applies only to schema.org dates, never to the structured sources, which state real campaign windows.
- **A window whose start is after its end is discarded** — AI-chosen. Such a pair is a source bug, and guessing which end is right would put a wrong date on screen.
- **Nothing about alerts, price history or `current_price` changes** — AI-chosen. The promotional price is already the tracked price; this spec only records how long it lasts.

---

## 1. Terminology

| Term | Meaning |
|---|---|
| **Promotion window** | The period a shop states its promotional price runs for. The app's own term, used in columns and code. |
| **Bonus** | Albert Heijn's name for its promotions (`isBonus`, `bonusStartDate`, `bonusMechanism`). Shop-specific; appears in the UI only where the shop's own label is rendered. |
| **Conditional offer** | A price only part of a shop's customers can pay (`74cd894`). A different concept: it never becomes the tracked price, while a promotional price already is the tracked price. |

## 2. Data Model

Three nullable columns appended to `shops`:

```php
$table->timestamp('promotion_starts_at')->nullable();
$table->timestamp('promotion_ends_at')->nullable();
$table->string('promotion_label')->nullable();
```

`promotion_starts_at` is nullable on its own: several sources state only an end date (`priceValidUntil`). A row with an end date and no start is a promotion running now that ends then.

A `PromotionWindow` value object mirrors `App\PriceAdapters\ConditionalOffer`:

```php
final readonly class PromotionWindow
{
    public function __construct(
        public CarbonImmutable $endsAt,
        public ?CarbonImmutable $startsAt = null,
        public ?string $label = null,
    ) {}

    public function isRunning(?CarbonImmutable $now = null): bool;
}
```

`endsAt` is the first constructor argument and is required — the spec stores no open-ended window.

`ShopSnapshot` gains `?PromotionWindow $promotionWindow = null` and `bool $promotionWindowAuthoritative = false`, and `Shop` gains a `promotionWindow(): ?PromotionWindow` accessor that returns the stored window whether or not it has ended (unlike `conditionalOffer()`, which hides an expired offer — here the expiry is the thing worth showing).

## 3. Source Mapping

Every field below was read from a live response on 2026-09-02.

| Source | Fields | Verified value | Today |
|---|---|---|---|
| AH API (`AhApiSource::snapshotFrom()`) | `bonusStartDate`, `bonusEndDate`, `bonusMechanism`, gated on `isBonus` | Lay's wi526381: `2026-08-31` → `2026-09-06`, `"VOOR 1.69"` | Read into `raw` only as `is_bonus` / `price_before_bonus`; the dates are never read |
| Dirk (`DirkAdapter`) | Nuxt price record `startDate`, `endDate` beside `offerPrice` | product 115212: `2026-08-26T00:00:00+02:00` → `2026-09-08T23:59:59+02:00`, offer 1.69 vs normal 3.29 | The adapter takes its price from JSON-LD and reads only `packaging` from the payload — it locates no price record at all |
| DekaMarkt (`DekaMarktAdapter::offerIsRunning()`) | `startDate`, `endDate` on the price record | Parsed already, to decide whether the offer price applies | Parsed, then discarded as a boolean |
| Aldi (`AldiAdapter::bound()`) | `currentPrice.validFrom`, `currentPrice.validUntil` (unix) | granola 91244024: `2026-08-31 22:00` → `2026-09-06 21:59:59` UTC | Parsed, then discarded after the staleness check |
| Generic JSON-LD (`JsonLdAdapter`) | `offers.priceValidUntil`, `offers.validFrom` when present | zooplus 169589.19: `2026-09-09T09:04:05Z`; spar.nl 9183397: `2026-09-03` | Not read |
| checkjebon, Lidl, Jumbo, Vomar, Poiesz, Bol, Amazon, Dierapotheker | none | Checked live: Lidl, Jumbo, Dirk and DekaMarkt pages carry no `priceValidUntil` | Nothing to read; these leave the stored window untouched |

Spar is covered by the generic mapping rather than a rule of its own: `SparAdapter` delegates to `JsonLdAdapter` and keeps its snapshot, so the window arrives with the price.

### Authority — when a source may clear a stored window

| Source | Authoritative when | A cleared window means |
|---|---|---|
| AH API | the card carries `isBonus`, whether true or false | the bonus ended; a normal product omits the date keys entirely, so authority keys on `isBonus`, never on the dates |
| Dirk, DekaMarkt, Aldi | the adapter produced a price from the payload | the payload states no running promotion for the price it chose |
| Generic JSON-LD | an offer supplied the price | the page no longer states `priceValidUntil`, so the promotion is over |
| checkjebon, Lidl, Jumbo, Vomar, Poiesz, Bol, Amazon, Dierapotheker | never | nothing — these leave a window another source stored |

The JSON-LD rule accepts a known cost: a page that drops the field for one check clears a window that was real. A promotion badge that outlives its promotion is the worse failure, because it makes the price on screen look temporary when it is not.

### Dirk needs a price record it does not currently have

`DirkAdapter` takes its price from the JSON-LD and reads only `packaging` out of the Nuxt payload (`DirkAdapter.php:42`). There is no selected price record to read a window from, so this phase adds one: find the price record whose `productId` matches the URL id — the same id `packagingFromNuxtPayload()` already matches on — and use its window **only when its `offerPrice` equals the price the JSON-LD supplied**. A record that prices something else is a different offer, and its window would be attached to a price it does not describe. When no record matches, or the prices disagree, no window is reported.

### The window belongs to the price

DekaMarkt selects between `offerPrice` and `normalPrice` (`DekaMarktAdapter::currentPrice()`). It reports the window **only when it selected the offer price** — attaching the expired window to the normal price would label the ordinary price as a promotion. Aldi refuses a price whose window has closed, so its reported window always matches its reported price.

DekaMarkt also accepts **several** price records when their computed prices agree: the adapter fails as ambiguous only when the prices differ. Those records can carry different windows. When the accepted records do not agree on one window, no window is reported — the price is still trustworthy, the window is not.

Aldi and DekaMarkt already parse their windows for staleness checks. Those parsers report what they compute rather than being duplicated.

**Placeholder dates.** `priceValidUntil` is also used to mean "no promotion": pharmacy4pets states `2027-12-31`. A schema.org end date more than 90 days out is treated as a placeholder and stores no window. The cutoff applies only here — the structured sources state real campaign windows and are stored as given.

## 4. Rendering

The shops table (`ShopsRelationManager`) renders the window as a description under the price, beside the existing unit price and conditional offer:

- Running: `Bonus until 6 Sep` — or `VOOR 1.69 until 6 Sep` when the source supplied a label.
- Not started: `Bonus from 8 Sep` — a stored window whose start is in the future. Without this state a future promotion falls through to the ended branch and reads as "ended" before it has begun.
- Ended: `Bonus ended 6 Sep`, so a price still showing the promotional number reads as suspect until the next check.
- Absent: nothing.

`isRunning()` alone cannot pick between these: it is false both before the start and after the end. The renderer compares against `startsAt` and `endsAt` separately.

Dates render as short day-and-month, **converted to Europe/Amsterdam before formatting**. `config('app.timezone')` is `UTC`, and a window starting 8 September is stored as the instant `2026-09-07 22:00 UTC`; formatting that instant as-is prints "from 7 Sep" — a day early, every time, for every date-only source. Storage tests cannot catch this, so the assertion belongs in the `ui` phase.

The word "Bonus" is not hard-coded per shop: it is the generic label the UI uses when the source gave none.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Source states an end date but no start | Stored with `promotion_starts_at` null; renders as "until <date>". Covered by `foundation` Tests. |
| Source states a start date in the future | Stored as given; the row renders "from <date>", not "ended". Covered by `foundation` and `ui` Tests. |
| Source states a start after its end | Nothing stored — a source bug, not a window to guess at. Covered by `foundation` Tests. |
| Source states a window with no end date | Not stored — the spec has nothing to show. Covered by `foundation` Tests. |
| AH answers `isBonus: false` on a shop that had a window | Window cleared: the source reads promotion fields and found none. Covered by `sources-structured` Tests. |
| checkjebon or Lidl checks a shop that had a window from another source | Window untouched — no promotion concept, so not authoritative. Covered by `sources-structured` Tests. |
| End date passes with no check since | Renders "ended <date>"; the price is corrected on the next scheduled check. Covered by `ui` Tests. |
| A date the source states cannot be parsed | No window stored, and an existing window is cleared when that source is authoritative — an unreadable date is not evidence the old promotion still runs. Covered by `sources-structured` Tests. |
| Schema.org `priceValidUntil` is a far-future placeholder (pharmacy4pets: 2027-12-31) | Beyond 90 days it stores no window, so the row shows no promotion. Fails once the placeholder itself falls inside 90 days — see Assumptions and Open Questions. Covered by `sources-jsonld` Tests, including both sides of the boundary. |
| A JSON-LD page stops stating `priceValidUntil` while a window is stored | Cleared: JSON-LD is authoritative whenever an offer supplied the price. Covered by `sources-jsonld` Tests. |
| DekaMarkt's offer window has closed, so the adapter prices `normalPrice` | No window reported, and a stored one is cleared — the normal price is not a promotion. Covered by `sources-structured` Tests. |
| AH answers `isBonus: false`, omitting the date keys | Cleared: authority keys on `isBonus`, not on the dates. Covered by `sources-structured` Tests. |
| A source states a date-only value, another an offset timestamp | Date-only is read as a Europe/Amsterdam day boundary; a timestamp with an offset keeps the instant it states. Covered by `foundation` Tests. |
| Schema.org `priceValidUntil` is a real weekly end (spar.nl: 2026-09-03) | Stored like any other window. Covered by `sources-jsonld` Tests; rendering is source-agnostic and covered by `ui`. |
| Dirk states a window whose offer price is not the one shown | The window belongs to the price record the adapter used; if that record was rejected, no window is stored. Covered by `sources-structured` Tests. |
| A promotion that ends today | `isRunning()` true until 23:59:59 Europe/Amsterdam, so "until today" does not vanish at midnight UTC. Covered by `foundation` Tests. |
| A date-only start renders near midnight | The renderer converts to Europe/Amsterdam first, so an 8 September start never prints "from 7 Sep". Covered by `ui` Tests. |
| Dirk's payload holds a price record for a different offer than the JSON-LD price | No window: the record's `offerPrice` must equal the tracked price. Covered by `sources-structured` Tests. |
| DekaMarkt accepts two price records with equal prices but different windows | No window reported; the price stands. Covered by `sources-structured` Tests. |
| A shop has a promotion window and a conditional offer at once | Both render: the compact column joins its description lines, and the two are different statements — how long this price lasts, versus a price some shoppers can pay. Covered by `ui` Tests. |
| Shop check fails (parse error, block, throttle) | No write: only an `Ok` outcome touches the window, as with every other shop field. Covered by `foundation` Tests. |

## Implementation

### Phase 1: Store the window (Priority: HIGH)

**ID:** foundation · **Depends:** none

- [x] Migration appending `promotion_starts_at`, `promotion_ends_at`, `promotion_label` to `shops` — nullable, appended at the end per the migration convention.
- [x] `App\PriceAdapters\PromotionWindow` value object with `isRunning()` — mirrors `ConditionalOffer`.
- [x] `ShopSnapshot::$promotionWindow` + `$promotionWindowAuthoritative`.
- [x] `ShopSnapshot::with(...)` copy helper, and switch `DirkAdapter` and `SparAdapter` to it — both rebuild the JSON-LD snapshot field by field today (`DirkAdapter.php:47`, `SparAdapter.php:49`), so every field added to the snapshot has to be hand-copied in two places or it is silently dropped. This is what keeps phases 2 and 3 from colliding.
- [x] `CheckShopPrice` outcome keys and persistence — write on `Ok` only, clear when authoritative and absent.
- [x] `Shop::promotionWindow()` accessor and casts — returns the window even when it has ended.
- [x] Tests — a window round-trips; an end-only window stores; an open-ended window does not; a start after the end does not; an authoritative empty result clears; a non-authoritative source leaves it; a failed check writes nothing; a promotion ending today is still running at 23:00 Europe/Amsterdam; a date-only value lands on the Amsterdam day boundary while an offset timestamp keeps its instant; `with()` carries every snapshot field through Dirk and Spar.

### Phase 2: Structured sources (Priority: HIGH)

**ID:** sources-structured · **Depends:** foundation

- [x] `AhApiSource` — map `bonusStartDate` / `bonusEndDate` / `bonusMechanism`, read only when `isBonus` is true; authoritative whenever the card carries the `isBonus` key at all, true or false. Never key authority on the date fields: a normal product answers `isBonus: false` and omits them.
- [x] `DirkAdapter` — locate the Nuxt price record for the URL's product id (the adapter locates none today) and read `startDate` / `endDate` from it, only when its `offerPrice` equals the JSON-LD price the adapter reports.
- [x] `DekaMarktAdapter` — report the window `offerIsRunning()` already parses instead of discarding it, on the offer-price branch only, and report none when several accepted records disagree on the window.
- [x] `AldiAdapter` — report the window `bound()` already parses.
- [x] Tests — per source: a live-shaped payload yields the right window; a payload without a promotion clears it; an unparseable date clears rather than keeps; AH clears on `isBonus: false` with the date keys absent; DekaMarkt reports no window when it falls back to `normalPrice`; DekaMarkt reports none when two accepted records price the same but state different windows; Dirk reports none when the matching record prices a different offer than the JSON-LD price.

### Phase 3: Schema.org sources (Priority: MEDIUM)

**ID:** sources-jsonld · **Depends:** foundation

Runs alongside `sources-structured`, but only because `foundation` removed the collision. `DirkAdapter` and `SparAdapter` rebuild the JSON-LD snapshot field by field, so without the `with()` helper both phases would have to edit those two files — this phase to propagate the new JSON-LD window, the other to add Dirk's own. With `with()` in place this phase touches `JsonLdAdapter` alone, and builds its JSON inline the way `JsonLdAdapterTest` already does. Keep it that way: a fixture added to `tests/Pest.php` here would collide with the other phase's edits to `dirkPage`, `dekaMarktPage`, `aldiPage` and `ahApiProductFakes`.

- [x] `JsonLdAdapter` — map `offers.priceValidUntil` (and `validFrom` when present) into a window; authoritative whenever the selected offer supplied the tracked price, so an offer that no longer states the field clears the stored window.
- [x] Ignore an end date more than 90 days out — the placeholder rule; the constant lives beside the mapping, with the pharmacy4pets example and the date the heuristic expires.
- [x] Tests — a spar-shaped offer (`2026-09-03`) yields a window; a pharmacy4pets-shaped one (`2027-12-31`) yields none; 89 days out yields a window and 91 does not; an offer that stops stating the field clears the stored window; a malformed date clears it.

### Phase 4: Show it (Priority: HIGH)

**ID:** ui · **Depends:** foundation, sources-structured

- [x] Render the window under the price in `ShopsRelationManager`, alongside the unit price and conditional offer. The renderer reads the shop's columns and never the source, so one implementation covers every phase-2 and phase-3 source.
- [x] Running / not-started / ended / absent states, with the source's label when it gave one.
- [x] Tests — each of the four states renders as specified, including a future window rendering "from" and never "ended"; a date-only Amsterdam start renders its own day rather than the day before; and a shop carrying both a promotion window and a conditional offer shows both.
- [ ] Eye-verify in a browser against the real AH shop, which is in a bonus until 6 September: the row shows the window, the layout survives on a phone width, and no console errors appear. **BLOCKED — dipcatch.test has no logged-in session and this assistant does not enter credentials. Everything else is ready: the AH shop stores its window (ends 2026-09-06 23:59:59, label "VOOR 1.69") and the page to open is /app/products/01a059c1-5380-707a-ae0b-d3724ed2f111. Log in and the check takes a minute.**

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **AH returns `bonusStartDate` / `bonusEndDate` whenever `isBonus` is true** — if a bonus product answers without dates, the gate is wrong and storing nothing is not obviously right; report the payload.
2. **Dirk's Nuxt price record carries `startDate` / `endDate` beside `offerPrice`** — the phase reads the window off the record the adapter already selected. If the shape moved, report rather than hunting for dates elsewhere in the payload.
3. **DekaMarkt and Aldi already parse their windows** — the phase reports what they parse. If those parsers no longer exist or changed meaning, stop: re-deriving a window from a different field is a new decision.
4. **A schema.org end date within 90 days means a real promotion** — the placeholder rule assumes shops do not state genuine multi-month promotions in `priceValidUntil`, and that placeholders sit far enough out to be filtered. If a tracked shop runs a real promotion longer than 90 days, or a placeholder is found inside the window, report rather than widening or narrowing the cutoff silently.
5. **Dirk's Nuxt payload holds a price record whose `offerPrice` equals the JSON-LD price** — the match is what ties the window to the tracked price. If no record ever matches, report it: reporting an unmatched record's window is exactly the failure this rule exists to prevent.
6. **`DekaMarktAdapter::currentPrice()` still chooses between the offer and normal price** — the window is reported only on the offer branch. If that selection moves or disappears, stop: reporting a window without knowing which price it belongs to is how the ordinary price gets labelled a promotion.
7. **The promotional price is already the tracked price** — this whole spec assumes the window annotates `current_price`. If a source turns out to state a window for a price the app does not track, that is a conditional offer, not a promotion window.

---

## Open Questions

1. **What happens when the 90-day placeholder rule expires?** pharmacy4pets states `priceValidUntil: 2027-12-31`. The cutoff is measured from the check date, so that placeholder passes the filter from about 2 October 2027 and the shop starts advertising its ordinary price as a promotion. No public signal separates a placeholder from a real date, so the options are: accept it and revisit before then; drop schema.org windows entirely and keep only the four structured sources; or require a corroborating discount signal on the page, which would also drop spar.nl unless its markup carries one. Not decided.

2. **Should the public product page show the window too?** `resources/views/public/product.blade.php` renders shops for a shared product. Showing "until 6 Sep" there helps a reader judge a shared price, but it also dates the page. Not in scope until answered.

---

<!-- ## Resolved Questions
1. **{Original question?}** **Decision:** {What was decided.} **Rationale:** {Why.}
-->

## Findings

- **Eloquent stored the wall clock, not the instant.** The first live run wrote every window two hours late: an Amsterdam `23:59:59` landed in the column as `23:59:59 UTC`, which reads back as 01:59:59 the next day. Eloquent formats a date to store it, and formatting prints the zone the object carries. Instants are now converted with `->utc()` at the persistence boundary, for the conditional-offer columns too — the same latent bug was already there, waiting for a source to populate them. Caught by the live run, not by the phase tests, which is why `PromotionWindowTest` gained a round-trip assertion.
- **`AldiOffer` extracted.** Reporting Aldi's window pushed `AldiAdapter` to cognitive complexity 44. The price-validity logic moved to `AldiOffer::price()` / `::window()`, which also makes explicit that the window reported is the one the price passed.
- **Dirk needed a price record it never had.** As the spec's round-three review predicted, the adapter located no price record — it takes the price from JSON-LD. `promotionWindow()` now finds the record for the URL's product id and uses it only when its `offerPrice` equals the reported price.
- **`DutchDate` added** (`app/Support/DutchDate.php`) so AH, Dirk and the schema.org mapping read date-only values the same way: a bare date is a Dutch retail day, a value carrying a time keeps its instant.
- **Phase 4 eye-verify is NOT-VERIFIED.** The local app logged the browser session out and entering credentials is not something this assistant does. Rendering is covered by seven Filament tests, including one that fails with "Bonus from 7 Sep" if the Amsterdam conversion is removed. The browser pass still needs doing by hand.
