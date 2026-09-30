# Category Expansion: Electronics, DIY, Toys

<!-- spec:planned-at 9f5a32966902c9a719484d0c7873891c57cf40f3 2026-09-30 +uncommitted -->

## Overview

DipCatch reads groceries, pet food and beauty well. People also watch electronics, DIY (bouwmarkt), department-store and baby/toy products, and no Dutch tool watches any product URL across all those shops. This spec fixes the generic JSON-LD reader so more of those shops read correctly, refuses comparison sites, adds a discount check ("the shop says it was €X; the lowest price here in the 30 days before was €Y"), adds landing pages for the new categories, and records a decision for each shop that blocks the fetcher.

Research that led here (2026-09-30): 30 shops tested with `dipcatch:read-page` from a home IP; competitor scan (Tweakers, Kieskeurig, PriceSpy, Prijzenvolger, Whisprice, Prijspuls); ACM fines of €621,000 for fake discounts (2024) and continued violations at Black Friday 2025; ACM Leidraad prijsweergave en -vergelijkingen (19 September 2025).

## Assumptions

- **Scope:** reader fixes, comparison-site refusal, claimed regular price, discount check, three landing pages, blocked-shop decisions. The user chose all four parts (2026-09-30).
- **Discount check reference:** the shop's own lowest shelf price in our readings in the 30 days before the discount started (ACM 30-day rule). Confirmed.
- **Discount start:** the first reading of the unbroken run of readings that show today's claimed 'was' price, at most three months back. Steps of a progressive discount keep one claim, so they stay one run. Confirmed after the Codex review (replaces the earlier price-only "falling run" rule).
- **A rise ends the run:** walking back, the run stops at a reading whose shelf price is below the reading after it. ACM's progressive exception needs an uninterrupted fall. Found in Codex round 2.
- **Unknown is not absent:** a reading from a reader that cannot state a claim (for example the Checkjebon fallback for AH), a reading from before this ships, a reading whose bundle prices were carried over from an earlier reading, and a reading with an ex-VAT or trade-only price issue are not evidence. They neither continue nor end a run; they count as gaps. Found in Codex round 2.
- **Claim longer than three months:** the window becomes the 30 days before today. ACM calls a discount longer than three months excessive, and the progressive exception ends at three months. Chosen by the assistant, needs sign-off.
- **Claim and seller per reading:** each `price_checks` row stores the claimed 'was' price and the seller name; `shops` keeps the current claim for display. Confirmed (reverses the earlier "current value only" choice).
- **Seller:** the window, the run and the low use only readings from today's seller. A page that names no seller counts as the shop itself. Confirmed.
- **Coverage:** no gap over 3 days between evidence readings from the last one before the window start to today, the latest one included (at most 3 days before the page is shown). Else no line. Confirmed; boundaries made exact in Codex round 2.
- **Repointed shops:** readings before `repointed_at` never count; repointing clears the claim. Found in the Codex review; follows the existing `Reference` rule.
- **Plans:** the discount line shows on every plan. Confirmed.
- **Threshold:** any gap of €0.01 or more. ACM guidance has no tolerance. Confirmed.
- **Perishables:** no line for products in FreshProduce, MeatFishVeg, DairyEggs or Bakery. A heuristic for the ACM perishable exception, not the same thing: shelf-stable items in those categories are skipped too. Uncategorised products are checked. Confirmed.
- **Wording:** "Shop says it was €X. Lowest here in the 30 days before: €Y." Neutral, never "fake". Confirmed.
- **What counts as a claim:** a schema.org `StrikethroughPrice`, AH `priceBeforeBonus`, DekaMarkt `normalPrice`. A `ListPrice` is a recommended price (MSRP), not a previous price, so it is never a claim. Found in the Codex review.
- **Claimed price during a bundle:** not stored while a multi-buy bundle applies now (`BundleOffer::appliesTo()`), stored when a bundle is only announced. Chosen by the assistant, needs sign-off.
- **Where the line shows:** product page headline deal box and shop-table row; not on product cards. Chosen by the assistant, needs sign-off.
- **Not-a-shop hosts:** one shared host list (the web-discovery list plus bcc.nl and maxict.nl), checked in `ProbeShopUrl` (paste, MCP, discovery, reference retries). Refused, no keep-as-link. Tracked rows keep rechecking. Confirmed after research (Ambin turns bankrupt shops into comparison sites).
- **Landing pages:** 8 thin host adapters, each with a canary URL; slugs `electronics`, `toys`, `diy` with the hosts in section 5. Confirmed.
- **Host adapter fallback:** each thin adapter runs the configured generic chain in its order without the heuristic `GenericAdapter` (JSON-LD, Shopify, microdata, OpenGraph: `config/dipcatch.php:239`). `AdapterResolver::extractWith` states why an owned host must not fall to "a weaker reader that prices whatever number it finds" (`AdapterResolver.php:84-92`); a page on a new host that reads only through `GenericAdapter` today stops reading, which the per-host fixture check catches before launch. It moves on only after a `skip`, and returns success, failure or ambiguity at once, as `AdapterResolver` does (`AdapterResolver.php:73`). Found in the Codex review: an owned host otherwise loses the generic fallback (`AdapterResolver.php:92`).
- **Department stores:** no page of their own. Part of the confirmed page set.
- **Bot name:** the fetcher keeps `DipCatchBot`. The owner asks blocking shops for access. Confirmed.

