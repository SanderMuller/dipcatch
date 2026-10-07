# Web Shop Discovery

<!-- spec:planned-at 71adc5a5b0e6c841b690fc29d708cc011d7df0e8 2026-09-30 +uncommitted -->

## Overview

Shop suggestions today come only from the checkjebon dataset, so they only name Dutch supermarkets. This feature finds more shops for a tracked product on the open web: one Google search through Serper per product, a first Jev check on each result's title and snippet, a page read of the likely matches through the normal add-shop probe, and a second Jev check with what the page itself says. The pages that pass show in the existing suggestions list, beside the checkjebon rows, with the same Add and Hide actions.

A prototype (`php artisan dipcatch:find-shops`, `app/Services/ShopDiscovery/`) ran this flow on 20 products on 2026-09-30. It found 120 new shop pages. Jev proposed 21 after the page read, and 18 of them were right by a manual check. See `## Findings` for the numbers this spec is built on.

## Assumptions

Confirmed with the user on 2026-09-30 unless marked otherwise.

- **Gate:** the same as the AI shop check (Pro plus the shop-check setting). No setting of its own. Confirmed.
- **Trigger:** automatic. A queued job after a product is created, and a daily command for products never searched or searched more than 90 days ago. Confirmed.
- **Search cache:** one stored search per query, shared by every account, repeated after 90 days. Confirmed.
- **Limits:** page read from a first-check chance of 0.3; at most 8 page reads per product per run; at most 300 Serper searches a day app-wide; on by default (still needs a Serper key). Confirmed, the user changed the search cap from 200 to 300 and the default from off to on.
- **Price:** the page's price, labelled "when checked on {date}", plus the unit price when there is a pack size. Never shown as live. Confirmed.
- **Order:** one list, checkjebon rows first, then web rows, with the same "checked by AI" badge. Confirmed.
- **Provider:** Serper, behind one search interface so another provider can replace it. Confirmed.
- **Non-shops:** a config host list only, extended when one slips through. No extra Jev question. Confirmed.
- **Proposal bar:** the second check reuses `shop_checks.accept_from` (0.6). Carried over from the existing suggestions; not asked separately.
- **Page read retries:** 3 attempts for a rate-limited or temporary failure, then `unreadable`. AI-chosen from the prototype, where 3 of 62 reads were rate-limited.
- **Job type:** three short queued jobs (search and first check, one per page read, second check), not one long job and not `app()->terminating()`: page reads wait on per-host rate limits, and one long job would outrun the queue's 90 s `retry_after`. AI-chosen after the spec review.
- **Concurrency:** writes guarded by generation, fingerprint and expected status; the second check claims its rows; its uniqueness lock lasts only until it starts, so a later dispatch is never swallowed. AI-chosen after the spec review.
- **Budget split:** first and second checks spend from separate daily counters, so first checks never starve the checks that finish a read product. AI-chosen after the spec review.
- **Open panel:** "Looking for more shops…" with a progress bar that estimates the steps, and a 3 s poll for the first minute, then 15 s up to 5 minutes, while discovery is unfinished. A search queued more than 5 minutes ago shows the plain line instead of the bar. AI-chosen after the spec review.
- **Consumer prices:** a page whose price has no VAT or is trade-only is rejected, never shown. AI-chosen after the spec review.

---

## 1. Terminology

| Term | Meaning |
|---|---|
| **Web finding** | One page a web search returned for one product, with the state of its checks. A row in `web_shop_findings`. |
| **First check** | Jev's same-product chance from the search result's title and snippet only. |
| **Page read** | `ProbeShopUrl` on the result URL, as the add-shop form runs it. Stores nothing. |
| **Second check** | Jev's same-product chance from the page's title, pack size, price and barcode, plus the search title. |
| **Web suggestion** | A web finding that passed the second check, shown in the suggestions list. |
| **Checkjebon suggestion** | The existing `ShopSuggestion` from the dataset. Unchanged by this spec. |

## 2. Who Gets It

The same gate as the existing AI shop check (`User::wantsShopChecks()`, `app/Models/User.php:140`): the plan allows `shop_checks` (Pro, `config/plans.php:72`) and the user has not switched it off on the product features settings page. Web discovery needs Jev at every step, so it never runs without that gate. It also needs a configured Serper key (`services.serper.key`) and `dipcatch.web_discovery.enabled`.

## 3. Data Model

### 3.1 `web_searches` — one search, shared by every account

A search result says nothing about who asked, so one search serves every product with the same query.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `query_hash` | string(64), unique | sha256 of the normalised query |
| `query` | string | The text sent, for support |
| `results` | json | The organic results, rebuilt from Serper's answer: title, link, snippet, position. A row without a link is dropped |
| `searched_at` | timestamp | |

A search older than `web_discovery.search_max_age_days` is repeated on the next run.

