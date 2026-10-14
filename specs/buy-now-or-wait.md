# Buy Now or Wait

<!-- spec:planned-at ef44986bdfaff1db894dcda7ff081d55a36f626a 2026-10-13 -->

## Overview

Today DipCatch tells a user what a product costs now. This feature also tells them whether that is a good price. Each shop on the product page gets a short price verdict, "Good price", "Normal price" or "High price", measured against that shop's own price over the last 90 days. A "Lowest in 90 days" badge marks a shop at its low. A later phase adds the offer rhythm: "Usually on offer every ~6 weeks. Last offer 5 weeks ago."

The feature describes the past. It never forecasts and never shows a confidence figure. Research (2026-10-12) found no price tracker that publishes an audited accuracy figure for a forecast, and Google Shopping keeps its own price insights descriptive. A wrong "wait" costs the user money and costs DipCatch their trust.

## Assumptions

- The verdict is per shop, against that shop's own readings. The verdict at the product level is the verdict of `HeadlinePrice::buyableShop()` (`app/Support/HeadlinePrice.php:118`), the shop the headline price already names. Do not choose the shop again from the display mode. When `buyableShop()` is null (the headline falls back to a sold-out or switched-off shop), the product shows no verdict.
- The verdict compares a shop's pack prices with each other. A shop is compared only with itself, so a price per unit adds nothing, as long as the pack size stays the same. `price_checks` stores no pack size, so a check cannot show the size it was read at. Phase `verdict` adds that column (section 1).
- A multi-buy offer (for example 1+1) counts at its price per item, the same as `price_checks.price` stores it today.
- DipCatch's own readings are the only reference. The shop's crossed-out "was" price is never used: the ACM found that 75% of the sellers it checked showed discounts incorrectly.
- Phase `rhythm` uses the promotion columns on `price_checks` that shipped in `ef44986` (2026-10-13). Rows from before that deploy have a null window even when an offer ran, so the rhythm reads only readings from the deploy on (the **rhythm cutoff**, section 3).
- English only, as for the price changes list: `lang/nl.json` holds marketing keys only.

---

## 1. Data

- `price_checks` holds one row per shop per check: every 24 h on Free, every 6 h on Pro. `RecheckActiveShopsCommand` adds a check when a promotion starts, and, for a bundle only, when it ends (`dueBundleBoundary()`, `dueDealStart()`). Checks older than 365 days are deleted, except the newest 50 per shop and checks a drop event uses (`PruneOldHistory::pruneChecks()`). `history_kept_from` does not keep checks; it keeps drop events and cheapest-history segments. The verdict needs 90 days and the rhythm 365 days, so this is enough.
- **Evidence readings.** A reading counts when all of these are true:
  - it is in the `PriceCheck::eligible()` scope: successful, with a price, and not out of stock;
  - `shelf_inherited` is not true (the prices were read on the page, not carried over);
  - `consumer_price_issue` is null (a shopper can pay the price);
  - `seller` is the same as on the newest reading (a marketplace offer from another seller is another offer);
  - the shop is not a reference shop (`Shop::isReference()`);
  - it is later than the shop's `repointed_at`.

  These rules look like the rules in `PriceBeforeDiscount::isEvidence()` (`app/Support/PriceBeforeDiscount.php:185`), but they are not the same. That check also needs `claim_read = true` and `shelf_inherited = false`. Both columns exist only from 2026-09-30, and `claim_read` is true only for readers that can read a "was" price. With those two rules, a shop that never shows a "was" price would have no evidence at all. So the verdict uses `null` as "not inherited" and does not read `claim_read`. Do not share code with `PriceBeforeDiscount` where the rules differ.