---

## 1. Reader Fixes (generic JSON-LD)

Four defects, found by reading real pages:

1. **`isOfferType` tests the wrong word.** `app/PriceAdapters/JsonLdEntities.php:73` tests `'Shop'`, not `'Offer'`. The model rename in `d631c31` ("replace per-product scraper with per-shop adapter pipeline") changed the string. A top-level standalone `Offer` is never used; a standalone `AggregateOffer` is.
   - Danger: `consider()` fills `$state->shop` with the first offer-type entity (`JsonLdEntitySearcher.php:45-47`). With `'Offer'` restored, an unrelated top-level Offer (shipping, a site-wide promotion) seen first blocks `Product.offers` (`JsonLdAdapter.php:94-96`), and `fallback()` (`JsonLdSearchState.php:115-130`) pairs the Product with it when the Product did not rank.
   - Fix: keep a standalone Offer or AggregateOffer in its own slot (`$state->standaloneOffer`). `Product.offers` and ProductGroup offers always win; the standalone slot is used only when the page has no Product or ProductGroup offer at all. `$state->shop` keeps its name (a rename is out of scope).
2. **A Product inside an Action is never seen.** MediaMarkt publishes `{"@type":"BuyAction","object":{"@type":"Product",…,"offers":[…]}}`. `expandGraph` (`JsonLdEntities.php:17-47`) yields only top-level, `@graph` and list entries.
   - Fix: collect the `object` of every entity whose type ends in `Action` (one level deep, in top-level, list and `@graph` entries). After the first pass over all scripts, consider those objects in a second pass, into the same search state, unless a top-level entity already identified the request precisely (`$state->identified()`). A top-level Product that only names the page (`namesPageOnly`, for example a canonical Product with another SKU, `JsonLdMatch.php:64`) does not stop the second pass, so a more precise Action variant still wins. A page that repeats an identifying Product inside an Action skips the second pass, so it reads exactly as today and cannot become a tie (`PrecisionRanking.php:39`, `JsonLdAdapter.php:48`); a repeat that only names the page lands on `namesPageOnly ??=` and changes nothing. A page with an unrelated recommendation Product at top level and the requested Product inside the Action reads the Action's Product, because the recommendation does not identify the request.
   - The offer can name a marketplace seller (`seller.name` "Maxmovil NL"). Section 3 stores that name.
3. **A full-URL `@type` is not recognised.** Prénatal writes `"@type":"https://schema.org/Product"`. `typesOf` (`JsonLdEntities.php:57-65`) returns it unchanged.
   - Fix: strip a leading `http://schema.org/` or `https://schema.org/` from each type in `typesOf`. The price already comes through OpenGraph today; after the fix, stock comes through too.
   - Guard: a page whose only Product was recognised through the full-URL form, and which has no usable offer, returns `skip` (not `jsonld_no_offer`), so it still reaches OpenGraph as today. A short-form Product with no offer keeps today's `jsonld_no_offer`.
4. **The first `priceSpecification` wins, whatever its `priceType`.** `JsonLdOfferPrice::firstPriceSpec` (`JsonLdOfferPrice.php:31-34, 64-84`) does not read `priceType`. An offer with no direct `price` that lists a `StrikethroughPrice` or `ListPrice` spec first is priced at the old or recommended price. No test covers it.
   - Fix: `price()` skips specs whose `priceType` names `StrikethroughPrice` or `ListPrice` (short or full-URL form). `currency()` reads, in order: the offer's own `priceCurrency`; the currency of the spec `price()` selected; any other spec (today's behaviour, kept for an offer with a direct price whose only currency sits on a strikethrough spec). A struck `$100` spec before an `€80` selling spec then reads `80 EUR`, not `80 USD`.

## 2. Comparison Sites Are Not Shops