### 3.2 `web_shop_findings` — one page per product

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `product_id` | uuid FK, cascade | |
| `web_search_id` | FK to `web_searches`, null on delete | The search this finding came from |
| `url` | text | As the search returned it |
| `url_hash` | string(64) | `UrlNormalizer::hash()` of the normalised search URL |
| `host` | string | Of the search URL, without `www.` |
| `add_url` | text, nullable | The URL Add sends to the add-shop flow: `ProbeOutcome::$normalizedUrl`, which `ProbeShopUrl::storedAddress()` keeps at the pasted address unless the page moved for good within the same shop |
| `served_host` | string, nullable | `ProbeOutcome::$host`: the host that answered, which differs from `add_url`'s host after a temporary redirect. The filters check both |
| `search_title` | string | |
| `snippet` | text, nullable | |
| `first_chance` | float, nullable | |
| `page_title` | string, nullable | From the page read |
| `page_pack_quantity` / `page_pack_unit` | decimal / string, nullable | From the page read |
| `page_price` | decimal(10,2), nullable | The price read, shown as "price when checked" |
| `page_currency` | string(3), nullable | |
| `page_gtin` | string, nullable | From the page read, for the second check and `matched_gtin` |
| `matched_gtin` | string, nullable | Set when the second check proposed a page that carries a tracked barcode |
| `checked_gtins` | json, nullable | The product's tracked barcodes when the last check ran |
| `read_at` | timestamp, nullable | When the page read succeeded |
| `second_chance` | float, nullable | |
| `status` | string | See 3.4 |
| `failure` | string, nullable | Why the finding stopped: a `ProbeFailure` value, a probe state (`duplicate`, `ambiguous`), `incomplete_probe`, `read_cap`, `tracked_host`, `not_a_shop`, `hidden_shop` (the owner chose Don’t suggest for it), `not_a_consumer_price` or `worker_failed: {exception}` |
| `attempts` | unsigned tinyint | Page reads tried |
| `next_attempt_at` | timestamp, nullable | When a retried read may run |
| `fingerprint` | string(64) | See below |
| `generation` | unsigned int | Raised each time the finding starts over; a job's write only lands when the generation it read is still current |
| `checked_at` | timestamp, nullable | When the last check finished |
| `dismissed_at` | timestamp, nullable | Hide |

Unique on `(product_id, url_hash)`. Index on `(product_id, status)`.