- **Pack size per reading (new).** Add `pack_quantity` and `pack_unit` to `price_checks`. Both write sites (`CheckShopPrice::persist()`, `AttachShop::record()`) store the shop's pack size as it is after the check. Call the deploy of this column the **size cutoff**. A reading counts for the verdict when one of these is true:
  - it is from on or after the size cutoff, and its pack size is the same as the shop's pack size today (two null sizes are the same: no size was known for either);
  - it is from before the size cutoff (a legacy row with no stored size), and today's pack size is the same as on the oldest reading after the cutoff. If the size changed after the cutoff, legacy rows do not count.

  Accepted risk: a size change before the cutoff stays invisible until those readings leave the 90-day window. Ship this column early, as its own commit, for the same reason as the promotion columns.
- `price_checks.promotion_starts_at`, `promotion_ends_at` and `promotion_label` (new) say which window the shop ran under at each check. A check with a running window, a live bundle, or a claimed regular price above the price is "on offer". `NormalPrice::isOnOffer()` (`app/Support/AlertSuggestion/NormalPrice.php:84`) has this rule for the `shops` row. Use the same rule on a check row.

## 2. The Price Verdict (phase `verdict`)

For one shop:

1. Take the evidence readings of the last 90 days (section 1). The price of a reading is `price_checks.price`.
2. **Today's price** is the shop's `current_price`. The shop gets a verdict only when all of these are true; otherwise it shows nothing:
   - the newest reading of the shop, of any status, is an evidence reading (so the newest reading did not fail, was not out of stock, and was not from another seller);
   - that reading is less than 3 days old;
   - its `price` equals the shop's `current_price` (a failed check can move `current_price` without a new reading, for example when a bundle expires: `ResolvedBundlePricing::expiredFailureUpdates()`);
   - the newest reading is later than every boundary of the shop's stored promotion window that is already past: `promotion_starts_at` when it is in the past, and `promotion_ends_at` when it is in the past. Between a boundary and the next check, `current_price` is the price from before it. After the end, it can still be the offer price: `Shop::liveBundleOffer()` (`app/Models/Shop.php:415`) only checks that the bundle matches `current_price`, not that it still runs. After the start of an announced deal, it is still the price from before the deal: `AhApiSource` stores that price on purpose, and `RecheckActiveShopsCommand::dueDealStart()` asks for a new check. The first reading after the boundary restores the state, even when a source without the concept keeps the ended window on the shop.

   The historical evidence of step 1 and this current-state rule are separate. A past reading that fails a rule only drops out of the history; the newest reading that fails a rule removes the verdict.
3. Build one price per day: the lowest evidence reading of that day, by calendar day in `Europe/Amsterdam`, the same day boundary that the promotion windows use. Days without a reading are left out; they are not filled in.
4. The verdict needs both of these. Otherwise the shop shows no verdict, no badge and no range: nothing at all.
   - at least 30 days with a reading;
   - no gap longer than 7 days between the first reading in the window and today.
5. From the daily prices, take the 25th percentile (P25), the median and the 75th percentile (P75).
6. Compare today's price:
   - **Good price:** at or below P25, and at least 5% below the median;
   - **High price:** above P75, and at least 5% above the median;
   - otherwise: **Normal price**.

   The 5% margin matters. Most products keep one regular price on most days, so P25, the median and P75 are often the same number. Without the margin, a change of a few cents, or the regular price itself, would get "Good price" or "High price".
7. **Lowest in 90 days:** today's price is at or below the lowest daily price in the window, and the first evidence reading in the window is at most 3 days after the window start. A shop with only 40 days of history must not say "90 days".

Compute this at read time from `price_checks`, in one query per product for all its shops. The index `price_checks_shop_id_checked_at_index` on `(shop_id, checked_at)` serves the window. Do not store the result.

If the query is too slow for the product list (phase `cards`), cache it per product for at most 1 hour. Also clear the cache on every change that moves the result before that: `CheckShopPrice::persist()`, `AttachShop`, a repoint (`Shop` sets `repointed_at`), and a shop removed or switched off. Do not cache the time-based rules (the 3-day freshness and the window-boundary guard): apply them to the cached result on each read, so a window that ends inside the hour removes the verdict at once.

## 3. The Offer Rhythm (phase `rhythm`)