`bcc.nl` and `maxict.nl` are former shops that Ambin turned into comparison sites (bcc.nl after the 2023 bankruptcy, maxict.nl after September 2025; Ambin owns more than 250 such domains). Their product pages carry an `AggregateOffer.lowPrice` of another shop (bcc.nl: 1699, Expert's price, while the BCC listing is 2038.80; maxict.nl: 134.90, the lowest of 4 shops, which `dipcatch:read-page` reads as the shop price today). `JsonLdOfferPrice::price` (`:22-23`) stores `lowPrice` as the shop's price. A comparison-site host list exists only for web discovery (`config/dipcatch.php:204-211`, `web_discovery.not_a_shop`, read by `WebResultFilter::isNotAShop` at `app/Services/ShopDiscovery/WebResultFilter.php:53`). A pasted link never checks it.

- Move the list to one top-level key `dipcatch.not_a_shop` and read it from both `WebResultFilter` and `ProbeShopUrl`. Add `bcc.nl` and `maxict.nl`.
- In `ProbeShopUrl`, next to the `UnservableShops` check (`app/Actions/Shops/ProbeShopUrl.php:88`), a listed host fails with a new `ProbeFailure` case `NotAShop`. `ProbeShopUrl` serves the web paste (`AddShop`, `DrivesShopProbe`), the MCP tools (`AddShopTool`, `CreateProductTool`), web discovery (`WebPageReads.php:25`) and reference retries (`RetryReferenceShopsCommand.php:83`).
- Message, in the web probe error (`resources/views/livewire/shops/partials/probe-error.blade.php`) and the MCP reporter (`app/Mcp/Support/ProbeReporter.php`): "This is a comparison site, not a shop. Paste the link of the shop that sells it."
- `ProbeFailure::isWorthKeepingAsLink()` is false for `NotAShop`.
- Do **not** add the check to `ShopFetcher` (`:106`): that path also serves rechecks, and tracked rows keep rechecking. A reference (link-only) row on a listed host stays a link; its weekly retry now fails with `NotAShop` and does not turn it into a tracked shop.
- The final host after redirects is not checked; a redirect to a listed host still reads (accepted).
- No automatic detection. `AggregateOffer.offerCount > 1` catches maxict.nl but not bcc.nl (`offerCount` 1), and real shops use `AggregateOffer` for variant price ranges. A new comparison host is added to the list when it is found.

## 3. Claimed Regular Price and Seller

Nothing stores a shop's "was" price today (brief, 2026-09-30):

- No `regular_price`, `was_price` or `list_price` column (`database/migrations/2026_04_26_162646_zz_create_shops_table.php:11-34`).
- `single_item_price` is the shelf price during a multi-buy bundle, not a was price (`ResolvedBundlePricing.php:103-130`).
- Adapters read and drop it: AH `priceBeforeBonus` (`AhApiSource.php:179,191-195`), DekaMarkt `normalPrice` (`DekaMarktAdapter.php:127-146`), schema.org Strikethrough/ListPrice as a bool only (`UnitPriceSize.php:128-145`).
- No reading records a seller: the JSON-LD snapshot drops `offer.seller` (`JsonLdAdapter.php:152`), and `PriceCheck::create` (`CheckShopPrice.php:368`) has no seller column.

Add:

- Columns: `shops.claimed_regular_price` (decimal, nullable, current value for display); on `price_checks`: `claimed_regular_price` (decimal, nullable), `seller` (string, nullable), `claim_read` (boolean, nullable: true when the reader can state a claim, so a null claim means "the page stated none") `shelf_inherited` (boolean, nullable: true when `ResolvedBundlePricing` carried the tracked and shelf prices over from an earlier reading, `ResolvedBundlePricing.php:40,125`) and `consumer_price_issue` (string, nullable: the ex-VAT or trade-only issue `AdapterResolver` detects, `AdapterResolver.php:128`, which today lands only on the shop, `CheckShopPrice.php:313`). Nullable columns without a default; Postgres adds them without a table rewrite. Rows from before this ships stay null and are not evidence.
- Readers that can state a claim: the JSON-LD path, the AH API (`AhApiSource`) and `DekaMarktAdapter`. Every other reader, and the Checkjebon fallback for AH (`CheckShopPrice.php:134`, `CheckjebonSource.php:53`), writes `claim_read = false`.
- `ShopSnapshot::claimedRegularPrice` and `ShopSnapshot::seller`, with `withClaimedRegularPrice()` and `withSeller()` copy methods, following the `with*` pattern (`ShopSnapshot.php:98-160`). Written by `CheckShopPrice::persist` (shop at `:303-360`, check at `:368`) and the add paths (`AttachShop.php:66,100`, `ShopDraft.php:88,139`).
- Claim sources: JSON-LD `priceSpecification` with `priceType` `StrikethroughPrice` (short or full-URL form) in the selected offer; AH `priceBeforeBonus`; DekaMarkt `normalPrice`. Never `ListPrice`.
- A claim is dropped when:
  - it is at or below the current shelf price (`single_item_price` when set, else `price`);
  - its spec states a currency other than the selling currency;
  - a multi-buy bundle applies now (`BundleOffer::appliesTo()`, `app/PriceAdapters/BundleOffer.php:48`; the resolved state in `ResolvedBundlePricing.php:65,108`). An announced, expired or inherited bundle does not drop it.
- Seller source: the selected JSON-LD offer's `seller.name`. Other adapters leave it null.
- `Shop::updateUrl()` (`app/Models/Shop.php:113-178`) also clears `claimed_regular_price`.
- `/bot` page (`resources/views/bot.blade.php:37-38`) promises "it reads the title, image, price and pack size, and it stores nothing else from the page". Change it to name the regular price the shop states and the seller too.
- Claims are stored from the day this ships. A shop gets its first discount line at least 30 days later.

## 4. Discount Check

For a shop with a claimed regular price, show what the shop's own price was before the discount, from our readings.

Definitions, all over the shop's **evidence readings**: `price_checks` with `status = ok` (`ScrapeStatus.php:7`), `claim_read = true`, `shelf_inherited = false`, `consumer_price_issue` null, `checked_at` after `repointed_at` when set, and the same `seller` as today's reading (null matches null). Other readings are not evidence: they neither continue nor end a run, and they count as gaps.

- **Shelf price** of a reading: `single_item_price` when set, else `price`.
- **Discount start:** walk back from today's evidence reading. Include the earlier reading while it has the same `claimed_regular_price` as today and its shelf price is at or above the shelf price of the reading after it. Stop at the first reading that fails either test (a different claim, no claim, or a rise). The discount starts at the earliest included reading. Steps of a progressive discount keep one claim and only fall, so they stay one run (ACM Leidraad 2025, section 3.1: the exception needs an uninterrupted fall).
  - Rise example: 100 (no claim), 60, 90, 80 (claim 100 from the 60 on). Walking back from 80: 90 is included; 60 is below 90, so the walk stops. The discount starts at the 90; the window holds the 60; the line shows "Lowest here in the 30 days before: €60".
  - Example: permanent cut 100 → 80 (no claim), then two months later 70 with claim 100. The run is the readings with claim 100; the window is the 30 days before the first 70, and the low is 80. Line: "Shop says it was €100. Lowest here in the 30 days before: €80."
- **Over three months:** when the run reaches further back than three months, the window is the 30 days before today instead. ACM calls a discount longer than three months excessive; its progressive exception ends at three months. No rolling re-anchor to a later step.
- **Window:** the half-open interval [discount start − 30 days, discount start).
- **Our low:** MIN shelf price of the readings in the window. Index `['shop_id','checked_at']` exists (`2026_04_26_162647_create_price_checks_table.php:21`).
- **Coverage:** take the last evidence reading at or before the window start, every evidence reading after it, and the moment the page is shown. No two consecutive points may be more than 3 days apart (exactly 3 days passes). No evidence reading at or before the window start means no line. Free rechecks every 24 h, Pro every 6 h (`config/plans.php:52,68`). The window reaches at most four months back; `PruneOldChecksCommand` keeps 365 days.
- **Before this ships:** old rows are not evidence, so a promotion that runs across the release date gets no line until its run and window hold only new rows.
- **Skipped** for products in `FreshProduce`, `MeatFishVeg`, `DairyEggs` and `Bakery` (`app/Enums/ProductCategory.php:12-15`), a heuristic for the ACM perishable exception.
- **Today's reading must be evidence too.** A current reading with a consumer-price issue, a carried-over bundle or no claim shows no line.
- **Shown when** today's claim is €0.01 or more above our low. Every plan sees the line.
  - "Shop says it was €X. Lowest here in the 30 days before: €Y."
- **Where:** the product page only, in the headline deal box (`product-show.blade.php:103`) and the shop-table row (`:315`), the two places `shop-deal` already shows the promotion window. `components/shop-deal.blade.php` takes an optional result, and a result opens its outer guard on its own (`shop-deal.blade.php:19` renders today only for a bundle or promotion window; a strikethrough claim often has neither). The product card (`components/product-card/index.blade.php:139`) passes none, so the product list shows no line and runs no query.
- Neutral wording. The page never says "fake" or "illegal".
- A new class `App\Support\PriceBeforeDiscount` returns the claim and the low, or null, for a set of shops in a fixed number of queries. `ProductShow` loads it for all shops at once.
- `shop-deal.blade.php` and `product-show.blade.php` are in the `MarketingTranslationsTest` file list (`tests/Feature/MarketingTranslationsTest.php:~110-111`), so new strings need `lang/nl.json` entries.

## 5. Category Landing Pages

Use-case pages are `/price-alerts/{slug}`, with hosts in `config/site.php:131-159` and copy in `app/Support/UseCases.php:92+` (keys heading, label, description, intro, example, tips, faq). Every use-case host must be in `site.supported_hosts`, and every supported host must have a dedicated reader (`tests/Feature/SupportedHostsHaveReadersTest.php`), because the site markets each such host as one DipCatch reads (the rule and its history are in the test's docblock; `ShopPages::facts()` at `app/Support/ShopPages.php:108` builds the shop-page facts).

So each newly marketed host gets a thin host adapter that owns the host and runs the configured generic chain in its order, without the heuristic `GenericAdapter` (the non-host entries of `dipcatch.adapters` minus Generic: JSON-LD, Shopify, microdata, OpenGraph, `config/dipcatch.php:239`). Owning a host means refusing the heuristic reader; that is the rule `AdapterResolver::extractWith` enforces (`AdapterResolver.php:84-92`). It moves to the next adapter only on `skip`, and returns success, failure and ambiguity at once, with the unmatched variant key kept, exactly as `AdapterResolver` does (`AdapterResolver.php:73`). It fails only when every adapter skips. `SparAdapter` (`app/PriceAdapters/Hosts/SparAdapter.php`) is the pattern for owning a host; running the full chain keeps what the host reads today, since `AdapterResolver.php:92` turns an owned host's skip into a failure. One shared base class holds the chain; each host class only names its hosts. Each also gets a canary URL (`config/canary.php:22-55`), a `site.shop_names` entry and a `site.supported_hosts` entry.

Pages and hosts (only hosts that read correctly on 2026-09-30):

| Slug | Hosts |
|---|---|
| `electronics` | mediamarkt.nl, expert.nl, megekko.nl, bol.com, amazon.nl |
| `toys` | intertoys.nl, prenatal.nl, bol.com, amazon.nl |
| `diy` | hubo.nl, toolstation.nl, bouwmaat.nl, bol.com, amazon.nl |

- Copy in English with Dutch entries in `lang/nl.json`. Tips and FAQ counts follow the existing pages (groceries and beauty have six FAQ entries each). The copy leads with price history and the discount check. DIY and toys name the unit price for consumables (paint per litre, screws per piece, nappies per piece).
- Run `route:clear` after adding slugs (`config/site.php:126-129`).
- Department stores get no page: their products are the categories above.

## 6. Blocked Shops

Blocked from a home IP on 2026-09-30:

- **Bot name only** (a browser User-Agent gets the page): coolblue.nl, praxis.nl, babypark.nl.
- **Bot check for everyone:** gamma.nl, karwei.nl (Vercel), azerty.nl, action.com, debijenkorf.nl, lego.com, dreamland.nl (Cloudflare).

The fetcher keeps its honest name `DipCatchBot/1.0 (+https://dipcatch.eu/bot)` (`config/dipcatch.php:74`); the `/bot` page promises it. Per shop:

1. Run `php artisan dipcatch:read-page <url>` in production (Laravel Cloud command) for one product URL per shop above.
2. A shop that reads: treat like the hosts in section 5 (candidate for a page later).
3. A shop still blocked: add it to `site.unsupported_hosts` with the date and cause, so the shops page says so before a paste.
4. For the "bot name only" shops: the owner mails the shop to ask to allow `DipCatchBot`. Not code.

---

## Edge Cases

| Scenario | Handling |
|---|---|
| Unrelated top-level `Offer` plus a Product whose offer does not rank | The Product's own offer wins; the standalone slot is unused. Regression fixture asserts the Product's price. Test in `reader-fixes`. |
| Only a standalone `Offer` on the page, no Product | The standalone slot supplies the price. Test in `reader-fixes`. |
| Product inside `BuyAction.object` and the same Product at top level | Only the top-level one is considered; no tie. Test in `reader-fixes`. |
| Unrelated recommendation Product at top level and the requested Product inside `BuyAction.object` | The Action's Product is read (second pass). Test in `reader-fixes`. |
| Canonical Product (SKU A) at top level, requested SKU B inside the Action | Second pass runs; SKU B is read. Test in `reader-fixes`. |
| Full-URL `@type` Product with no offer, OpenGraph price present | Skip, OpenGraph reads as today. Test in `reader-fixes`. |
| Struck `$100` spec before an `€80` selling spec, no offer currency | Reads `80 EUR`. Test in `reader-fixes`. |
| Selling spec has the currency, the struck spec has none | Reads the selling spec's currency. Test in `reader-fixes`. |
| Product only inside an Action, in a list or `@graph` | Read. Test in `reader-fixes`. |
| `@type` list mixing a full-URL and a short type | Every element stripped. Test in `reader-fixes`. |
| Offer with only a StrikethroughPrice spec for price | No selling price from it; falls through as for a missing price. Test in `reader-fixes`. |
| Offer with a direct price and its currency only on a strikethrough spec | Reads as today. Test in `reader-fixes`. |
| Pasted URL on a `not_a_shop` host, web or MCP | `NotAShop`, no keep-as-link, message shown. Test in `not-a-shop`. |
| Web discovery finds a `not_a_shop` page | Filtered from the shared key. Test in `not-a-shop`. |
| Tracked shop on a `not_a_shop` host | Rechecks unchanged. Test in `not-a-shop`. |
| Reference row on a `not_a_shop` host | Weekly retry fails with `NotAShop`; stays a link. Test in `not-a-shop`. |
| Claim at or below the current shelf price | Not stored. Test in `claimed-price`. |
| Claim spec in another currency | Not stored. Test in `claimed-price`. |
| `ListPrice` (MSRP) only | No claim. Test in `claimed-price`. |
| Bundle applies now / only announced / expired | Not stored / stored / stored. Test in `claimed-price`. |
| Later reading states no claim | Shop column cleared; check row null. Test in `claimed-price`. |
| Failed reading | Nothing written for claim or seller. Test in `claimed-price`. |
| URL repointed | Claim cleared; readings before `repointed_at` never count; a failed first read of the new URL shows no line. Test in `claimed-price` and `discount-check`. |
| Rise inside a claim (100, 60, 90, 80 with claim 100) | Run starts at the 90; low 60; line shows. Test in `discount-check`. |
| Promotion running across the release date | No line until run and window hold only new rows. Test in `discount-check`. |
| AH reading from the Checkjebon fallback inside a run | Not evidence; counts as a gap. Test in `discount-check`. |
| Reading with carried-over bundle prices | Not evidence; counts as a gap. Test in `discount-check`. |
| Earlier ex-VAT or trade-only reading, later consumer promotion | The ex-VAT reading is not evidence; it never becomes the low. Test in `discount-check`. |
| Last evidence reading before the window is 4 days before the first one in it | No line. Test in `discount-check`. |
| Latest evidence reading more than 3 days before the page view | No line. Test in `discount-check`. |
| Gap of exactly 3 days | Passes. Test in `discount-check`. |
| Undated strikethrough claim (no bundle, no promotion window) | Line shows in headline and row. Test in `discount-check`. |
| Permanent cut, then a promotion claiming the old price | Window before the promotion's first reading; low is the cut price; line shows. Test in `discount-check`. |
| Progressive discount (100 → 90 → 80, claim 100 throughout) | One run; window before the 90. Test in `discount-check`. |
| Claim changes (was 100, now was 120) | New run starts at the first "120" reading. Test in `discount-check`. |
| Run exactly three months / just over | Exactly: window before run start. Over: window is the 30 days before today. Test in `discount-check`. |
| Readings exactly at window start and at discount start | Start included, discount start excluded (half-open). Test in `discount-check`. |
| Month-end arithmetic (run starting 31 January) | Uses Carbon `subMonthsNoOverflow(3)`. Test in `discount-check`. |
| Gap over 3 days between `ok` readings in the covered span | No line. Test in `discount-check`. |
| Gap over 3 days that hides a rise before the run | No line (the gap rule covers it). Test in `discount-check`. |
| Shop first read less than 30 days before the discount start | No line. Test in `discount-check`. |
| Seller changed inside the window | Only today's seller's readings count; if that breaks coverage, no line. Test in `discount-check`. |
| Bundle live inside the window | Low uses `single_item_price`. Test in `discount-check`. |
| Claim at or below our low | No line. Test in `discount-check`. |
| Product in a perishable category / shelf-stable item in one / uncategorised fresh food | No line / no line (heuristic) / checked. Test in `discount-check`. |
| Reference ("kept as a link") shop | No readings, no line. Test in `discount-check`. |
| Product list (cards) | No line, no query. Test in `discount-check`. |
| Public share page `/p/{slug}` | No line (uses `x-shop-price`). No change. |
| New marketed host stops reading | Canary reports rot; the health check fails. Existing canary tests. |
| New host page that reads only through OpenGraph or Shopify today | Still reads through the thin adapter's chain. Test in `category-pages`. |
| New host page that reads only through `GenericAdapter` today | Stops reading (owned-host rule). The per-host fixture check before launch finds it. Test in `category-pages`. |
| JSON-LD on a new host returns ambiguous or failed while OpenGraph could read | Ambiguous or failed is returned; OpenGraph does not run. Test in `category-pages`. |
| New slug added without `route:clear` | Documented in the task; route test covers the slug. |

Currency: `CheckShopPrice` records `CurrencyMismatch` rather than a reading in another currency (`CheckShopPrice.php:400-407`), so readings of one shop share one currency and the check needs no currency filter.

## Implementation

### Phase 1: Reader Fixes (Priority: HIGH)

**ID:** reader-fixes · **Depends:** none

- [x] `JsonLdEntities::isOfferType` tests `'Offer'`; standalone Offer/AggregateOffer go to `$state->standaloneOffer`, used only when no Product or ProductGroup offer exists
- [x] Collect `Action.object` Products; second pass into the same state unless the first pass identified the request precisely
- [x] A full-URL-only Product without an offer returns `skip`
- [x] `JsonLdEntities::typesOf` strips a `http(s)://schema.org/` prefix
- [x] `JsonLdOfferPrice::price()` skips StrikethroughPrice and ListPrice specs; `currency()` prefers the offer currency, then the selected spec's, then any spec
- [x] Run `dipcatch:read-page` on every `config/canary.php` URL before and after; compare title, price and stock
- [x] Tests — `tests/Feature/PriceAdapters/JsonLdReaderFixesTest.php`: fixture per fix and per reader-fix edge case row; existing JSON-LD tests stay green

### Phase 2: Not-a-Shop Hosts (Priority: HIGH)

**ID:** not-a-shop · **Depends:** none

- [x] Move `web_discovery.not_a_shop` to `dipcatch.not_a_shop`, add `bcc.nl` and `maxict.nl`; `WebResultFilter` reads the new key
- [x] `ProbeFailure::NotAShop`; `ProbeShopUrl` returns it for listed hosts; `isWorthKeepingAsLink()` false
- [x] Message in `probe-error.blade.php` and `ProbeReporter`
- [x] Tests — `tests/Feature/Shops/NotAShopTest.php`: web and MCP paste refused with the message; discovery still filters; tracked row still rechecks; reference retry stays a link

### Phase 3: Claimed Regular Price and Seller (Priority: MEDIUM)

**ID:** claimed-price · **Depends:** reader-fixes

- [x] Migration: `shops.claimed_regular_price`; `price_checks.claimed_regular_price`, `seller`, `claim_read`, `shelf_inherited`, `consumer_price_issue`; each guarded with `Schema::hasColumn`
- [x] `claim_read` from the reader (JSON-LD path, AH API, DekaMarkt true; others and the Checkjebon fallback false); `shelf_inherited` from `ResolvedBundlePricing`
- [x] `ShopSnapshot::claimedRegularPrice`, `::seller` and `with*` methods; JSON-LD StrikethroughPrice and `seller.name`, AH `priceBeforeBonus`, DekaMarkt `normalPrice` fill them
- [x] Drop rules (at or below shelf price, other currency, bundle applies now)
- [x] `CheckShopPrice::persist` and the add paths write both on success only; `Shop::updateUrl()` clears the shop claim
- [x] `/bot` page sentence updated, Dutch entry in `lang/nl.json`
- [x] Tests — `tests/Feature/PriceAdapters/ClaimedRegularPriceTest.php`: each source, each drop rule, cleared on a reading without a claim, untouched on failure, cleared on repoint

### Phase 4: Discount Check (Priority: MEDIUM)

**ID:** discount-check · **Depends:** claimed-price

- [x] `App\Support\PriceBeforeDiscount`: evidence filter, run by claim, seller and no rise, three-month rule, half-open window, boundary-exact gap coverage, repoint cut-off, perishable skip, for a set of shops in a fixed number of queries
- [x] `components/shop-deal.blade.php` shows the line when given a result, and the result opens the outer guard; `ProductShow` passes results for all shops; product cards pass none
- [x] Dutch entries in `lang/nl.json`
- [x] Tests — `tests/Feature/Products/PriceBeforeDiscountTest.php`: every discount-check edge case row; query count does not grow with the number of shops
- [x] Eye-verify the product page in a browser (light, dark, 390 px)

### Phase 5: Category Landing Pages (Priority: MEDIUM)

**ID:** category-pages · **Depends:** reader-fixes, not-a-shop, discount-check

Depends on `not-a-shop` because both edit `config/dipcatch.php`, and on `discount-check` because the page copy names the discount check and both edit `lang/nl.json`.

- [x] One base class that runs the generic chain with `AdapterResolver`'s terminal-result rules; thin host adapters on it for mediamarkt.nl, expert.nl, megekko.nl, intertoys.nl, prenatal.nl, hubo.nl, toolstation.nl, bouwmaat.nl; register in `dipcatch.adapters` before the generic adapters
- [x] For each host, compare the read price with the price the page shows to a person (Expert, Megekko and Bouwmaat were not cross-checked on 2026-09-30); for Toolstation, confirm the stored price is incl. VAT (the page also prints "Excl. btw")
- [x] Canary URL per new adapter in `config/canary.php`
- [x] `site.supported_hosts`, `site.shop_names`, `site.use_cases` for `electronics`, `toys`, `diy`
- [x] Copy in `UseCases::copy()` and Dutch entries in `lang/nl.json`; `route:clear`
- [x] Tests — one adapter test per host from a saved fixture (`tests/Feature/PriceAdapters/Hosts/`); base-class tests for an OpenGraph-only page, a Shopify variant page, a Generic-only page (fails), and ambiguous or failed JSON-LD beside readable OpenGraph; existing use-case, supported-host, shop-page and sitemap tests pass
- [x] Eye-verify the three pages in English and Dutch

### Phase 6: Blocked Shops (Priority: LOW)

**ID:** blocked-shops · **Depends:** category-pages

- [x] Production `dipcatch:read-page` run for the shops in section 6; record results in this spec's Findings
- [x] Still-blocked shops into `site.unsupported_hosts` with date and cause
- [x] Tests — the shops page lists them (existing test pattern)

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The reader fixes change no canary result.** If any canary URL reads a different title, price or stock after `reader-fixes`, stop and report the page.
2. **MediaMarkt's Product sits one level inside the Action.** If the real pages nest deeper, or the offer list names no seller for MediaMarkt's own offers in a way that breaks the seller rule, stop and report.
3. **A shop's claim stays stable across its readings.** If a host's claimed price changes between readings of one promotion (for example rounding or a rotating reference), runs break constantly; stop and report that host.
4. **Our readings never go below the shop's own price.** If a host's readings include a loyalty-card price the shop does not charge everyone, our low can be too low; stop and report that host.

---

## Open Questions

1. **HEMA.** Its price sits only in `data-gtmproduct` attribute JSON, one entry per variant. It needs its own adapter. A separate spec?
2. **Babypark offers.** Five offers with mixed PreOrder and InStock; `pickOfferFromProduct` takes the first. Prefer an InStock offer? Not needed for the pages in this spec.
3. **Stock alerts, "bought it" and shared wish lists.** Left for a later spec; no demand evidence yet.
4. **Uncategorised fresh food.** The perishable skip reads the product category. Automatic categories are a Pro opt-in, so many Free grocery products have no category, and an AH bonus on fresh food then gets a line. Confirm after `discount-check` ships by counting lines shown on uncategorised supermarket products.
5. **Seller on bol.com and Amazon.** Their host adapters read the DOM, not JSON-LD, so the seller stays null there and seller changes go unseen. Read the seller in those adapters in a later spec?

---

## Resolved Questions

1. **Which parts go into this spec?** **Decision:** reader fixes, discount check, category pages, blocked-shops plan. **Rationale:** the user chose all four (2026-09-30).
2. **How are comparison-site links handled?** **Decision:** a shared host list, refused at paste, no keep-as-link. **Rationale:** a multi-seller signal misses bcc.nl and would flag real shops' variant ranges.
3. **When does the discount line show?** **Decision:** any gap of €0.01 or more, perishables skipped, all plans. **Rationale:** ACM Leidraad 2025 section 3.1 has no tolerance and names the perishable exception.
4. **Change the bot name for shops that block it?** **Decision:** no. **Rationale:** the `/bot` page promises an honest name; the owner asks the shops for access.
5. **How does the check know when a discount began?** **Decision:** store the claim per reading; the discount starts at the first reading of today's claim, at most three months back. **Rationale:** price movements alone cannot tell a permanent cut from a promotion step (Codex review, 2026-09-30).
6. **Marketplace sellers?** **Decision:** store the seller per reading; compare only readings from today's seller. **Rationale:** a different seller's price is not the shop's own previous price.
7. **What coverage is enough?** **Decision:** no gap over 3 days between `ok` readings from the window start to today. **Rationale:** a gap can hide a rise or a claim change and move the window.
8. **What ends a run, and what is evidence?** **Decision:** a shelf-price rise ends a run; readings that cannot state a claim, old rows and carried-over bundle rows are not evidence and count as gaps. **Rationale:** Codex round 2 showed a restarted discount and a fallback reading would otherwise move the window.
9. **Does `ListPrice` count as a claim?** **Decision:** no. **Rationale:** schema.org `ListPrice` is a recommended price, and ACM treats a recommended price apart from a previous price.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **reader-fixes (2026-09-30):** the 20 canary URLs read identically before and after (title, price, stock, adapter), so STOP 1 held. MediaMarkt (262.23 EUR, marketplace seller "Maxmovil NL") and Prénatal (10.99 EUR, stock now read, adapter `jsonld`) read live. MediaMarkt's Product sits one level inside `BuyAction.object`, and its offers name the seller (`seller.name`), so STOP 2 held.
- The rename in `d631c31` had also changed `'@type' => 'Offer'` to `'Shop'` in 32 test fixtures and in the `jsonLdPage()` helper docblock. They are back to `'Offer'`. The old `isOfferType('Shop')` also treated a schema.org `Shop` (a store, a LocalBusiness) as an offer.
- `JsonLdOfferPrice::claimedRegularPrice()` reads the strikethrough spec through the same private `specs()` walk the selling price uses. The `withClaimedRegularPrice()` and `withSeller()` copy methods were left out: no adapter augments a snapshot with them, the JSON-LD reader, AH and DekaMarkt set both in the constructor.
- **claimed-price:** the drop rules live in `App\Actions\Shops\RegularPriceClaim::kept()`, shared by `CheckShopPrice` and `ShopDraft::keptClaim()` (the add path). A snapshot's `claimAuthoritative` (the `with*` authority pattern) decides both whether the shop column is written and `price_checks.claim_read`. `ResolvedBundlePricing` now says when it carried prices over (`$inherited`), which feeds `shelf_inherited`.
- Review round (code-critic and Codex): a run longer than three months is now checked for gaps from its own start, not only across its last 30 days; an inherited bundle no longer drops or clears a claim (the claim is judged against the price just read); a full-URL Product with an unreadable offer no longer hides a later readable Product or blocks OpenGraph; a standalone AggregateOffer beats an earlier shipping Offer. The landing-page copy now says the chart shows the lowest price over time, and that per-piece and 30-day comparisons depend on what the page states and on coverage.
- The `/bot` page has no `__()` strings (it is English-only), so its changed sentence needs no `lang/nl.json` entry, unlike the spec task said.
- `ListPrice` still cannot be the selling price (phase 1), and is never a claim.
- **discount-check:** `App\Support\PriceBeforeDiscount::forShops()` answers for every shop in one query (a test pins it at one for 1 and 4 shops). Eye-verified on a dumped product page: headline and shop row, light, dark and 390 px.
- **category-pages, deviation:** Bouwmaat is left out. Its page shows "Reguliere prijs €7,48 excl. BTW" and it serves trade customers, so it is not a consumer price. The DIY page lists Hubo, Toolstation, bol.com and Amazon.nl; 7 host adapters, not 8. Expert (209,-), Megekko (€ 359,-) and Toolstation (€ 34,64 incl. btw) match what the page shows a shopper.
- Each new landing page has 3 tips and 6 FAQ entries, like groceries and beauty.
- Fixtures under `tests/Fixtures/category-shops/` keep only the JSON-LD and OpenGraph tags of the live pages, with `review` and `author` removed.
- **blocked-shops (production, 2026-09-30, `cloud command:run` with `dipcatch:read-page`):** Coolblue reads from production (188 EUR, `jsonld`), so it is not blocked there; the electronics page names it as a shop that works through the generic reader. Praxis, Babypark and Dreamland answer 403; Gamma and Karwei 429 (Vercel check); Azerty, Action, de Bijenkorf and LEGO a challenge page with 403. Those nine are now in `site.unsupported_hosts` with the date and cause.
- The owner still has to mail Praxis and Babypark to ask to allow `DipCatchBot` (not code).
- **not-a-shop:** the host check lives in `App\Support\NotAShop`; `WebResultFilter::isNotAShop()` delegates to it, so discovery and paste read one list.