**Fingerprint:** sha256 of the product title, its distinct tracked pack sizes (`TypeSafeClient::trackedPackSizes()`, already de-duplicated and sorted) and the shoppers' country code (`ShoppersCountry::code()`), which both checks ask about. It leaves out the tracked shops' URLs and barcodes, which `SuggestionVerdicts::evidence()` includes: adding one suggested shop of the same pack must not void every other web suggestion. A shop in a new pack size does change it, and then the findings are checked again against that size. Barcodes are guarded apart, because Jev sees them too (`TypeSafeClient::state()` sends the evidence shop's barcode): a finding whose `checked_gtins` holds a barcode no tracked shop carries any more starts over, as a stale fingerprint does. A barcode that is only added, by a newly tracked shop, changes nothing.

Add each statement on its own guard (`Schema::hasTable`), per the migration rules. Both tables are new and empty, so the migration runs in the deploy step.

### 3.3 `web_discoveries` — one row per product

The state of discovery for a product as a whole, written before any job is queued, so the panel knows discovery is on its way before the first finding exists.

| Column | Type | Notes |
|---|---|---|
| `product_id` | uuid, primary key, FK cascade | |
| `web_search_id` | FK to `web_searches`, null on delete | The search the product's findings were built from |
| `search_searched_at` | timestamp, nullable | The `searched_at` of that search when the findings were built. When the shared search's `searched_at` is newer, another product refreshed it, and this product's findings start over too |
| `state` | string | `queued`, `running`, `done` |
| `queued_at` / `finished_at` | timestamps, nullable | |

### 3.4 Finding status

| Status | Meaning | Shown? | Unfinished? |
|---|---|---|---|
| `new` | Stored from the search, first check not answered yet (Jev down or budget spent) | No | Yes |
| `rejected` | First check below `web_discovery.read_from`, a filtered host, or over the per-run read cap | No | No |
| `pending_read` | Passed the first check, page not read yet, or a read to retry after `next_attempt_at` | No | Yes |
| `read` | Page read succeeded, second check not answered yet | No | Yes |
| `checking` | Claimed by a running `CheckWebFindings`. A claim older than 120 s goes back to `read` on the next run (`releaseStaleClaims`) | No | Yes |
| `unreadable` | The page read failed for good (blocked, extraction failed, not servable, ambiguous, duplicate) or after `read_attempts` | No | No |
| `proposed` | Second check at or above `shop_checks.accept_from` | Yes | No |
| `declined` | Second check below `accept_from` | No | No |

A product has unfinished discovery while its `web_discoveries.state` is `queued` or `running`, or any of its current-fingerprint findings is `new`, `pending_read`, `read` or `checking`.

## 4. Configuration

A new `dipcatch.web_discovery` block in `config/dipcatch.php`, next to `shop_checks`, with `.env.example` placeholders:

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Kill switch; nothing runs without a Serper key either |
| `results_per_search` | 10 | Serper `num` |
| `country` / `language` | `nl` / `nl` | Serper `gl` / `hl` |
| `read_from` | 0.3 | First-check chance from which the page is read |
| `max_reads_per_product` | 8 | Page reads per product per run |
| `read_attempts` | 3 | Attempts for a rate-limited or temporary page read |
| `retry_fallback_seconds` / `retry_max_seconds` | 60 / 900 | Delay when a failed read names none, and the cap on any delay |
| `poll_fast_seconds` / `poll_fast_for_seconds` | 3 / 60 | How often an open panel polls in its first minute |
| `poll_seconds` / `poll_for_seconds` | 15 / 300 | How often and how long an open panel polls while discovery is unfinished |
| `search_max_age_days` | 90 | When a stored search is repeated |
| `daily_search_limit` | 300 | Serper searches app-wide per day; zero lifts it. Reserved with an atomic `Cache::increment` on a key that expires at the end of the day, compared after the increment |
| `not_a_shop` | list of hosts | Comparison, review, social, rental and B2B sites, from the prototype (`SerperSearch::NOT_A_SHOP`) plus `kisteman-events.nl`, `partyverhuren.nl`, `bidfood.nl`, `makro.nl` |

`services.serper.key` already exists in the working tree (prototype); keep it. `.env.example` gets `SERPER_API_KEY=` with no value.

Jev spending goes through `CategorisationBudget::allowsShopCheck()` with two new purposes, so it has its own daily counters and never spends the add-shop or suggestion budget. Those counters use the existing `shop_checks.daily_limit_per_user` (50) and `daily_limit` (2000). The counters count logical checks: one per `sameProduct()` call, whatever the HTTP client retries underneath. The first check spends from `ShopCheckPurpose::WebDiscovery` and the second from `ShopCheckPurpose::WebDiscoveryConfirm`, so a busy backfill of first checks can never use up the checks that finish products already read. A run spends one of each, so one account's backfill covers about 50 products a day. A second check that finds its budget spent leaves the findings `read` for the next day; no page is read again.

## 5. Flow

### 5.1 Trigger

- **New product:** at the end of `CreateProductWithShop` (used by the paste-a-link form and the MCP `create_product` tool), dispatch `DiscoverWebShops` when the owner passes the gate in section 2 and the product is priced in the currency of the owner's country (`DiscoveryReach`). `CreateProductManual` creates a product without a shop, so there is no pack to compare against; the backfill picks it up once it has one.
- **Preview of a pasted page:** before the product is saved, the add-product wizard dispatches `PrewarmShopSearches` for the preview title: the open search and the Klarna page search, stored per query as usual, so the discovery that starts on save finds them done. Same gate and currency rule as a new product, none for a page the account already tracks, and at most 10 per account per hour and 30 per day, as a preview without a save still spends paid searches.
- **Daily command:** `dipcatch:discover-web-shops` (daily, `withoutOverlapping()->onOneServer()`, next to the others in `bootstrap/app.php`) dispatches `DiscoverWebShops` for gated products in the currency of their owner's country with at least one shop, in this order, up to the daily search limit for the ones that need a search:
  1. products with unfinished discovery (3.4) and a fresh search (an unfinished product whose search is stale falls under 4), so a run cut short by Jev or the budget goes on the next day without a new search;
  2. products whose `web_discoveries.search_searched_at` is older than their search's `searched_at` (another product refreshed the shared search), and products with findings whose fingerprint or `checked_gtins` is stale, re-checked from the stored search;
  3. products with no search, then products whose search is older than `search_max_age_days`, oldest first.

### 5.2 Jobs

Three short queued jobs rather than one long one. One job that reads eight pages at 15 s or more each (`dipcatch.fetcher.timeout_seconds` per redirect hop, plus robots.txt) plus two Jev calls at up to 20 s with a retry would outrun the queue's 90 s `retry_after` (`config/queue.php`), and a second worker would pick the same job up. Each job below sets `$timeout` below `retry_after`. `DiscoverWebShops` makes two network calls (the search and the first check); the other jobs make one step each. A page with many redirects can outrun `ReadWebFinding`'s 60 s; the worker then kills it, which uses one of its tries. `DiscoverWebShops` and `ReadWebFinding` are `ShouldBeUnique` on their key, like `CheckShopPrice`; `CheckWebFindings` claims its rows, and is unique only until it starts (see below). Every job is safe to run again: it reads the findings' state and does only what is still missing.

**`DiscoverWebShops` (unique per product)**

1. **Search.** Set the product's `web_discoveries.state` to `running`. Build the query from the product title. Under a `Cache::lock` on the query hash, check again for a fresh stored search; only when there is none, reserve one unit of the daily search limit (`Cache::increment` on the day's key, compared after the increment; `RateLimiter::attempt()` checks before it counts, so two workers could both pass the last slot) and call the provider. Store the result. Two products with the same title that miss the cache together spend one search. No search (switched off, limit spent, provider failed): steps 2 and 3 are skipped, and the stored findings go on from step 4.
2. **Start over where needed.** A finding starts over when the product's `search_searched_at` is older than the search's `searched_at` (this or another product refreshed it), or when its fingerprint is not the current one. A stale `checked_gtins` starts it over too. Starting over deletes an undismissed finding whose URL is not in the filtered results (step 3), so a result that now points at a tracked or non-shop host goes too, and puts the others back to `new` with the current fingerprint, the new search's title and snippet, read and check columns cleared, `attempts` reset and `generation` raised. Dismissed findings stay dismissed. Then record the search on `web_discoveries`.
3. **Filter.** Drop results whose host the product already tracks (same rule as the prototype's `FoundShop::bestPerNewHost`), hosts on `not_a_shop`, and URLs with a dismissed finding. Keep the best result per host. Insert the rest as `new` findings (insert-or-ignore on the unique key).
3a. **Barcode search.** After the title search, when the product's shops report a barcode, use its stored search when it is fresh. Otherwise `SearchProductBarcode` (unique per product) searches it the same way, one stored search per query from the same daily limit, and queues discovery again only when a search was made; one job with both searches and the first check would outrun its timeout. The job checks the owner's gate again before it searches. While it is queued, discovery is not `done`, so an open panel keeps polling; a refused search finishes it. The daily command ranks a product with nothing else to do but a barcode without a fresh search last, so existing products get one. Several barcodes: the one most shops report, leading zeros ignored, the lowest on a tie, sent as a shop stores it, the 13-digit form when there is one. Filter the results as in step 3. Both searches also drop a result on a host the product already has a finding on under another URL, dismissed ones included: the two searches can name one shop twice, and a hidden shop must not come back under another URL. The daily command counts a barcode without a fresh search against the limit on top of another rank's work when it fits; when it does not, the product still runs and its barcode search waits a night. A barcode finding is not in the title search, so step 2 does not delete it: like a `site:` lookup, it starts over only when its fingerprint or `checked_gtins` is stale. For Roter Vitamine C 800 the title search returned 5 pages and the barcode search 8 shops, 5 of them new (2026-10-04).
Both checks (`WebShopDiscovery::firstCheck()` and `WebSecondCheck`) also ask whether the candidate is a shop for shoppers in the searched country (`web_discovery.country`, sent as `shoppers_country` in the state), judged from its domain, title and listing. A barcode search finds shops abroad that sell the same product (houra.fr, bodygain.si, medpex.de, 2026-10-05). The add-shop and suggestion checks and the Klarna page search do not ask it: Klarna is a comparison site, not a shop.
4. **First check.** One `sameProduct()` request for the product's `new` findings with the current fingerprint, with `title` = search title and `snippet` as its own field, and `checked_gtins` stored. Below `read_from`: `rejected`. From `read_from`: `pending_read`, while the product's current-generation findings that are `pending_read`, `read`, `checking`, `proposed`, `declined` or `unreadable` number fewer than `max_reads_per_product`; the rest `rejected`. A finding the answer leaves out stays `new` (`sameProduct()` returns a partial map when at least one candidate is answered). Jev down or budget spent: the findings stay `new` for the daily command.
5. Dispatch `ReadWebFinding` for each `pending_read` finding whose `next_attempt_at` has passed, and `CheckWebFindings` for the `read` findings as below. With nothing left unfinished, state `done`.

**`ReadWebFinding` (unique per finding)**

Run `ProbeShopUrl($product, $url, $product->user, spendBudget: false)`.

- Success: store `add_url`, `served_host`, page title, pack size, price, currency, barcode and `read_at`; status `read`. Instead:
  - `rejected` when the host of `add_url` or `served_host` is tracked by the product or on `not_a_shop`;
  - `rejected` with `failure` = `not_a_consumer_price` when the draft carries a `consumerPriceIssue` (a price without VAT, or a trade-only page; `AdapterResolver` sets it on the snapshot);
  - A page whose barcode matches a tracked shop's barcode is `read` like any other (2026-10-05). It used to be proposed without a Jev call, which proposed shops abroad that the barcode search found: the barcode proves the product, not the shop's country.
- `LocalThrottle`, `HostRateLimited`, `TemporaryFailure`: count the attempt and set `next_attempt_at` from `retry_after_seconds`, or `retry_fallback_seconds` when the outcome carries none (`TemporaryFailure` does not), capped at `retry_max_seconds`; release the job to that time. After `read_attempts`, `unreadable`. The job declares `tries()` of `read_attempts` and a `uniqueFor()` longer than the sum of its delays, as `CheckShopPrice` does (`#[Tries(10)]`, `uniqueFor()`), because the dev worker runs with `--tries=1`. Its `failed()` sets the finding `unreadable`, so a worker crash does not leave it `pending_read` for good.
- Any other failure, a duplicate included: `unreadable`, with the `failure` value.
- Within 10 minutes of a product or shop being added, someone waits: when the product has a `read` finding, dispatch `CheckWebFindings` with a 2 s delay, without waiting for the other reads, up to 4 such early checks per product. A read that waits to retry then does not hold back the pages already read, so web suggestions appear one by one. Otherwise, as in the daily run, dispatch it once no finding is left `pending_read`, which keeps the second check to one Jev call per product. Then run the completion rule below.

**`CheckWebFindings` (unique until it starts)**

Claim the product's `read` findings with the current fingerprint by a conditional update to `checking` (only rows still `read` move), then send one `sameProduct()` request for the claimed ones: `title` = page title, `listing_title` = search title, `pack_size`, `price`, `gtin`. Store `second_chance` and `checked_at`; `proposed` at or above `accept_from`, with `matched_gtin` when the page carries a tracked barcode, else `declined`. A finding the answer leaves out goes back to `read`. Jev down or budget spent: the claimed findings go back to `read` for the daily command; the page is not read again. `failed()` puts back claims older than 120 s; a job killed at its timeout leaves younger claims, which the next run puts back. Two jobs for one product never send the same finding twice, because the claim moves each row once. The job is unique only until it starts: reads that end while it waits are checked in the same request, and a read after it starts queues the next one. Then run the completion rule.

**Completion rule**

After `ReadWebFinding` reaches a final status for its finding (`read` with no read left, `rejected`, `unreadable`, `proposed`), after `CheckWebFindings`, and in every job's `failed()`: when the product has no unfinished finding (3.4 without the state), set `web_discoveries.state` to `done` and `finished_at`.

**Shared rules**

- `TypeSafeClient::sameProduct()` uses the short add-shop timeout. The jobs pass a new `quick: false` argument for the categorisation timeout and retry.
- `ShopMatchCheck::candidate()` gets optional `snippet` and `listing_title` fields. The second check keeps the search title because a page title often drops the pack size the search title states (the prototype lost plein.nl's Roter 800 and Body & Fit creatine this way).
- Jobs write only the columns their step owns, as column updates on a query that also matches the finding's id, the `generation` and `fingerprint` the job read, and the status it expects (`pending_read` for a read, `read` for a second check). A finding that started over while a page read or a Jev call was on its way is left alone. None of them ever writes `dismissed_at`, so a Hide during a run survives.
- `CreateProductWithShop` and the daily command set `web_discoveries.state` to `queued` before they dispatch, so the panel shows "Looking for more shops…" from the first render.

### 5.3 Showing web suggestions

`ShopSuggestions` (`app/Livewire/Suggestions/ShopSuggestions.php`) reads the `proposed`, undismissed findings with the current fingerprint and no stale `checked_gtins` for the product, whose `add_url` host and `served_host` the product does not track yet, and, for a barcode-shortcut proposal, whose `matched_gtin` a tracked shop still carries, while the owner still passes the gate. It shows them after the checkjebon rows.

The view (`resources/views/livewire/suggestions/shop-suggestions.blade.php`) decides its empty state and its accordion count from the checkjebon rows alone today. Both take the web rows into account: "No shop suggestions for this product right now" shows only when both lists are empty and discovery is not unfinished, and web rows show even when the dataset is unusable or the product is not in euros (`SuggestShops` returns nothing then).

- Label: the host with its favicon, the page title, the pack size.
- Price: "€x when checked on {date}", plus the unit price when there is a pack size. Never shown as a live price. The dataset rows carry a "dataset price" label; the web rows need their own wording.
- Badge: the existing "same product, checked by AI" badge; every web suggestion has passed two checks.
- Add: `accept($addUrl)`, the existing flow. The add-shop probe reads the page again, so the stored page data is never written to the shop.
- Hide: a new `dismissWeb(int $findingId)` that updates `dismissed_at` on a finding of the component's authorised product only (`WebShopFinding::query()->where('product_id', $this->product()->id)->findOrFail($id)`), then dispatches `shop-suggestions-changed` so the second instance of the component on the page refreshes too, as `dismiss()` does.
- **While discovery is unfinished:** the component shows "Looking for more shops…" and polls (`wire:poll.15s`) until nothing is unfinished or 5 minutes have passed since the component mounted. The queued jobs cannot send a browser event, so polling is the way the open panel learns about new suggestions.

## 6. Out of Scope

- Adding the brand to product titles. It would fix the one brand confusion in the prototype, and is its own change.
- Searches restricted to one shop (`site:`). The open search found 6 new shops per product; add restricted searches only if a later measure asks for them.
- Other countries. *Superseded:* the search runs in the owner's country (`ShoppersCountry`: the country set in Settings, else the one of the timezone, else `nl`), with its language, and both checks ask whether a shop sells to shoppers there.
- The receipt-import prototype (`MatchListCommand`, `app/Services/ListImport/`). Separate feature.

## Edge Cases

| Scenario | Handling |
|---|---|
| Serper key missing or `enabled` false | The job and the command do nothing. No finding is written. `search` phase tests. |
| Serper answers 4xx/5xx or times out | The job logs a warning (an error for 401, 402 and 403) and stores no search, so the next run tries again. Findings stored before still go on. `search` phase tests. |
| Daily search limit reached | The command stops dispatching; a job that finds the limit spent ends without a search. `search` tests. |
| Two products with the same title | One stored search serves both; findings are per product. `search` tests. |
| Jev unreachable or budget spent | Findings stay `new` (first check) or `read` (second check); the daily command picks the product up as unfinished and goes on without a new search or a new page read. `judge` and `triggers` tests. |
| Page read rate-limited by the shop, or a temporary failure | Retried after `retry_after_seconds` or 60 s, capped at 15 min; `unreadable` after `read_attempts`. `judge` tests. |
| Page read blocked or no product data | `unreadable` with the failure value; never shown. `judge` tests. |
| Result is a category or comparison page | The page read finds no single product, or Jev declines it. Prototype: jumbo.com category pages scored 0.39. `judge` tests with a category-page fixture. |
| Result is a multipack of the tracked pack | The second check sees the real pack size and declines. `judge` tests. |
| Product gets a new title or pack size | The fingerprint changes; stale findings are hidden and re-checked on the next run. `ui` and `judge` tests. |
| User adds the suggested shop | The host is now tracked; the finding drops out through the tracked-host filter on render. `ui` tests. |
| User hides a web suggestion | `dismissed_at` set; never shown again for that product, also after a new search. `ui` tests. |
| Another account's finding id sent to `dismissWeb` | 404. `ui` tests. |
| User switches shop checks off or loses Pro | No new jobs; stored web suggestions stop showing (the render checks the gate). `ui` tests. |
| Product not in the currency of the owner's country | No job is dispatched: a shop there prices in that currency, and a page in another one fails the read (`currency_mismatch`). `triggers` tests. |
| Product created by hand, without a shop | No job on create; the backfill picks it up once it has a shop. `triggers` tests. |
| User adds one suggested web shop | Only that host drops out; the other web suggestions keep their fingerprint, because tracked shop URLs are not part of it. `ui` tests. |
| A job runs twice, or is released and runs again | Each job does only what the findings' state still needs: no second search, no second first check, no second read of a finished page. `judge` tests. |
| Two products with the same title miss the cache at once | The lock on the query hash lets one search through; the other reuses it. `search` tests with two concurrent runs. |
| Stored search refreshed after 90 days | Findings not in the new results are deleted unless dismissed; the rest start over from `new`. `judge` tests. |
| Page redirects to another path or host | `add_url` and `served_host` are stored; a tracked or `not_a_shop` host on either is `rejected`; Add uses `add_url`. `judge` tests with a cross-host 302. |
| A tracked shop's barcode is corrected or removed | Findings whose `checked_gtins` hold the old barcode start over, AI proposals and barcode-shortcut proposals alike. `judge` tests. |
| User adds a suggested shop with a barcode the product did not have | The fingerprint holds no barcodes, so the other web suggestions stay. `ui` tests. |
| User adds a shop in a new pack size | The fingerprint changes; the findings start over on the next run and are checked against the new size. `judge` tests. |
| Two products share a query; one refreshes the search | The other product's `search_searched_at` is now older than the search's; its findings start over on its next run. `judge` tests. |
| A refresh or product edit while a page read or Jev call is on its way | The late write matches no row (generation, fingerprint or status changed) and is dropped. `judge` tests with a delayed fake response. |
| Two workers take the last search slot at once | The atomic increment lets one through; the other finds the limit spent. `search` tests. |
| Page read shows a price without VAT or a trade-only page | `rejected`, `failure` = `not_a_consumer_price`. `judge` tests with both fixtures. |
| Read job crashes or runs out of tries | `failed()` sets the finding `unreadable`. `judge` tests through a real queue run, not repeated `handle()` calls. |
| Account's Jev checks for the day spent | First and second checks have separate counters; a spent second-check budget leaves findings `read` for the next day without a new read. `judge` tests. |
| Jev answers only part of a batch | Unanswered findings stay `new` or go back to `read`; none is rejected by default; the read cap counts findings already past the first check. `judge` tests for both checks. |
| A new read finishes while a second check runs | The running job claimed only its own rows; the new read's `CheckWebFindings` claims the rest. `judge` tests through a real queue run. |
| All reads and checks finish | The completion rule sets `done`, and the panel stops "Looking for more shops…". `judge` and `ui` tests. |
| A refresh returns the same URL with a new title or snippet | The finding takes the new title and snippet before its first check. `judge` tests. |
| User hides a web suggestion while a job runs | Jobs never write `dismissed_at` and update by column, so the Hide stays. `judge` tests. |
| Panel open while discovery is unfinished, also before the first job ran | `web_discoveries.state` is `queued` from dispatch; "Looking for more shops…" and a 3 s poll for the first minute, then 15 s up to 5 min; new web rows appear without a reload. `ui` tests and eye-verify. |
| More results pass the first check than the read cap | The top `max_reads_per_product` are read; the rest are `rejected` for this search. `judge` tests. |
| Product deleted | Findings cascade. Stored searches stay; they hold no user data. Migration test. |
| A search result on an unsafe or internal URL | `ProbeShopUrl` runs `UrlSafetyGuard` and robots.txt as for any pasted URL. `judge` tests. |
| Result URL already tracked by the product under another path | `ProbeShopUrl` answers duplicate; the finding is `unreadable` with `duplicate`. `judge` tests. |

## Implementation

### Phase 1: Schema and configuration (Priority: HIGH)

**ID:** schema · **Depends:** none

- [x] Migration for `web_searches`, `web_shop_findings` and `web_discoveries` — section 3, each statement guarded.
- [x] Models `WebSearch`, `WebShopFinding` and `WebDiscovery` with casts and a status enum `WebFindingStatus` — section 3.4.
- [x] `WebShopFinding::fingerprintFor(Product, string $url)` and scopes `current()`, `unfinished()`, `shown()` — the one definition the jobs, the command and the component share (3.2–3.4).
- [x] `dipcatch.web_discovery` config block and `.env.example` placeholders — section 4.
- [x] `ShopCheckPurpose::WebDiscovery` and `WebDiscoveryConfirm` — own Jev budget counters for each check.
- [x] Tests — migration shape, the unique key, the cascade on product delete, the fingerprint (title, distinct packs, URL; not shop URLs or barcodes), the scopes.

### Phase 2: Search (Priority: HIGH)

**ID:** search · **Depends:** schema

- [x] `App\Services\ShopDiscovery\WebSearchProvider` interface with a `SerperProvider` implementation, bound with `#[Bind(SerperProvider::class)]` on the interface — the provider can be replaced without other changes.
- [x] `App\Services\ShopDiscovery\WebSearches` — one search through the provider under a lock on the query hash, the daily limit as an atomic increment, `gl`/`hl`/`num` from config, a stored `WebSearch` reused while fresh. Built from the prototype's `SerperSearch`, without its disk cache.
- [x] Result filter — tracked hosts, `not_a_shop`, dismissed URLs, best result per host.
- [x] Tests — `Http::fake` for Serper: search stored and reused, stale search repeated, limit reached, two workers at the last slot, two concurrent misses on one query, error answer, missing key, filter rules.

### Phase 3: Checks and page reads (Priority: HIGH)

**ID:** judge · **Depends:** search

- [x] `TypeSafeClient::sameProduct()` `quick` argument; `ShopMatchCheck::candidate()` `snippet` and `listing_title` fields — section 5.2.
- [x] `App\Jobs\DiscoverWebShops` — search under the query-hash lock, refresh, filter, first check, dispatch the reads.
- [x] `App\Jobs\ReadWebFinding` — one page read, final URL and host, barcode shortcut, retry with `next_attempt_at`.
- [x] `App\Jobs\CheckWebFindings` — claim `read` findings, the second check, put unanswered or failed ones back.
- [x] The completion rule in every job and every `failed()`.
- [x] Each job's `$timeout` below the queue's `retry_after`; `ReadWebFinding` `#[Tries]`, `uniqueFor()` and `failed()`; conditional column updates on generation, fingerprint and status, never `dismissed_at`.
- [x] Start-over rules: refreshed shared search, stale fingerprint.
- [x] Reject a draft with a `consumerPriceIssue`.
- [x] Tests — `Http::fake` for Serper, TypeSafe and the shop pages (fixtures under `tests/Fixtures/`, anonymised): first-check split and read cap; read success; each failure class; retry with and without `retry_after_seconds`, then `unreadable`; redirect to a tracked host; second-check split; barcode shortcut; changed barcode re-check; Jev down at each check and the next run resuming without a new search or read; refresh after 90 days by this and by another product; new pack size; a delayed response after a start-over; a job run twice; Hide during a run; `not_a_consumer_price`; retries through a real queue run.

### Phase 4: Triggers (Priority: HIGH)

**ID:** triggers · **Depends:** judge

- [x] Set `web_discoveries.state` to `queued` and dispatch at the end of `CreateProductWithShop` for gated owners of EUR products — section 5.1.
- [x] `dipcatch:discover-web-shops` command and its daily schedule in `bootstrap/app.php`.
- [x] Tests — dispatched for a gated owner of a EUR product only; not for a manual product without a shop; the command picks unfinished, then stale-fingerprint, then never-searched and stale-search products, oldest first, and stops at the search limit.

### Phase 5: Suggestions list (Priority: HIGH)

**ID:** ui · **Depends:** schema

- [x] `ShopSuggestions` reads proposed findings and renders web rows after the checkjebon rows — section 5.3.
- [x] `dismissWeb()` scoped to the authorised product, dispatching `shop-suggestions-changed`.
- [x] "Looking for more shops…" state with a bounded `wire:poll` while discovery is unfinished.
- [x] Copy for "€x when checked on {date}" — English only; `nl.json` holds marketing keys only.
- [x] Empty state and accordion count from both lists; web rows shown when the dataset is unusable.
- [x] Tests — shown only when proposed, current fingerprint, undismissed and gated; tracked host hidden; adding one web shop keeps the others; "No shop suggestions for this product right now" only when both lists are empty; Add dispatches `suggest-shop` with the final URL; Hide refreshes both instances; a finding of another product, and a tampered product id, answer 404; polling stops when nothing is unfinished.
- [x] Eye-verify the product page panel and the add-shop form with a seeded proposed finding, and a finding that turns `proposed` while the panel is open.

### Phase 6: Remove the prototype (Priority: MEDIUM)

**ID:** cleanup · **Depends:** triggers, ui

- [x] Delete `FindShopsCommand`, `SitemapIndex`, `LocalSources`, `PageCheck`, `FoundShop` and the prototype `SerperSearch` once their parts live in the new classes.
- [x] Remove `storage/app/private/prototype/serper` and `.../sitemaps` locally (not in git).
- [x] Tests — the full suggestions suite still passes.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`ProbeShopUrl` stores no shop or product** — the page read relies on it being a pure read (it writes only host fetch memory). If it writes shop or product rows, stop: the read needs another entry point.
2. **Serper's terms allow storing results for reuse** — section 3.1 shares one stored search across accounts for 90 days. If the terms forbid storing, stop and ask before building the shared cache.
3. **Jev's same-product question works with the extra `snippet` and `listing_title` fields** — if adding them lowers the answers on the prototype's 20 products, stop and report the numbers.

---

## Open Questions

None.

---

## Resolved Questions

1. **Serper's terms of service?** **Decision:** Use Serper, behind a `WebSearchProvider` interface. **Rationale:** Serper resells Google results without a Google licence; Google's December 2025 suit against SerpApi lost its core claims in July 2026. The practical risk is the service stopping or changing price, which the interface contains.
2. **How to keep rental and B2B sites out?** **Decision:** A config host list for now. **Rationale:** Two of 21 proposals in the prototype were rental sites; a list fixes the known ones without an extra paid question.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

### Implementation notes

- **Fingerprint without the URL.** The URL is fixed per finding row (unique on product and URL), so it adds nothing to the fingerprint. Leaving it out makes the fingerprint one value per product, so `current()` is a plain `where` instead of a per-row hash in PHP.
- **Enum `WebDiscoveryState`** added beside `WebFindingStatus` for `web_discoveries.state`.
- **One service, thin jobs.** The steps live in `App\Services\ShopDiscovery\WebShopDiscovery`; `DiscoverWebShops`, `ReadWebFinding` and `CheckWebFindings` only load the model and hand in, so each step is testable without a queue.
- **Job limits.** `DiscoverWebShops` and `CheckWebFindings` run once (`#[Tries(1)]`, `#[Timeout(75)]`) under the 90 s `retry_after`; a failed run is picked up by the daily command. `ReadWebFinding` has `#[Timeout(60)]`, `tries()` = `read_attempts` and `uniqueFor()` above the sum of its delays.
- **Retry tests.** The read retries are tested on the service (`read()` returns the delay, the attempt count, the give-up), and the job's `tries()`, `uniqueFor()` and `failed()` directly, not through a real queue worker: the test suite runs the sync queue, where `release()` does not requeue.
- **Read cap failure.** A finding over the per-run read cap is `rejected` with `failure` = `read_cap`, so it is told apart from a low first check.
- **Hosts on `not_a_shop`** added from the prototype run: `supermarktscanner.nl`, `fatsecret.nl`, `beeradvocate.com`, `techradar.com`.
- **"Looking for more shops…" outside the disclosure.** With checkjebon rows present, the "Also sold at" disclosure is closed by default, and a line inside it was never seen. The line sits under the disclosure instead. Found by the eye-verify run.
- **Eye-verify:** `.github/eye-verify/web-suggestions.mjs` with its seed script (12 checks passed on 2026-09-30): the looking line and the poll, a finding proposed while the page is open appearing without a reload, the poll stopping, the row's text, Hide in both copies of the panel. Not driven in the browser: clicking Add on a web row, which would read a real shop's page; the component test covers the dispatch.
- **Duplicate page not tested.** The spec's "already tracked under another path" row reaches `ProbeShopUrl`'s duplicate answer only for a URL whose host the product tracks, and the result filter drops those before any read. A duplicate still ends `unreadable` through the generic failure branch.
- **Full suite:** 3,206 passed, 1 skipped, on 2026-09-30, run with `--no-tia` (the local TIA mode replays unaffected tests from cache). The one failure then was `ShoppingListTest` in another session's uncommitted shopping-list work. Earlier, 3,180 passed. The one failure was `PasswordManagerSupportTest` asserting the palette's old placeholder, "Search pages and recent products…", which commit 54e25c3 changed. The test now asserts the current text.
- **Evaluation fixes (2026-09-30).**
  - **Search limit and gate:**
    - A search limit of zero lifts the cap in the daily command too, as it does in `WebSearches`.
    - The command warns when discovery is on but a search or TypeSafe key is missing.
  - **Read cap:** the cap counts every finding granted a read (first check at or above `read_from`, except `read_cap` rejections), including pages rejected after the read.
  - **Stale search:** discovery without a new search (limit spent, provider down) still finishes the stored findings.
  - **Serper answers:**
    - A Serper answer without an `organic` list, or with rows none of which has a link, throws `WebSearchFailed` instead of being stored as "nothing found".
    - A 401, 402 or 403 from Serper logs as an error.
  - **Second-check claims:** they carry `claimed_at`. A failed or killed check puts back only claims older than 120 s, so a sibling check keeps its own. A check killed at its timeout is recovered on the next run.
  - **Queue and timeouts:**
    - `read_retries` is renamed `read_attempts` (total reads).
    - The lock wait is 10 s, so a waiter's own search and first check fit the 75 s job timeout.
  - **Panel:**
    - It polls with `wire:poll.visible`, so the copy in the closed add-shop form does not poll.
    - A `role="status"` region announces the search and its result.
    - The buttons name the shop to screen readers.
    - Dark-mode text uses zinc-400.
    - A barcode-shortcut row says "same barcode as your product", not "checked by AI".
- **Prototype removed.** `FindShopsCommand` and its classes are gone. The receipt-import prototype (`MatchListCommand`, `app/Services/ListImport/`) stays uncommitted; it is out of scope (§6).
- **Claim check (2026-09-30).** A fresh-context pass traced the spec's statements about the code. Corrected in the text above: the fingerprint has no URL; a failed search does not end the run; `failed()` of the second check puts back only stale claims; the provider binding; the `results` and `failure` columns; the search-order rank of an unfinished product with a stale search; the read time per page. A start-over delete now skips a row hidden after its select. Kept as it is: `ReadWebFinding`'s 60 s can be outrun by a page with many redirects or slow robots.txt hosts; the kill uses one try, and the third ends `unreadable`.
- **Known gap: "Looking for more shops…" can stay for a day.** When Jev is down, or the account's check budget is spent, findings stay `new` or `read` and the state stays `running` until the daily run. The panel says it is looking all that time. Raised with the user.

### Spec review, 2026-09-30

Evaluated against the code, then three Codex review rounds (the cap). Changes they led to:

- Evaluation: the fingerprint left out tracked shop URLs (adding one shop voided every suggestion); the empty state and accordion count also count web rows; manual products without a shop and non-EUR products are skipped; stale fingerprints are picked up by the daily command.
- Round 1: unfinished work picked up again; a refreshed search starts findings over; a lock on the query hash; three short jobs under the queue's `retry_after`; page barcode and read state stored; barcodes guarded; Hide survives a running job and refreshes both component instances; polling for an open panel; the redirect destination stored.
- Round 2: shared-search refreshes reach every product through `web_discoveries.search_searched_at`; stale fingerprints start over; writes guarded by generation; an atomic search-limit increment (`RateLimiter::attempt()` counts after it checks); a queued state before the first job; barcodes out of the fingerprint; `add_url` and `served_host` apart; `#[Tries]`, `uniqueFor()` and `failed()` for reads; logical-check budgeting; consumer-price rejection.
- Round 3: separate budget counters instead of an undefined two-check reservation; a completion rule in every job; the command selects products whose shared search another product refreshed; refreshed titles and snippets; the second check claims rows instead of a uniqueness lock; barcode corrections through `checked_gtins`; partial Jev answers.
- Round 3's fixes were not reviewed again; the review cap is three rounds.

### Prototype, 2026-09-30

20 dev products, one Serper search each (`gl=nl`, 10 results):

- 120 new shop pages found; the first check alone proposed 29, about 21 right.
- With the page read and second check: 62 pages read, 37 read, 25 failed (7 blocked, 9 no product data, 3 rate-limited, 3 several variants, 2 not servable, 2 temporary). 21 proposed, 18 right.
- Wrong: two event-rental sites (Fanta Cassis), one other-brand creatine for a title without a brand.
- Missed: plein.nl pages without a stated pack size, dropped because the page title lost the size the search title had.
- Sources compared and rejected before this: checkjebon alone (supermarkets only), shop sitemaps (legal risk, no pack size), Web Data Commons (research-only terms, sparse, a third of URLs dead), Common Crawl URL index (sparse), shop search pages (mostly blocked or JavaScript-rendered), DuckDuckGo (terms forbid automated queries).