For one shop:

1. Read only evidence readings with `checked_at` on or after the later of two dates: the **rhythm cutoff** (the deploy of `ef44986`, 2026-10-13) and 365 days ago. A reading before the cutoff has a null window even when an offer ran, so it can neither start, end nor prove an offer. The 365-day bound must be in the query: pruning keeps the newest 50 checks of a shop and checks that events use, so an inactive shop can keep older readings.
2. An **offer** is a run of consecutive evidence readings that are on offer. A reading is on offer when one of these is true:
   - `checked_at` is between its `promotion_starts_at` (or no start) and its `promotion_ends_at`;
   - it has a bundle (`bundle_quantity` is not null; the check stores a bundle only when the tracked price came from it);
   - its `claimed_regular_price` is above its `price`.

   This is `NormalPrice::isOnOffer()` (`app/Support/AlertSuggestion/NormalPrice.php:84`) applied to a check row. A stored window that has ended does not count, so a stale window from a source without the concept does not make an offer. A run ends at the first reading that is not on offer.
3. **Gaps.** A gap is more than 3 days between two evidence readings. An offer is **certain** only when there is no gap inside it, no gap just before its first reading, and no gap just after its last reading. An offer next to or across a gap is **uncertain**: the gap could hide its true start or end, or join two offers into one.
4. **Interval:** the time from the start of one certain offer to the start of the next certain offer, with no gap and no uncertain offer between them. Any other pair gives no interval.
5. Show the rhythm only when the shop has at least 3 certain offers that ended, and at least 2 intervals. "Ended" means that an evidence reading after the offer, with no gap, is not on offer.
6. The text uses the median interval, rounded to whole weeks, with a minimum of 1: "Usually on offer every ~6 weeks." Add "Last offer N weeks ago", or "Last offer this week" when that is less than 7 days ago. The end of the last offer is the first evidence reading after it that is not on offer. Only when the newest offer of the shop is a certain offer that ended does this text show. When the newest run has no observed end (an outage after it, or no reading since), show the rhythm sentence without the "Last offer" part.
7. **"On offer now"** replaces the "Last offer" part only when the shop is on offer now: `NormalPrice::isOnOffer()` is true for the `shops` row, and the shop passes the current-state rule of section 2, step 2, which includes the window-boundary guard. A historical run never says "On offer now" on its own: an offer whose window has ended, or a shop whose newest reading is stale, shows the rhythm sentence, with the "Last offer" part only under rule 6.
8. When the intervals vary a lot (the longest interval is more than twice the shortest), show "On offer now and then" with no number.
9. The checks are every 6 h on Pro, and every 24 h on Free, which covers the time before an upgrade. An offer shorter than the check interval can be missed. The rhythm then shows fewer offers and longer intervals, never more offers. This is accepted.

## 4. Where It Shows

| Place | Phase | What |
|---|---|---|
| Product page, per shop row | `verdict` | Verdict badge beside the price; "Lowest in 90 days" badge |
| Product page, per shop row | `rhythm` | The rhythm line under the price, beside the promotion window line |
| Product page, per shop row, a new tooltip on the verdict badge | `verdict` | P25 to P75 as "Usually €X to €Y for this pack", only when P25 and P75 differ. A tooltip on the badge works for every product. The existing price tooltips (`pack-price-hint`, `unit-price-hint`) render only on products compared per unit (`product-show.blade.php:285-301`); a product priced per pack renders `<x-shop-price>` with no tooltip. The words "for this pack" keep the range from being read as a price per unit. |
| Product cards and product list | `cards` | The verdict of the leading shop only |
| Dashboard "Where to shop this week" and the "Best buys here" filter | `cards` | Count a product as a best buy only when buying it now is worth it, by Resolved Question 6. A product at its usual price, or above it, is not a reason to go to a shop: the shopping list serves "where is it cheapest if I need it now". Today `DashboardDigest::itemsByHost()` counts every product whose card leads with the shop, and a trip needs 2 of them, 1 on offer. |
| Price alerts: the daily digest e-mail and push | `cards` | "Lowest in 90 days" when it held at the moment the alert fired. Store it as a boolean on the event row (`price_drop_events`, `target_price_events`) when the event is written, against the event's shop and price. Never compute it when the e-mail or push renders: push snapshots its price and shop (`PriceDropNotification`), and the digest shows past events, so a later price change would describe another offer. |

The public share page and the MCP tools are out of scope for this spec.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| A shop added less than 30 days ago | Nothing shows. Phase `verdict`. |
| Checks failed for more than 7 days in the window | Gap rule: no verdict. Phase `verdict`. |
| The newest good reading is more than 3 days old | Nothing shows. Phase `verdict`. |
| The pack size changed in the window | Only readings at today's pack size, or with no stored size, count. Phase `verdict`. |
| A shop repointed to another URL | Only readings since the repoint count. Phase `verdict`. |
| The price has been the same for 90 days | P25, the median and P75 are equal; the 5% margin gives "Normal price". Phase `verdict`. |
| The price moved by a few cents | Within 5% of the median: "Normal price". Phase `verdict`. |
| A shop with 40 days of history at its lowest price | A verdict, but no "Lowest in 90 days". Phase `verdict`. |
| A reference shop | No verdict. Phase `verdict`. |
| A marketplace offer whose seller changed | Only readings from today's seller count. Phase `verdict`. |
| A shop whose reader cannot read a "was" price | Its readings still count; `claim_read` is not used. Phase `verdict`. |
| Out-of-stock readings | Not evidence; they are left out. Phase `verdict`. |
| A multi-buy offer the user would need to buy 2 of | The price per item counts, as today. The badge text does not change. Phase `verdict`. |
| A promotion with no stated window (only a claimed regular price) | Counts as on offer for the rhythm. Phase `rhythm`. |
| An offer that is still running at the window start | It is not complete; do not use it for an interval. Phase `rhythm`. |
| A stored promotion window that has ended, on a source without the concept | Not on offer. Phase `rhythm`. |
| An offer seen before and after a 5-day check outage | Uncertain: it counts for no interval and as no ended offer. Phase `rhythm`. |
| A reading from before the rhythm cutoff | Ignored by the rhythm. Phase `rhythm`. |
| The last on-offer reading is 10 days old, the window ended | Never "On offer now". The newest run has no observed end, so the rhythm sentence shows without the "Last offer" part. Phase `rhythm`. |
| A bundle window ended, and no check ran since | No verdict and no "On offer now" until the next check, also from a cached result. Phase `verdict`, `rhythm`. |
| An announced deal started, and no check ran since | No verdict and no "On offer now" until the next check. Phase `verdict`, `rhythm`. |
| A source without the concept keeps an ended window on the shop | The first reading after the end restores the verdict. Phase `verdict`. |
| An inactive shop keeps offers older than 365 days | Ignored: the query bounds the window at 365 days. Phase `rhythm`. |
| The newest reading is out of stock, the one before it is a deal | No verdict: the current-state rule fails. Phase `verdict`. |
| A failed check expired a bundle and moved `current_price` | No verdict until a reading at the new price. Phase `verdict`. |
| Pack size became unknown, then a new size | Only readings at today's size count; the unknown-size readings do not. Phase `verdict`. |
| The headline shop is sold out (fallback headline) | No verdict on the card. Phase `cards`. |
| The best-value shop leads with its pack price | The card shows that shop's verdict, from `buyableShop()`. Phase `cards`. |
| The price changed between an alert and its digest | The alert shows the stored boolean from the moment it fired. Phase `cards`. |
| A Free user (90-day chart) | Sees the verdict, the same as Pro. Phase `verdict`. |
| A Free user and the rhythm | No rhythm line, and no Pro note in its place. Phase `rhythm`. |
| A Pro user downgraded to Free | The rhythm line goes; the verdict stays. Phase `rhythm`. |

## Implementation

### Phase 1: The Price Verdict (Priority: HIGH)

**ID:** verdict · **Depends:** none

- [x] Migration: `pack_quantity` and `pack_unit` on `price_checks`, nullable, guarded, the same types as on `shops`; written by both write sites. Ship it first, as its own commit
- [x] The evidence rules of section 1, as a scope on `PriceCheck` (`verdictEvidence()`)
- [x] A class that returns the verdict, P25, the median, P75 and "lowest in 90 days" per shop, for all the shops of a product in one query
- [x] Badges on the product page shop rows, with the range tooltip on the badge
- [x] Tests:
  - each verdict at its boundary, with the 5% margin;
  - not enough days, a long gap and a stale newest reading;
  - the newest reading out of stock, failed, or from another seller;
  - `current_price` moved by a failed check (bundle expiry);
  - a window that ended, or a deal that started, before any new check gives no verdict; the next reading restores it, also with an ended window kept on the shop;
  - "Lowest in 90 days" with full and with short history;
  - pack-size change; legacy rows with and without a size change after the cutoff; an unknown size followed by a new size;
  - repoint, another seller and a reference shop;
  - flat price;
  - out of stock and an inherited price left out;
  - a shop without `claim_read` still gets a verdict;
  - a query count for a product with many shops
- [x] Eye-verify: light, dark, 390 px; a shop with each verdict and one with too little history

### Phase 2: Cards, List and Alerts (Priority: MEDIUM)

**ID:** cards · **Depends:** verdict

- [x] The verdict of `HeadlinePrice::buyableShop()` on the product cards and the product list, without a query per card
- [x] Migration: a nullable boolean `lowest_in_90_days` on `price_drop_events` and `target_price_events`, set when the event is written
- [x] "Lowest in 90 days" in the digest e-mail and push, from that column
- [x] "Where to shop this week" and "Best buys here" (`DashboardDigest::trips()`, `bestBuyIds()`) count only the products worth buying now, by Resolved Question 6
- [x] Tests:
  - the card shows the verdict of the buyable headline shop;
  - pack winner and unit winner differ;
  - no buyable shop gives no verdict;
  - a fixed number of queries for the list;
  - a product at its usual price is not a best buy in a trip, and a trip that keeps fewer than 2 best buys is not shown;
  - the alert text, and a price change between the event and the digest;
  - the cache: cleared by a new check and by a change to the shop; a time rule still applies to a cached history; the alert flag reads past the cache

### Phase 3: The Offer Rhythm (Priority: MEDIUM)

**ID:** rhythm · **Depends:** verdict. Start only when production has enough data. Before building, query production for the number of shops with at least 3 complete offers since `ef44986`, and report it. A shop with an offer every 4 weeks needs about 10 weeks before its third offer ends, so expect this to be no earlier than late December 2026.

- [ ] An on-offer rule for a check row, shared with `NormalPrice::isOnOffer()`
- [ ] A class that finds offer runs and intervals per shop
- [ ] A `price_rhythm` key in `config/plans.php` (Free false, Pro true) and `Entitlements::allowsPriceRhythm()`, read on the product owner's plan, as the price changes list does
- [ ] The rhythm line on the product page shop rows, for Pro only
- [ ] Tests:
  - 3 regular offers give "every ~N weeks";
  - fewer than 3 give nothing;
  - a running offer gives "On offer now";
  - irregular intervals give "now and then";
  - an offer next to a gap is uncertain and gives no interval;
  - readings before the rhythm cutoff, and readings older than 365 days, are ignored;
  - a newest run with no observed end shows no "Last offer" part;
  - "On offer now" needs the current-state rule; an ended window or a stale reading never shows it;
  - a Free owner sees no rhythm line

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **One query per product can compute the percentiles for all its shops** (Postgres `percentile_cont` over daily minimums). If it needs a query per shop, stop and decide on a stored daily summary.
2. **`price_checks.price` is the price a shopper pays per item.** If a source stores a bundle total there, the verdict is wrong for that source.
3. **Most shops have enough evidence readings.** If the rules in section 1 leave fewer than half of the active shops with a verdict on the dev data, stop and report which rule removes them.

---

## Open Questions

None.

---

## Resolved Questions

1. **Forecast or describe?** **Decision:** Describe only; no forecast and no confidence figure. **Rationale:** No tracker publishes an audited forecast accuracy, and a wrong "wait" costs the user money.
2. **Free or Pro?** **Decision:** The verdict for everyone; the offer rhythm for Pro only. **Rationale:** User choice. The verdict uses the 90 days a Free user already sees; the rhythm needs up to 365 days.
3. **Too little history: say so, or show nothing?** **Decision:** Show nothing, in every place. **Rationale:** User choice: a new product must not look broken.
4. **Wording?** **Decision:** "Good price", "Normal price", "High price". **Rationale:** User choice: it says what the price means for the user.
5. **Store offer history now?** **Decision:** Yes, shipped in `ef44986` on 2026-10-13. **Rationale:** Each week without it is a week of rhythm data lost.
6. **Which products count as a best buy in "Where to shop this week"?** **Decision:** A product counts when its headline shop is on offer (a running deal or a visible drop), or, for a product tracked at more than one shop, when it is at the alert price or the shop has "Good price". A product with "Normal price", "High price" or no verdict yet counts only when it is on offer. A product tracked at one shop counts only when it is on offer. **Rationale:** User report and choice (2026-10-13): the dashboard sent the user to a shop for products at their usual price. The shopping list answers "where is it cheapest if I need it now"; the dashboard only says where a trip is worth it.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

**Phase `verdict` (2026-10-13):**

- **Plain rows, not models.** With models, 10 Pro shops (3,600 readings) took about 1.2 s in the test environment, almost all of it in the attribute casts. The verdict reads plain rows (`toBase()`) through `App\Support\VerdictReading`. Phase `cards` changed the query further; see below.
- **STOP 1 holds.** A fixed number of queries per product reads the history; PHP builds the percentiles. `percentile()` interpolates linearly, as `percentile_cont` does, so a later move to SQL gives the same numbers.
- **Size cutoff:** `VerdictHistory::SIZE_CUTOFF` is 2026-10-14 00:00 Europe/Amsterdam, the first midnight after `482eef3` was pushed. The exact deploy time is not known in the code. Only a row with no stored size uses the cutoff; a row with a size uses that size whatever its date.
- **No reading after the cutoff yet:** legacy rows count. Nothing can show a size change, and without this rule no shop gets a verdict until the first check after the deploy.
- **Deviation: "Lowest in 90 days" also needs today's price below the median.** Without it, a price that never moved is always "at or below the lowest daily price", and every flat product showed the badge beside "Normal price". The user kept this rule on 2026-10-14.
- **Badge icons (2026-10-14, user request):** the three verdicts and "Lowest in 90 days" were hard to tell apart from each other and from the green "In stock" badge. Each verdict badge now has a micro icon (trending down, equals, trending up), and "Lowest in 90 days" is blue with a trophy. Stock in the narrow layout is a check icon with plain text, the same as the wide stock column. The icons made the table 11 px wider than its card at 390 px, so the "Actions" header is for screen readers only below the `@2xl` container width.
- **STOP 3 on dev data:** 0 of 328 active priced shops get a verdict today, because the dev database has no check after 2026-10-09 (the 3-day freshness rule). As of 2026-10-09 12:00, 322 of 328 get one. All 322 are "Good price": the dev history is a seeded falling trend, not real prices. The slowest product took 73 ms.
- **Eye-verify:** a seeded product in the `screenshots` scenario of `tools/changelog-media/seed.php` (`pastaId`) has one shop per verdict and one with 12 days of history. Checked in light and dark, at 1280 px and 390 px: each badge shows, the new shop shows nothing, the tooltip reads "Usually €1.69 to €1.79 for this pack", no console errors, no horizontal overflow.

**Phase `cards` (2026-10-13):**

- **Two queries, not one.** `PriceVerdicts::forShops()` now reads one row per shop, day, seller and pack size (`min(price)`, first and last `checked_at`), grouped in SQL with the `PriceCheck::verdictEvidence()` scope, and a second query for each shop's newest reading of any status (`distinct on`). The repoint rule is a `not exists` on `shops` in both. The count stays 2 whatever the number of shops, which is what STOP 1 protects. `VerdictReading::fromNewestRow()` repeats the scope's rules for that one row in PHP.
- **Speed:** 10 Pro shops (3,600 readings) take about 19 ms, 250 Pro shops (90,000) about 420 ms in the test environment. The dashboard reads the history only for trip products that nothing else qualifies (not on offer, not at the alert price, sold at more than one shop), and the product list only for the page.
- **The "Lowest in 90 days" flag** is computed with `PriceVerdicts::isLowestIn90Days()` for the alert's shop when the event row is written: the triggering check's shop for a drop, the shop that reached the target for a target alert. The push notifications get it as a constructor argument from the event row. The property is not readonly and has a default, so a payload queued before the deploy still renders.
- **The product card** shows the verdict badges without the range tooltip: the card already sits inside a tooltip of its own. It shows on the product list and on the dashboard's "At your alert price" and "Biggest drops" cards.
- **Interpretation of Resolved Question 6:** "a product with no verdict does not count" means no verdict gives no reason of its own. Such a product still counts when it is on offer, or at the alert price at a product with more than one shop. The trip rule that needs at least one offer per shop stays.
- **Copy:** the "Where to shop this week" line now reads "Shops where several of your products are cheapest and worth buying now, and at least one is on offer."
- **Eye-verify:** the product list card shows "Good price" and "Lowest in 90 days" under the price, in light at 1280 px and dark at 390 px, with no overflow and no console errors.

**Closeout review (2026-10-14):**

- **Size baseline:** the first reading after the size cutoff of any status, as section 1 says, not the first evidence day. The second query now reads it per shop with `LEFT JOIN LATERAL ... LIMIT 1`, together with the newest reading, so it walks the index from the end it needs instead of the whole window. The window start and the cutoff are bound as ISO 8601 strings with their offset: Laravel sends a Carbon binding without one, and Postgres would read it in the session time zone, which follows `APP_TIMEZONE`.
- **At the alert price** counts on the dashboard only when the headline shop's own price meets the pack or unit target. A product can be at its alert price at a shop the headline does not lead with.
- **Cache per shop, not per product (deviation).** The dashboard read verdicts twice per render (the cards, and the trips), and the "Best buys here" filter rebuilt the trip set for every list render. `PriceVerdicts::forShops()` now caches each shop's history for an hour, as scalars (`VerdictHistory`), because the cache store unserializes no objects. All shops come from one `Cache::many()`, and the shops not in it from the same two queries, so a warm read costs no database query on a Redis store. The key is per shop because the verdict belongs to a shop: which shop a product leads with is still decided live, so a shop switched off or removed needs no product key cleared.
- **What clears it:** a new `PriceCheck` and any update of the `Shop` give the shop a new random generation after the commit. An entry stores the generation and the pack size it was computed for, so a reader that queried before the commit, or that holds the shop from before a size change, cannot put back the old history. A random value, not a counter: the database store does not create a missing counter, and a counter that expires or is set back can repeat a value. That covers `CheckShopPrice::persist()`, a repoint and a new pack size. `AttachShop` makes a new shop, which has no cached entry. Freshness, the promotion boundaries, the price comparison and "covers the window" run on every read.
- **The alert flag reads past the cache.** `isLowestIn90Days()` runs inside the check's transaction, before the commit clears the entry, so it reads and writes nothing in the cache.
- **DemoSeeder** stores each shop's pack size on its seeded checks. Without it, no seeded shop with a size gets a verdict after the cutoff.
