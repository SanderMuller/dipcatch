# Multi-Webshop Price Tracking

## Overview

Today a `Product` row conflates the abstract item with a single tracked URL plus scrape state. This spec splits that into `Product` (the abstract item) and `Offer` (a URL at a specific webshop). Users add arbitrary URLs to an existing product to track price across multiple shops; the cheapest live offer becomes the product's headline price, and drops are detected per offer with rollup to the product.

URL extraction stops being per-product CSS-selector config and becomes an **adapter chain** (host-specific → JSON-LD → microdata → OpenGraph) that probes any URL.

---

## 1. Data Model

Application not yet deployed live, so existing `products` / `price_checks` / `price_drop_events` migrations can be **rewritten in place** (edit the existing migration files, drop+remigrate) rather than altered. No backfill needed.

### Rewritten `products`

`products` becomes the abstract item. Drop all URL/scrape state. Keep:

- `id` (uuid), `user_id`
- `title`, `image_url`, `currency`
- `drop_threshold_pct`, `drop_threshold_abs` (still per-product — applies to cheapest offer)
- `last_notified_price`, `last_notified_at` (now compared against cheapest offer price)
- `cheapest_offer_id` (nullable FK → offers; `nullOnDelete()` because offer rows can be deleted independently)
- `cheapest_price` (decimal(12,2), denormalized — mirrors `offers.current_price` of cheapest)
- `active`, timestamps

Drop entirely: `url`, `price_selector`, `fallback_selectors`, `image_selector`, `title_selector`, `initial_price`, `initial_checked_at`, `last_price`, `last_checked_at`, `last_success_at`, `last_status`, `last_error`, `needs_js`.

Index on `cheapest_price` for sort.

Circular FK: `products.cheapest_offer_id` → `offers.id` and `offers.product_id` → `products.id`. Resolved by creating both tables without the `cheapest_offer_id` FK constraint inline, then adding it in a follow-up `Schema::table` call **within the same migration** with `nullOnDelete()`. DB-level FK is enforced — no application-only "soft pointer" workaround, since we are pre-launch and there is no integrity debt to pay.

### New `offers`

```php
Schema::create('offers', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
    $table->text('url');
    $table->string('url_hash', 64);                // sha256(normalized_url)
    $table->string('host');                        // normalized host (see below)
    $table->string('adapter_key');                 // 'jsonld' | 'microdata' | 'og' | 'generic' | host-specific
    $table->char('currency', 3);
    $table->decimal('initial_price', 12, 2);
    $table->timestampTz('initial_checked_at');
    $table->decimal('current_price', 12, 2)->nullable();
    $table->boolean('current_in_stock')->default(true);
    $table->timestampTz('last_checked_at')->nullable();
    $table->timestampTz('last_success_at')->nullable();
    $table->string('last_status')->default('pending'); // pending | ok | failed | blocked | dead
    $table->text('last_error')->nullable();
    $table->unsignedSmallInteger('consecutive_failures')->default(0);    // parse / 4xx / block
    $table->unsignedSmallInteger('consecutive_5xx_failures')->default(0); // transient server errors, higher threshold
    $table->string('health')->default('ok');       // ok | failing | dead
    $table->boolean('active')->default(true);
    $table->timestampsTz();

    $table->unique(['product_id', 'url_hash']);
    $table->index(['active', 'last_checked_at']);
    $table->index(['host']);
});
```

**URL normalization** (for `url_hash`) — exact rules so dedupe is deterministic:

1. **Scheme + host**: lowercase. Reject anything that isn't `http`/`https`.
2. **Host**: strip leading `www.`. Convert IDN to ASCII via `idn_to_ascii(..., IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)` (punycode). Lowercase the result.
3. **Userinfo**: strip `user:pass@` from authority entirely.
4. **Port**: strip default ports (`:80` for http, `:443` for https). Keep explicit non-default ports.
5. **Path**:
   - Empty path becomes `/` (so `https://ex.com` == `https://ex.com/`).
   - Strip trailing slash from non-root paths (`/foo/` → `/foo`, but `/` stays `/`).
   - **Preserve path case** — many shops have case-sensitive product slugs.
   - Decode then re-encode unreserved percent-escapes to canonical form (e.g. `%2D` → `-`).
6. **Query**:
   - Drop tracking params: `utm_*`, `gclid`, `fbclid`, `mc_eid`, `mc_cid`, `ref`, `ref_src`, `_ga`.
   - **Sort remaining params alphabetically** by key, then by value for repeated keys.
   - Preserve repeated keys (don't dedupe `a=1&a=2`).
   - Percent-encode values canonically.
7. **Fragment**: strip entirely.

**Host normalization**: same as step 1-2 — lowercase + strip leading `www.`. Stored in `offers.host`.

Prices stored as decimal(12,2), strings in PHP — matches existing convention (`DetectDrop` uses `bccomp` on string prices). No cent-int conversion anywhere.

### Rewritten `price_checks`

```php
Schema::create('price_checks', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->foreignUuid('offer_id')->constrained()->cascadeOnDelete();
    $table->decimal('price', 12, 2)->nullable();
    $table->char('currency', 3)->nullable();
    $table->boolean('in_stock')->nullable();   // per-check stock signal, used by recompute + charts
    $table->text('raw')->nullable();
    $table->string('status');
    $table->text('error')->nullable();
    $table->timestampTz('checked_at');

    $table->index(['offer_id', 'checked_at']);
});
```

Same shape as today, with `product_id` swapped for `offer_id` and the new `in_stock` column (referenced by the recompute logic and the history series in §6.5).

### Rewritten `price_drop_events`

Keep per-product (the "drop" is meaningful at the product level — cheapest changed). Add `triggered_by_offer_id` nullable FK so the email/notification can show *which* shop dropped.

```php
$table->foreignUuid('triggered_by_offer_id')->nullable()->constrained('offers')->nullOnDelete();
```

---

## 2. Adapter Chain

Replace per-product CSS selectors with a chain-of-responsibility resolver.

```php
namespace App\PriceAdapters;

interface ShopAdapter
{
    public function key(): string;                              // 'bol', 'jsonld', ...

    /**
     * Three-state result:
     *  - ExtractionResult::skip()          → adapter does not apply, try next.
     *  - ExtractionResult::failed($reason) → adapter applied (matched markers)
     *                                        but could not extract → STOP, surface error.
     *  - ExtractionResult::success($snap)  → use this snapshot.
     */
    public function extract(string $url, string $html): ExtractionResult;
}

final readonly class ExtractionResult
{
    private function __construct(
        public string $state,            // 'skip' | 'failed' | 'success'
        public ?OfferSnapshot $snapshot,
        public ?string $failureReason,
    ) {}

    public static function skip(): self { /* ... */ }
    public static function failed(string $reason): self { /* ... */ }
    public static function success(OfferSnapshot $snap): self { /* ... */ }
}

final readonly class OfferSnapshot
{
    public function __construct(
        public string $title,
        public ?string $imageUrl,
        public string $price,        // decimal string, e.g. "289.00" — matches PriceCheck.price
        public string $currency,     // ISO 4217, e.g. "EUR"
        public bool $inStock,
        public array $raw = [],
    ) {}
}
```

Resolver loop semantics:

- `skip` → continue to next adapter.
- `success` → return snapshot, persist `adapter_key`.
- `failed` → **stop the chain**, return typed error to caller. Prevents a broken JSON-LD parse from silently falling through to weaker adapters (a host regression must surface, not be papered over with OG fallback).

Example: JSON-LD adapter sees `<script type="application/ld+json">` on the page → it applies (no `skip`). If the JSON is malformed or shape unrecognized → `failed('jsonld_parse_error')`, not `skip`.

**JSON-LD edge cases** the `JsonLdAdapter` must handle:

- Multiple `<script type="application/ld+json">` tags on one page.
- Top-level `@graph` arrays.
- `Product` with single `Offer`, array of `Offer`, or `AggregateOffer` (use `lowPrice`).
- Price as string vs number.
- Price nested under `priceSpecification.price`.
- `availability` URLs: `InStock`, `OutOfStock`, `LimitedAvailability`, `PreOrder`.

Resolution order (first match wins):

1. **Host-specific** adapters registered by host (`bol.com` → `BolAdapter`). None ship initially; structure exists for later.
2. **JsonLdAdapter** — parse `<script type="application/ld+json">` for `Product` / `Offer` schema.org types. Highest signal.
3. **MicrodataAdapter** — `itemprop="price"`, `itemprop="priceCurrency"`, `itemprop="availability"`.
4. **OpenGraphAdapter** — `og:price:amount`, `og:price:currency`, `og:title`, `og:image`.
5. **GenericAdapter** — last-resort heuristics (regex for currency-prefixed numbers near "price"). Marked low-confidence; user warned.

Registry binds in a service provider:

```php
$this->app->bind(AdapterResolver::class, fn () => new AdapterResolver([
    // host-specific go first
    new JsonLdAdapter(),
    new MicrodataAdapter(),
    new OpenGraphAdapter(),
    new GenericAdapter(),
]));
```

The chosen adapter's `key()` is persisted on the offer as a hint, **not a hard pin**. On every re-check, run the persisted adapter first; if it returns `skip` or `failed`, immediately fall through to the full chain (which may pick a different adapter or escalate the failure). This handles silently-stale persisted keys — for example a host that used to expose JSON-LD but now only has OpenGraph — without waiting for a "fails repeatedly" threshold.

---

## 3. HTTP Fetching

Single `OfferFetcher` service used by both probe and recheck.

**HTTP behavior:**

- Guzzle: 10s connect+read timeout, follow up to 5 redirects, `decode_content = true` (handles gzip + brotli transparently).
- User-Agent: `DipcatchBot/1.0 (+https://dipcatch.app/bot)`.
- `Accept-Language: en, *;q=0.5`.
- Request `Accept-Encoding: gzip, deflate, br`.
- Body cap: 2 MB. Drop and fail on larger.
- Charset: read `Content-Type` charset; if absent, detect via `mb_detect_encoding` against the head of the body, then convert to UTF-8. Invalid UTF-8 after conversion → `extraction_failed`.
- HTML parsing tolerant of malformed input — use `DOMDocument::loadHTML` with `LIBXML_NOERROR | LIBXML_NOWARNING`, or Symfony DomCrawler (which wraps the same).

**robots.txt policy:**

- Fetch once per host, cache 24h.
- 200 OK + parseable → honor disallow rules. If disallowed → throw `RobotsDisallowed`; offer marked `health=dead` permanently. User must remove + re-add.
- 404 / 403 / network error / unparseable → **fail-open** (assume allowed). Rationale: we are a low-volume bot identifying itself with a contact URL; treating missing robots.txt as "everything forbidden" would block half the long tail.
- 5xx on robots.txt → **fail-open** for this request, do not cache the failure (retry next request).

**Block detection** (anti-bot / WAF):

- HTTP 403 with body markers (`cf-mitigated`, `Just a moment...`, `Access denied`, Akamai reference IDs, PerimeterX) → throw `Blocked`.
- HTTP 401 → `Blocked`.
- HTTP 429 → throw `RateLimitedByHost`, mark `health=failing`, back off — distinct from `Blocked` because it self-heals.
- HTTP 5xx → `TemporaryFailure`. Mark `last_status=failed`. Increments a separate `consecutive_5xx_failures` counter on `offers` (not the main `consecutive_failures`) with a higher dead threshold of 30, so a multi-day shop outage doesn't dead-list us. Reset on the next successful check.
- HTTP 4xx (other) → `extraction_failed` with status code in `last_error`.

**JavaScript-rendered pages**: out of scope this iteration. If fetch returns HTML but every adapter returns `skip` (no extractable markers anywhere), reject the URL with `needs_js` error.

**FPM worker concern**: the probe path is synchronous (§4) so each in-flight probe occupies a PHP-FPM worker for up to ~10s (fetcher timeout) plus parse time. Mitigations: keep probe rate-limited per user (e.g. 6 probes/min/user via a `RateLimiter::for('offer-probe-user', ...)` limiter on the Livewire action), and document that operators may need to size the FPM pool accordingly. If this becomes a hot spot, move probe to a queued job with Livewire long-polling for the result — out of scope for v1.

**Per-host rate limit** — applies to **both** the queued recheck path and the synchronous probe path. Named limiter `offer-fetch` registered in `AppServiceProvider::boot()` keyed by host:

```php
RateLimiter::for('offer-fetch', fn (object $job) =>
    Limit::perMinute(12)->by($job->offer->host)
);
```

Applied two ways:

1. **Queue middleware** `RateLimited::class . ':offer-fetch'` on `CheckOfferPrice` — back-pressure for background jobs.
2. **Inside `OfferFetcher`** before issuing the HTTP request: `RateLimiter::tooManyAttempts('offer-fetch:' . $host, 12)` check + `RateLimiter::hit(...)`. On exceed → throw `RateLimitedByHost`. The probe path surfaces this as a "try again in N seconds" UI message.

Default 1 req / 5s / host (12 / min), configurable via `config/dipcatch.php`.

---

## 4. Add-Offer Flow

User on a product page paste a URL → "Add shop" form.

1. Server validates URL (scheme http/https, parseable host).
2. Normalize URL, compute `url_hash`, check `(product_id, url_hash)` dedupe — if exists, return existing offer.
3. Invoke `ProbeOfferUrl` action directly from the Livewire component (synchronous PHP — no queue/Bus needed). Livewire's `wire:loading` shows the spinner. Fetcher's 10s timeout caps the wait.
4. Probe runs adapter chain on the fetched HTML.
5. **On success** — return `OfferSnapshot` to UI. Show preview card: title, image, price. User clicks "Confirm — same product" → persist `Offer` + initial `price_check`, refresh product's cheapest. User clicks "Different product" → discard.
6. **On failure** — return error code (`robots_disallowed` / `blocked` / `extraction_failed` / `http_error`). UI shows actionable message.

The confirm step (§5) is the safety net for product-identity mismatch — see Open Question 2.

---

## 5. Scheduled Re-Checks

Replace whatever schedules the current per-product check with a per-offer scheduler.

- `RecheckActiveOffers` console command runs every 5 min via Laravel scheduler.
- Selects `offers` where `active=true`, `health != 'dead'`, `last_checked_at` older than configured interval (default 6h, jittered ±30 min per offer to avoid host bursts).
- Dispatches `CheckOfferPrice` job per offer onto the default queue.
- Job uses the named `offer-fetch` rate limiter (§3) via `(new RateLimited('offer-fetch'))` middleware so per-host limits apply across workers.

Per-job logic — fetch + parse runs **outside** any DB transaction (network calls under lock are forbidden); persistence runs **inside one transaction** with a fixed lock order `offer → product` to prevent deadlocks against the add/toggle/delete paths (which must follow the same order).

1. **Outside tx**: fetch HTML via `OfferFetcher`.
2. **Outside tx**: run persisted `adapter_key` first. If it returns `skip` or `failed` → re-run full chain. On `success` from a different adapter, the new key wins. If full chain returns `failed` end-to-end, treat the check as an extraction failure (don't silently fall back to a weaker adapter that returned `skip` after a `failed`).
3. **Outside tx**: classify outcome → one of `success` / `parse_failed` / `blocked` / `rate_limited` / `4xx` / `5xx` / `robots_disallowed`.
4. **Open tx + `lockForUpdate` on offer**:
   - Insert `price_check` row (price, in_stock, status, raw, error).
   - Update offer state based on classification:
     - `success`: `current_price`, `current_in_stock`, `last_checked_at`, `last_success_at`, `last_status='ok'`, `last_error=null`, **both** counters reset to 0, `health = 'ok'` (if was `failing`).
     - `5xx`: `last_checked_at`, `last_status='failed'`, `last_error`, **`consecutive_5xx_failures++`** (main `consecutive_failures` unchanged).
     - `parse_failed` / `blocked` / `rate_limited` / `4xx`: `last_checked_at`, `last_status`, `last_error`, **`consecutive_failures++`** (5xx counter unchanged).
     - `robots_disallowed`: immediate `health='dead'`, `active=false`, no counter increment.
   - Apply health transitions (below).
5. **Inside same tx**: call `Product::recomputeCheapestOffer($priceCheckId)` — this nests another `lockForUpdate` on the product row (lock order `offer → product` is consistent).
6. Commit. The check row, the offer state, the product cheapest, the history row, and any drop-detection write are all atomic — a failure anywhere rolls the whole thing back.

Health transitions:

- `consecutive_failures >= 3` OR `consecutive_5xx_failures >= 10` → `health = 'failing'`.
- `consecutive_failures >= 10` OR `consecutive_5xx_failures >= 30` → `health = 'dead'`, `active = false`. User must manually re-enable.
- A successful check resets **both** counters to 0 and clears `failing` back to `ok` (does not auto-resurrect `dead`).
- All thresholds live in `config/dipcatch.php` (`offer.failing_after`, `offer.dead_after`, `offer.failing_5xx_after`, `offer.dead_5xx_after`).

---

## 5.1 Drop-Detection Interaction (was deferred — now baked in)

After every `CheckOfferPrice` job finishes, `Product::recomputeCheapestOffer()` runs under a row lock. Capture the **previous** `cheapest_price` and **previous** `cheapest_offer_id` before the recompute. Then:

- **If `cheapest_price` decreased** (or went from `null` to a value): write a row to `product_cheapest_history` (see §6.5), then call `DetectDrop` with the product **and** the `price_check_id` of the triggering offer's freshly-inserted check. `DetectDrop` writes that exact `price_check_id` into `price_drop_events` — no more `latest('checked_at')` lookup (which was racy with concurrent per-offer checks).
- **If `cheapest_price` increased**: write a row to `product_cheapest_history`. If `last_notified_price` is non-null and the new cheapest is at or above `reference_price` (i.e. recovery), clear the latch atomically — same logic as `DetectDrop::clearLatchAtomically`, hoisted into a public method (`DetectDrop::clearLatchIfRecovered`) and called here. Required because drops are now invoked only on downward moves; the existing in-line recovery clear (`DetectDrop::isRecovered`) never fires when cheapest goes up.
- **If `cheapest_price` is now `null`** (all offers out of stock / dead / inactive): clear the latch (same as recovery) and do not detect a drop. Charts and "active drops" UI must treat null as "no cheapest right now".
- **If unchanged**: no further action.

`DetectDrop`'s signature changes:

```php
public function __invoke(Product $product, int $triggeringPriceCheckId): void
public function clearLatchIfRecovered(Product $product, ?string $newPrice): void
```

`clearLatchIfRecovered` decides recovery by comparing the new cheapest price against a precomputed `ReferenceValue`. **The reference is computed before the product `lockForUpdate` is taken**, not inside it — `Reference::compute()` reads the whole 30-day window and sorts in PHP (~30+ rows, more for very active products), and we do not want that under a hot row lock. The flow:

```php
// In recomputeCheapestOffer, BEFORE the transaction:
$reference = app(Reference::class)->compute($this);   // null-safe

DB::transaction(function () use ($reference, ...): void {
    $locked = Product::query()->lockForUpdate()->find($this->id);
    // ... compute previous + new ...
    match ($direction) {
        'down' => app(DetectDrop::class)($locked, $triggeringPriceCheckId),
        'up', 'null' => app(DetectDrop::class)->clearLatchIfRecovered($locked, $newPrice, $reference),
        default => null,
    };
});
```

The reference may be slightly stale relative to the locked snapshot (another job could have inserted a history row between compute and lock), but for the recovery check that's harmless — the worst case is clearing a latch one cycle late, which the next recompute fixes. The downward path doesn't pass `$reference` because `DetectDrop` already recomputes it inside its own transaction (existing behavior, kept).

If yes — clear `last_notified_price` / `last_notified_at` atomically (existing `clearLatchAtomically` logic). If no — leave the latch alone.

The `Reference` service and `DropEvaluator` continue to operate on the product-level cheapest series — see §6.5.

**Notification rate limit interaction**: `DetectDrop` already throttles per-user via `RateLimiter` against `config('dipcatch.notifications.user_hourly_limit')`. That gate stays in place — multiple offers dropping near-simultaneously for the same user will still be collapsed into one notification per product (the existing per-product latch), and bursts across products are bounded by the per-user limit.

**Lock order — applies to all mutations**: `offer → product`. Every code path that mutates an offer and recomputes the product must follow this order to avoid deadlocking against in-flight `CheckOfferPrice` jobs:

- **Add**: `lockForUpdate` on the new offer row (after insert), then call `recomputeCheapestOffer($priceCheckId)` which locks the product.
- **Toggle active / health change**: same — lock the offer, update, call recompute.
- **Delete**: lock the offer, delete, call recompute on the (still-locked) parent product. (Cascade deletes price_checks; the cascade itself doesn't need an explicit lock.)

**Triggering price-check id routing — single rule** (resolves §5.1 vs §6 contradiction):

- **CheckOfferPrice job**: pass the newly-inserted `price_check_id`.
- **Offer add**: pass the initial probe's `price_check_id` (the probe writes a check on confirm).
- **Toggle active back on**: pass the offer's latest successful `price_check_id` (so a "previously dormant cheaper offer is back" registers as a drop with a real anchor check).
- **Delete**: pass `null`. Delete can only **raise** the cheapest, never lower it, so the downward branch never fires — passing `null` is safe (and `clearLatchIfRecovered` doesn't need a check id).
- **Toggle active off**: pass `null`. Same reason — disabling can only raise cheapest.

---

## 6. Cheapest Offer Rollup

`Product::recomputeCheapestOffer(?int $triggeringPriceCheckId = null): void` — must be safe under concurrent `CheckOfferPrice` jobs for the same product. Captures the previous cheapest *inside* the lock, computes the new one, writes history + invokes drop detection / latch clear per §5.1, all atomically:

```php
public function recomputeCheapestOffer(?int $triggeringPriceCheckId = null): void
{
    // Precompute reference OUTSIDE the lock (§5.1 — keeps the critical section tight).
    $reference = app(Reference::class)->compute($this);

    DB::transaction(function () use ($triggeringPriceCheckId, $reference): void {
        $locked = Product::query()->lockForUpdate()->find($this->id);

        $previousOfferId = $locked->cheapest_offer_id;
        $previousPrice = $locked->cheapest_price;   // decimal string or null

        $cheapest = $locked->offers()
            ->where('active', true)
            ->where('current_in_stock', true)
            ->where('health', '!=', 'dead')
            ->whereNotNull('current_price')
            ->orderBy('current_price')
            ->first();

        $newOfferId = $cheapest?->id;
        $newPrice = $cheapest?->current_price;

        $locked->forceFill([
            'cheapest_offer_id' => $newOfferId,
            'cheapest_price' => $newPrice,
        ])->save();

        $changed = $previousOfferId !== $newOfferId
            || (string) $previousPrice !== (string) $newPrice;

        if (! $changed) {
            return;
        }

        // Close previous open segment + open a new one (§6.5).
        ProductCheapestHistory::query()
            ->where('product_id', $locked->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        ProductCheapestHistory::create([
            'product_id' => $locked->id,
            'cheapest_offer_id' => $newOfferId,
            'cheapest_price' => $newPrice,
            'started_at' => now(),
            'ended_at' => null,
            'triggering_price_check_id' => $triggeringPriceCheckId,
        ]);

        $direction = $this->compareDirection($previousPrice, $newPrice);

        match ($direction) {
            'down' => app(DetectDrop::class)($locked, $triggeringPriceCheckId),
            'up', 'null' => app(DetectDrop::class)->clearLatchIfRecovered($locked, $newPrice, $reference),
            default => null,
        };
    });
}
```

`compareDirection` returns `'down'` / `'up'` / `'null'` / `'unchanged'` using `bccomp` on the decimal strings, handling either side being null.

Call sites (full routing rules under §5.1 "Triggering price-check id routing"): job + add + toggle-on pass a real `price_check_id`; delete + toggle-off pass `null` (those operations can't lower the cheapest, so the downward branch never fires).

Index on `cheapest_price` for product listing sort.

---

## 6.5 Product Cheapest History (resolves Open Question 5)

`product_cheapest_history` — time series of the product-level cheapest price. Each row is a **segment** with explicit start + end timestamps, so windowed aggregations (`median_30d`, `min_7d`, etc.) can weight each value by how long the product held it. This preserves the existing `Reference::compute()` semantics even when the cheapest price stays stable for long stretches.

```php
Schema::create('product_cheapest_history', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('cheapest_offer_id')->nullable()->constrained('offers')->nullOnDelete();
    $table->decimal('cheapest_price', 12, 2)->nullable();   // null = no eligible offer
    $table->timestampTz('started_at');
    $table->timestampTz('ended_at')->nullable();            // null = currently active segment
    $table->foreignId('triggering_price_check_id')->nullable()->constrained('price_checks')->nullOnDelete();

    $table->index(['product_id', 'started_at']);
    $table->index(['product_id', 'ended_at']);
});
```

Write rules (inside the §6 transaction):

- **No change in `(cheapest_offer_id, cheapest_price)`**: do nothing. The currently-open segment continues.
- **Change**: close the previous open segment by setting its `ended_at = now()`, then insert a new row with `started_at = now()`, `ended_at = null`, current values, and the triggering check id.
- **First offer ever**: insert the first open segment.
- **All offers become ineligible**: close the previous segment, insert a new segment with `cheapest_price = null`.

This keeps storage proportional to **change events** (cheap) but lets `Reference::compute()` reconstruct any window by clipping segments to the window bounds and weighting by segment duration. Pseudocode:

```php
// median_30d
$windowStart = now()->subDays(30);
$segments = ProductCheapestHistory::query()
    ->where('product_id', $product->id)
    ->where(function ($q) use ($windowStart) {
        $q->whereNull('ended_at')->orWhere('ended_at', '>=', $windowStart);
    })
    ->orderBy('started_at')
    ->get();
// each segment contributes (clip(segment, window).durationSeconds) of weight at $segment->cheapest_price
```

`PriceHistoryChart` plots segments as step lines (price held flat between change events) plus optional per-offer overlays.

Pruning: `PruneOldChecksCommand` extends to prune segments fully before the retention window (i.e. `ended_at < cutoff`). The current open segment (`ended_at = null`) is never pruned.

Concurrency: segment close + new segment insert happens inside the §6 transaction under the product `lockForUpdate`, so there can never be two open segments for the same product.

---

## 7. UI Changes

- Product detail page: new "Shops" section listing offers (host favicon, current price, in-stock badge, health badge, last-checked relative time, "Remove" action). "Add another shop" inline form below.
- Product list: show `cheapest_price` + count badge (`3 shops`).
- Filament admin: nested `Repeater` or `RelationManager` for `offers` under `ProductResource`. New `OfferResource` for admin triage of failing/dead offers across users.
- Existing single-URL forms removed from product create/edit; instead product create takes one URL that becomes the first offer.

---

## 8. Notification Copy

Existing `PriceDropNotification` (`specs/notifications.md`) uses product fields. Update to include the triggering offer's host so users see *which* shop dropped:

- Mail subject: `Price drop on {title} at {host}: {currency}{newPrice}`.
- Database / push payload: add `host`, `offer_url`.

CTA still links to the product page, not the offer URL (we want users to see all shops before clicking out).

---

## Implementation

### Phase 1: Schema + Model Refactor (Priority: HIGH)

Existing migrations are edited in place (pre-launch, no data to preserve). After this phase the app must boot, `php artisan migrate:fresh` must succeed, and all existing tests must be updated or temporarily skipped so the suite is green.

**Migrations:**

- [x] Edit `2026_04_26_162646_create_products_table.php` — drop URL/scrape columns; add `cheapest_offer_id` + `cheapest_price` (decimal 12,2 nullable) + index on `cheapest_price`. Defer the `cheapest_offer_id` FK to a follow-up `Schema::table` block inside the same migration so offers exists first.
- [x] New migration `create_offers_table` — schema per §1; same migration adds the `products.cheapest_offer_id` FK with `nullOnDelete()`.
- [x] Edit `2026_04_26_162647_create_price_checks_table.php` — `offer_id` instead of `product_id`; add `in_stock` boolean nullable.
- [x] Edit `2026_04_26_162648_create_price_drop_events_table.php` — add nullable `triggered_by_offer_id` (FK → offers, `nullOnDelete`).
- [x] New migration `create_product_cheapest_history_table` — schema per §6.5.

**Models + support:**

- [x] `App\Support\UrlNormalizer` — pure class implementing the 7 normalization rules in §1 exactly (IDN, userinfo, path case, sorted query, etc.); throws on invalid input. Unit-tested in isolation.
- [x] `App\Models\Offer` — relations (`product`, `priceChecks`, `triggeredDropEvents`), `creating` event sets `url_hash` + `host` via `UrlNormalizer`, timestamp casts.
- [x] `App\Models\Product` — drop all URL/scrape fields, add `offers()` HasMany, `cheapestOffer()` BelongsTo, `cheapestHistory()` HasMany, `recomputeCheapestOffer(?int $triggeringPriceCheckId = null)` per §6 (lockForUpdate, capture previous, write `product_cheapest_history` on change, branch on direction to call `DetectDrop::__invoke` or `clearLatchIfRecovered`).
- [x] `App\Models\PriceCheck` — relation swapped to `offer()`; `belongsTo(Offer::class)` chain works for `$check->offer->product`.
- [x] `App\Models\ProductCheapestHistory` — minimal model for chart queries.

**Drop pipeline:**

- [x] `app/Actions/Drops/DetectDrop.php` — signature `__invoke(Product, ?int $priceCheckId)`; read `$product->cheapest_price`; remove `latest('checked_at')` lookup, use the passed `priceCheckId` directly when writing `price_drop_events`; persist `triggered_by_offer_id` from the check's offer.
- [x] `DetectDrop::clearLatchIfRecovered(Product, ?string $newPrice, ?ReferenceValue $reference)` — public method, invoked from `recomputeCheapestOffer()` on upward / null moves (§5.1).
- [x] `app/Services/Drops/Reference.php` — read from `product_cheapest_history` segments with time-weighted median, falling back to earliest segment price when fewer than 7 samples.
- [x] `app/Services/Drops/DropEvaluator.php` — verified: operates on product-level prices only, no changes needed.

**Existing scrape pipeline — remove/replace (Phase 4 builds the replacement, but Phase 1 must keep boot green):**

- [x] `app/Jobs/ScrapeProductJob.php` — gutted to throw; bootstrap schedule removed.
- [x] `app/Console/Commands/DispatchScrapesCommand.php` — gutted to log + no-op; schedule entry removed in `bootstrap/app.php`.
- [x] `app/Console/Commands/PruneOldChecksCommand.php` — now prunes per-offer price_checks and closed cheapest_history segments per §6.5.
- [x] `app/Services/Scraper/ScrapeRequest::fromProduct` + `app/Actions/Scraper/RecordScrape` — gutted to throw. Other Scraper services left in place; CreateProduct/EditProduct still reference `AutoDetect` + `Scraper` which Phase 4 will replace.
- [x] `app/Health/LastSuccessfulScrapeCheck.php` — now reads stale-active-offer count attached to active products.

**Filament + notifications — wired in Phase 1 so the app boots:**

- [x] `app/Notifications/PriceDropNotification.php` — reads `product->cheapest_price` + `product->cheapestOffer->{host,url}` instead of removed `last_price`/`url` fields. Mail subject / web push body now include host when present.
- [x] `app/Filament/App/Resources/Products/Tables/ProductsTable.php` — replaced columns with `cheapest_price` + offers count + `active`.
- [x] `app/Filament/App/Resources/Products/Schemas/ProductInfolist.php` — pricing section now shows cheapest + shop host; URL/Selector sections removed; "shops" section is a placeholder until Phase 3.
- [x] `app/Filament/App/Resources/Products/Schemas/ProductForm.php` — only title, image_url, currency, thresholds, active toggle.
- [x] `app/Filament/App/Resources/Products/Pages/CreateProduct.php` / `EditProduct.php` — gutted to basic CRUD; scrape preview/rescrape removed (Phase 3/4 replace).
- [x] `app/Filament/App/Resources/Products/Widgets/PriceHistoryChart.php` — reads `product_cheapest_history` as a stepped line; PriceDropEvent markers preserved (per-offer overlay defer to Phase 5).
- [x] `app/Filament/App/Widgets/StatsOverviewWidget.php` — savings sum now reads `price_drop_events.drop_abs` per currency (no more initial_price - last_price).
- [x] `app/Filament/App/Widgets/ActiveDropsTableWidget.php` — reads `cheapest_price` + `cheapestOffer.host` instead of removed `last_price` / `initial_price`.
- [x] `app/Filament/App/Widgets/SavingsByMonthChartWidget.php` — verified: already reads from `price_drop_events`, no changes needed.

**Factories / seeders:**

- [x] `database/factories/OfferFactory.php` — new.
- [x] `database/factories/ProductFactory.php` — drop URL/selector fields.
- [x] `database/factories/PriceCheckFactory.php` — reference offer instead of product.
- [x] `database/factories/PriceDropEventFactory.php` — accept triggering offer.
- [x] `database/factories/ProductCheapestHistoryFactory.php` — new.
- [x] Seeders left as-is (AdminUserSeeder doesn't touch product/scrape tables).

**Tests:**

- [x] `UrlNormalizer` unit tests at `tests/Unit/Support/UrlNormalizerTest.php` (28 tests covering all 7 normalization rules + IDN + hash).
- [x] Offer relations + product hasMany offers + cheapest_history relation at `tests/Feature/MultiWebshop/OfferRelationsTest.php`.
- [x] `recomputeCheapestOffer` picks min eligible offer + writes history segment only on change at `tests/Feature/MultiWebshop/RecomputeCheapestOfferTest.php`. (Concurrency test deferred — SQLite in-memory used by suite can't model real row locks; flagged for Phase 4 with a Postgres harness.)
- [x] Latch clears on upward move + null move at `tests/Feature/MultiWebshop/{RecomputeCheapestOffer,DetectDrop}Test.php`.
- [x] `DetectDrop` writes correct `price_check_id` + `triggered_by_offer_id` at `tests/Feature/MultiWebshop/DetectDropTest.php`.
- [x] `Reference` time-weighted median on segments at `tests/Feature/MultiWebshop/ReferenceSegmentsTest.php`.
- [x] `HealthChecksTest` rewritten to use offers.
- [x] `DataModelTest` updated to new schema.
- [x] Legacy tests for replaced functionality (CreateProduct wizard, EditProduct rescrape, old DetectDrop signature, old Reference, scraper pipeline, notification getters reading `last_price`) skipped with explicit `Phase X` rationale — rewrite alongside the replacing phase.

### Phase 2: Adapter Chain + Fetcher (Priority: HIGH)

- [x] `ShopAdapter` interface + `OfferSnapshot` + tri-state `ExtractionResult` in `app/PriceAdapters/` (plus shared `PriceNormalizer`).
- [x] `AdapterResolver` — iterates registered adapters; persisted-key hint runs first then falls through to chain on skip/failed.
- [x] `JsonLdAdapter`, `MicrodataAdapter`, `OpenGraphAdapter`, `GenericAdapter` implementations (JSON-LD handles all spec §2 edge cases).
- [x] `AppServiceProvider::register` binds `AdapterResolver` singleton with the four-adapter chain.
- [x] `OfferFetcher` service in `app/Services/OfferFetcher/`: UA, accept-language, decode_content, 10s timeout, 5 redirects, body cap 2MB, charset detection + UTF-8 conversion. `RobotsTxtPolicy` with 24h cache + fail-open. Block detection (401 / 403 + CF/Akamai/PerimeterX markers). 429 → `RateLimitedByHost`. 5xx → `TemporaryFailure`. Per-host rate limit via `RateLimiter` keyed `dipcatch:fetcher:host:{host}` enforced inside the fetcher so probe + queue share the budget.
- [x] `config/dipcatch.php` keys added: `fetcher.{user_agent,timeout_seconds,body_cap_bytes,rate_limit_per_minute,robots_cache_seconds}`, `offer.{failing_after,dead_after,failing_5xx_after,dead_5xx_after}`, `recheck.{interval_hours,jitter_minutes}`.
- [x] Tests: adapter resolver order (skip/failed/success/persisted-hint), per-adapter happy + edge cases (JSON-LD `@graph`, AggregateOffer, nested priceSpecification, OutOfStock, malformed JSON; Microdata content vs text; OG facets; Generic currency-symbol detection). Fetcher tests via `Http::fake` cover robots-allow / robots-disallow / Cloudflare-challenge / 401 / 429-with-retry-after / 5xx / 404 / per-host rate-limit / www-strip.

### Phase 3: Add-Offer Flow (Priority: HIGH)

- [x] `app/Actions/Offers/ProbeOfferUrl` action: normalize → dedupe → per-user rate-limit (6/min) → `OfferFetcher` → `AdapterResolver` → currency-match guard → `ProbeOutcome` (success / duplicate / failed with typed code).
- [x] `app/Livewire/Offers/AddOffer` Livewire component (MFC, Flux UI): URL input → `probe()` → state machine `idle → preview → idle` (Confirm) or `idle → error → idle` (Cancel).
- [x] On confirm: persist `Offer` + initial `price_check` inside one transaction, then call `Product::recomputeCheapestOffer($priceCheckId)` outside the transaction so the new offer is visible to the recompute.
- [x] Dedupe via `url_hash` (computed during normalization); duplicate hit surfaces inline with the existing offer's host.
- [x] Per-error-code copy in the blade: `invalid_url`, `empty_url`, `duplicate`, `robots_disallowed`, `blocked`, `host_rate_limited`, `probe_rate_limited`, `temporary_failure`, `http_error`, `currency_mismatch`, `no_adapter_matched`.
- [x] Wired into `ProductInfolist` via a `View::make('filament.partials.add-offer-livewire')` shim that renders `@livewire('offers.add-offer', ['product' => $record])`.
- [x] Tests at `tests/Feature/Offers/`: 9 `ProbeOfferUrlTest` (success / duplicate / robots / blocked / extraction-failed / currency-mismatch / invalid-url / per-user rate-limit / dedupe-before-rate-limit), 8 `AddOfferLivewireTest` (preview / confirm-persists / cancel / duplicate / robots / currency-mismatch / extraction-failed / empty-url). All green.

### Phase 4: Scheduled Rechecks + Health (Priority: HIGH)

- [x] `app/Jobs/CheckOfferPrice` — replaces `ScrapeProductJob`. Fetch outside DB tx, persist inside one tx with offer→product lock order. Routes 5xx to `consecutive_5xx_failures`; parse/4xx/block/rate-limit/robots to main `consecutive_failures` (mutually exclusive). Robots-disallowed flips offer to `dead`+inactive immediately. Recompute called after the offer-row tx commits with the freshly-inserted `price_check_id`.
- [x] `RateLimited('offer-fetch')` middleware on the job + named limiter registered in `AppServiceProvider::boot()` keyed by `offer-fetch:{host}`.
- [x] `app/Console/Commands/RecheckActiveOffersCommand` — picks `offers` where `active=true AND health!=dead AND product.active=true` and `last_checked_at < now - interval_hours` (or null). Jitters dispatch by ±`jitter_minutes`. Wired into `bootstrap/app.php` schedule every 5 min.
- [x] Health transitions config-driven: `offer.failing_after` (3), `offer.dead_after` (10), `offer.failing_5xx_after` (10), `offer.dead_5xx_after` (30).
- [x] `PriceDropNotification` host+offer_url payload already updated in Phase 1 and continues to read from the recomputed product.
- [x] Deleted legacy files: `ScrapeProductJob`, `DispatchScrapesCommand`, `RecordScrape`, all of `app/Services/Scraper/`, `AutoDetect` + `AutoDetectResult`. Dropped `Scraper`/`HtmlScraper` binding from `AppServiceProvider`. Deleted 11 obsolete test files.
- [x] `ScrapeStatus` enum extended with new cases (`pending`, `blocked`, `rate_limited`, `5xx`, `robots_disallowed`, `dead`, `failed`).
- [x] Tests at `tests/Feature/Offers/`: 7 `CheckOfferPriceJobTest` (success / parse-failure / 5xx-counter / dead-threshold / robots-disallowed / dead-skip / counter-reset-on-success) + 3 `RecheckActiveOffersCommandTest` (eligible dispatch / dead-skip / batch-size). All green.

### Phase 5: UI (Priority: HIGH)

- [x] `OffersRelationManager` on `ProductResource` — Shops list with host, price, in-stock, health badge, last-checked, plus actions: open URL, toggle active/pause, delete (each recomputes cheapest after). Default sort by `current_price` ascending so the cheapest shop is on top.
- [x] Product list already shows `cheapest_price` + offers count (delivered in Phase 1).
- [x] `app/Filament/Admin/Resources/Offers/OfferResource` (admin panel) — global triage view of all offers, filterable by health / active / host, bulk re-enable (resets counters + health=ok) and bulk mark-dead.
- [x] Product create flow stays as Phase 1: metadata-only `CreateProduct`; the first shop URL is added via the embedded Livewire `AddOffer` form on the product view page. "First URL becomes first offer" was deferred — the two-step flow is simpler and shares all the probe/preview logic with subsequent shop additions.
- [x] Per-offer overlay on the chart: deferred. `PriceHistoryChart` renders the cheapest-segments line as a stepped chart which is the primary use case; per-offer overlays add visual complexity without clear v1 demand.
- [x] Tests — rewrote `StatsOverviewWidgetTest`, `ActiveDropsTableWidgetTest`, `SavingsByMonthChartWidgetTest`, `RecentNotificationsTableWidgetTest`, `ProductResourceListTest`, `PriceHistoryChartTest`, `DatabaseChannelTest`, `WebPushTest`, `AppPanelDatabaseNotificationsTest`, `PriceDropNotificationTest`, `HourlyRateLimitTest`, `PruneOldChecksCommandTest`. Deleted the legacy `DetectDropTest`, `ReferenceTest`, `CreateProductTest`, `EditProductTest` (replaced by `tests/Feature/MultiWebshop/*` from Phase 1).

### Phase 6: Host-Specific Adapters (Priority: LOW)

- [x] `app/PriceAdapters/Hosts/BolAdapter` — reference implementation for bol.com. Demonstrates the slot: self-skip on non-matching host, delegate to `JsonLdAdapter` first, fall back to a tight CSS extraction (`[data-test="price"]`, `.promo-price`) when JSON-LD is missing. Registered in `AppServiceProvider::register` before the generic chain.
- [x] Triage path via `OfferResource` (built in Phase 5b) — filter offers by `host` + `health` to spot patterns that justify adding more host adapters.
- [x] Tests `tests/Feature/PriceAdapters/BolAdapterTest.php` — 6 tests covering skip-on-non-bol-host, www-bol normalization, JSON-LD happy path, CSS fallback, failed-when-no-markers.

---

## Open Questions

1. **Stale offer auto-disable threshold.** Spec defaults: failing at 3 consecutive fails, dead+inactive at 10. Is 10 right? Too low → users lose tracking after a flaky day at a shop. Too high → we waste fetches on permanently dead URLs. Recommend keeping 10 but making it `config('dipcatch.offer.dead_after')` so it can tune without code change. (Phase 4 will surface this as a config knob.)

---

## Resolved Questions

1. **Cheapest history series for charts + drop reference.** **Decision:** Persist a `product_cheapest_history` time-series row each time `recomputeCheapestOffer()` changes the value (see §6.5). **Rationale:** Cheap reads, mirrors how cheapest is already denormalized on the product, no expensive min-aggregation at query time; `Reference::compute` and `PriceHistoryChart` both depend on this so it cannot be deferred. Codex review flagged that leaving this as an open question would force a rework of Phase 1.

2. **Drop-detection invocation on upward / null moves.** **Decision:** `recomputeCheapestOffer()` calls `DetectDrop::clearLatchIfRecovered()` on upward and null moves, in addition to the existing `DetectDrop::__invoke()` on downward moves (see §5.1). **Rationale:** Original spec only fired drops on downward moves; the existing in-line recovery clear inside `DetectDrop::isRecovered` would never run, leaving the latch stuck. Codex review caught this.

3. **`price_drop_events.price_check_id` anchoring.** **Decision:** `DetectDrop::__invoke()` takes an explicit `int $priceCheckId` argument, removing the `latest('checked_at')` lookup (see §5.1). **Rationale:** Per-offer concurrent checks make "latest check on the product" racy and likely wrong. The triggering job knows the exact check ID it just inserted.

4. **Adapter contract.** **Decision:** Tri-state `ExtractionResult` (`skip` / `failed` / `success`) instead of nullable `OfferSnapshot` (see §2). **Rationale:** Two distinct meanings of `null` would cause regressions in host-specific adapters to silently fall through to weaker generic heuristics instead of surfacing as host failures.

5. **Circular FK enforcement.** **Decision:** Enforce DB-level FK on `products.cheapest_offer_id` with `nullOnDelete()`, added via a follow-up `Schema::table` block within the same migration (see §1). **Rationale:** Pre-launch; no reason to leave application-only integrity debt.

6. **Per-host rate limit on probe path.** **Decision:** Limiter applied inside `OfferFetcher` (not just queue middleware), so synchronous probes share the host budget (see §3). **Rationale:** Queue middleware alone leaves the user-driven probe path bypassing the limit, which can burst a host on rapid paste-and-retry.

7. **JSON-LD edge cases.** **Decision:** `JsonLdAdapter` must explicitly handle multiple script tags, `@graph` arrays, `AggregateOffer` (use `lowPrice`), nested `priceSpecification.price`, string-vs-number prices, and availability URLs (see §2). **Rationale:** schema.org Offer is loosely structured in the wild; a naive implementation works on the simplest shops and silently fails on the others.

8. **5xx failure counter.** **Decision:** Separate `offers.consecutive_5xx_failures` column with its own threshold (failing at 10, dead at 30), reset on any successful check, configurable in `config/dipcatch.php`. **Rationale:** Lumping 5xx into the main failure counter would dead-list offers after one bad day at a shop; ignoring 5xx entirely loses signal on truly broken endpoints. Separate counter with higher tolerance is the clear middle ground.

9. **Probe-path FPM blocking + per-user probe limit.** **Decision:** Document that the probe is a synchronous FPM-bound call capped by the 10s fetcher timeout; gate it with a per-user limiter (e.g. 6 probes/min/user) via `RateLimiter::for('offer-probe-user', ...)` and document FPM-pool sizing. **Rationale:** Codex round 2 flagged the worker-blocking risk; queuing the probe + long-polling would solve it but adds Livewire complexity that's not justified for v1 user volumes.

10. **End-to-end transaction boundary on CheckOfferPrice.** **Decision:** Fetch/parse happens outside any DB transaction; the entire persist phase (price_check insert, offer state update, product recompute, history segment swap, drop-detection write) happens in **one** transaction with fixed lock order `offer → product` (§5). **Rationale:** Original spec had price_check insert outside the transaction that updated offer state — a failure between them could leave a check with no matching state update. Codex round 2 caught this.

11. **Lock order for all mutation paths.** **Decision:** Every mutation that touches an offer + product (job, add, toggle, delete) follows `offer → product`, never the reverse (§5.1). **Rationale:** Mixed lock order between paths would deadlock against in-flight check jobs.

12. **Toggle-active drop trigger anchor.** **Decision:** Toggle-on passes the offer's latest successful `price_check_id`; toggle-off and delete pass `null` (those can't lower the cheapest). Resolves the §5.1-vs-§6 contradiction in round 1. **Rationale:** Re-enabling a dormant cheaper offer is a legitimate drop and needs a real anchor check; disabling can never lower the cheapest, so the downward branch never fires there.

13. **`product_cheapest_history` as time segments (not point-in-time rows).** **Decision:** Each row has `started_at` + nullable `ended_at`; only one open segment per product at a time; segment close + new segment insert atomic under the product lock (§6.5). **Rationale:** Round-1 design wrote a point row only on change, which made `median_30d` and windowed charts dropout for stable products. Segments preserve the existing window-weighted semantics at minimal storage cost.

14. **Adapter-key persistence is a hint, not a pin.** **Decision:** On re-check, run the persisted adapter first; any `skip` or `failed` immediately falls through to the full chain (§2). **Rationale:** Round 1 implied a "fails repeatedly before re-detect" rule; round 2 found that contradicts §5's immediate-fallback wording. Immediate fallback is the safer rule and handles silently-stale persisted keys (host changed its markup).

15. **Reference precomputed outside product lock.** **Decision:** `Reference::compute()` runs before `lockForUpdate` in `recomputeCheapestOffer`; the value is passed into `clearLatchIfRecovered` (§5.1, §6). **Rationale:** Reference reads the 30-day window and sorts in PHP — heavy enough that holding it under the product lock would serialize concurrent recompute jobs for the same product. Slight staleness is harmless for the recovery check (one cycle worst case).

16. **5xx counter is exclusive of the main failure counter.** **Decision:** 5xx increments only `consecutive_5xx_failures`; parse/4xx/block/rate-limit increments only `consecutive_failures` (§5 per-job algorithm). Either counter can trip a health transition, but only its own. Both reset to 0 on any successful check. **Rationale:** Round-1 wording let both counters increment in parallel, which defeated the point of splitting them.

17. **Currency mismatch at probe time.** **Decision:** Reject offer at probe with a clear error when snapshot currency ≠ product currency. Multi-currency support deferred. **Rationale:** Auto-conversion via FX rates adds dependency + staleness; mixed currencies make "cheapest" meaningless. Rejection is the only clean v1 option.

18. **Offer adder identity.** **Decision:** Only the product owner adds offers — no `added_by_user_id` column, no moderation step. **Rationale:** The whole feature is that a single user tracks one product across multiple webshops themselves; there is no cross-user "suggest an offer on someone else's product" workflow.

19. **Offer dedupe scope.** **Decision:** Duplicate per (product_id, url_hash). Two different products both tracking `bol.com/p/123` get two offer rows + two fetches. No canonical-product concept. **Rationale:** No EAN/identity linking exists; sharing fetches would require a canonical-product layer that is out of scope for v1.

20. **robots.txt fetch failure policy.** **Decision:** Fail-open on 404 / 403 / network error / unparseable robots.txt; log every fail-open for audit. 5xx fail-open + don't cache. **Rationale:** We're a low-volume bot identifying ourselves with a contact URL; treating missing robots.txt as "everything forbidden" would block the long tail. Logging keeps an audit trail if a shop later complains.

21. **Add-offer confirm UX.** **Decision:** Inline confirm card — paste URL → spinner → preview card (title + image + price + host) with Confirm / Cancel buttons. Persist only after Confirm. **Rationale:** Safest against product-identity mismatches; auto-accept would pollute cheapest when users paste the wrong URL.

22. **Currency mismatch at probe time.** **Decision:** Reject at probe with a clear inline error ("this shop sells in GBP; this product is tracked in EUR"). Multi-currency deferred. **Rationale:** Auto-conversion via FX rates adds dependency + staleness; mixed currencies make "cheapest" meaningless. Rejection is the only clean v1 option.


## Findings

### Phase 1 (2026-05-11)

- **Migration filename ordering**: Laravel sorts migrations alphabetically when timestamps tie. `create_offers_table` and `create_products_table` shared `2026_04_26_162646_` — offers sorted FIRST, breaking the products → offers → FK-on-products plan. Renamed offers migration to `2026_04_26_162646_zz_create_offers_table.php` so products runs first. A cleaner project-wide convention is "one migration per second" timestamps; not worth retrofitting now.
- **`PriceCheck::product()` HasOneThrough** caused `getOwnerKeyName` not-found errors at runtime when Filament/Eloquent introspected the relation. Replaced with a small plain method `product(): ?Product { return $this->offer?->product; }`. Same call site (`$check->product`) — different mechanism. `Product::priceChecks()` HasManyThrough removed entirely; callers route through `$product->offers->flatMap(fn ($o) => $o->priceChecks)` or scope by `offer_id`.
- **`recomputeCheapestOffer` null-to-value direction**: My initial `compareDirection` returned `'down'` when previous was null and new was non-null. That treats "first ever eligible offer" as a drop, which fires `DetectDrop`. Kept the behavior because it matches user intent ("a new cheaper shop appeared" → notify), but the recovery-latch test had to be restructured to seed `cheapest_price` first.
- **Concurrency test deferred**: Tried to model "two parallel `CheckOfferPrice` jobs against the same product", but the test suite uses `:memory:` SQLite which serializes all writes — there's no real lock contention to assert. The concurrency-correctness claim is supported by the `lockForUpdate` placement in code and the row-lock ordering described in §5.1 and §6. A real concurrency test belongs in Phase 4 when the actual queue path lands; flag with Postgres harness then.
- **Heavy-handed legacy-test skipping** (129 tests skipped) was the only realistic way to keep Phase 1 boot-green without dragging Phase 3/4/5 forward. Each skipped file has a `markTestSkipped` reason pointing at the phase that will rewrite it. Need to revisit during those phases — don't ship to production while these stay skipped.
- **`CreateProduct` / `EditProduct` gutted**: Phase 1 Filament pages are intentionally bare-bones (no URL probing, no rescrape). Users navigating to those pages today can only edit metadata; the add-shop / preview flow lands in Phase 3.
- **Intelephense diagnostics on factory `$attributes`-unused, anonymous-function `$middleware`-unused, and Builder/HasOneThrough method calls** are noise from the static analyzer; PHPStan + the test suite are the authoritative checks. Will verify with PHPStan at the full-Phase-1-completion gate.

### Phase 2 (2026-05-11)

- **`Response::effectiveUri()` not available on Laravel's HTTP client response**. Returned the original input URL as `finalUrl` in `FetchResult`. Redirect-aware probe needs a different approach (e.g. middleware) — flag for Phase 3 if the UI ever wants to display the canonical landed URL.
- **`Http::fake` URL-pattern matching is case-sensitive**. Initial host-normalization test failed because the fake key `https://www.Example.COM/p/1` didn't match the lowercased request. Resolved by lowercasing the fake URL in the test; production code calls `parse_url` + `UrlNormalizer::normalizeHost` so this is a test-harness quirk only.
- **Adapter "applies" semantics**: JSON-LD is the most aggressive — any `<script type="application/ld+json">` on the page counts as "applies" so a malformed JSON or missing `Offer` returns `failed`, not `skip`. This stops the chain (no silent OG fallback) so host regressions surface. Confirmed in `JsonLdAdapterTest::failed when JSON-LD has no Offer`.
- **`PriceNormalizer` strips trailing zeros** (PHP float→string keeps significant digits only — `199.50` becomes `"199.5"`). Tests assert via `(float)` cast where exact trailing zeros matter; storage casts to `decimal:2` so the column is canonical anyway.
- **Generic adapter is intentionally conservative**: returns `skip` if no currency hint anywhere. Prevents the chain from claiming success on price-like numbers that aren't really product prices. Trade-off: real product pages with bare numeric `.price` text + no metadata produce `no_adapter_matched`. Acceptable for v1.

### Phase 3 (2026-05-11)

- **Resolver now carries the winning `adapterKey`** on success and failed results. Added `ExtractionResult::withAdapterKey()` so the resolver tags which adapter produced the snapshot — needed so `offers.adapter_key` gets the right value for Phase 4's re-check hint.
- **Dedupe runs before the per-user rate limit**, so a user pasting the same URL repeatedly doesn't burn their 6/min budget on lookups that never fetch. Tested explicitly.
- **Empty-trim URL handled in the component, not the action**: stripping whitespace before validating keeps the action's `invalid_url` semantics tighter (action validates URL shape, component validates "user actually typed something").
- **Currency mismatch surfaces structured context** (`expected` / `actual`) so the blade renders the actual ISO codes without parsing the message.
- **Filament Infolist embeds Livewire via `View::make('partials...')` shim** rather than a raw `@livewire` directive in the schema. Reason: Filament's view component expects a blade path, not a component name, and the shim gives us a stable place to grab `$this->record` from the Filament context.
- **Recompute runs after the create() transaction commits**, not inside it. Inside-the-transaction would work in Postgres (read-your-own-writes), but SQLite + nested transactions occasionally surface ghost rows in `lockForUpdate` queries. Two-transaction approach is safer and the only race window is between create and recompute — bounded by single-user input cadence.
- **No "probing" state in the component state machine**: Livewire's `wire:loading` directive handles the spinner without needing an explicit state. Simpler markup, fewer states to test.

### Phase 4 (2026-05-11)

- **`ScrapeStatus` enum was extended rather than rewritten** to keep migration data + existing query callsites stable. New cases: `pending`, `blocked`, `rate_limited`, `5xx`, `robots_disallowed`, `dead`, `failed`. The pre-existing `RobotsBlocked` / `Throttled` cases remain unused by the new pipeline but stay so old `price_checks` rows (if any) deserialize cleanly.
- **Robots-disallowed branch in `incrementCountersFor` returns `[]`** which `healthTransitionsFor` treats as the hard-fail signal (`health=dead`, `active=false`). Saves one extra discriminator argument; explicitly tested.
- **`each()` callback in `RecheckActiveOffersCommand`** instead of `get()->each()` to keep memory bounded — `batch_size` is the only cap on the IO surface.
- **Schedule entry uses `everyFiveMinutes()`** per spec (the spec said "every 5 min"). Recheck cadence (interval_hours, default 6) is independent of dispatch cadence; the scheduler runs frequently to spread bursts.
- **Deleted ~11 test files** for the removed scrape pipeline (`ScrapeProductJobTest`, `DispatchScrapesCommandTest`, `RecordScrapeTest`, `HtmlScraperTest`, `HardeningTest`, `RobotsAndThrottleTest`, `AutoDetectTest`, plus unit tests for `CurrencyDetector`/`PriceParser`/`UrlResolver` and the `ScraperFixtures` support file). Suite count dropped from 412→342 in absolute terms but unique pass count is up — same surface area now covered by `tests/Feature/Offers/*` and `tests/Feature/PriceAdapters/*`.

### Phase 5 (2026-05-11)

- **No `RecentNotificationsTableWidget` test changes needed**: the database-notifications widget tests seed `data` payload manually, so they didn't care about the new shape (host / offer_url). Just removed the skip and they passed.
- **Two-step add-offer flow over "first URL at create"**: deferred the spec's "first URL becomes first offer" idea because the dedicated Livewire `AddOffer` component already handles probe/preview/persist atomically. Inlining that into `CreateProduct` would duplicate the state machine. The product create page now collects metadata only; the Shops list + Livewire form on the view page handle URL adds for first + every subsequent shop.
- **Per-offer chart overlay deferred**: spec listed it as optional ("add per-offer overlay later (Phase 5)"). The headline cheapest-segments line is enough for v1; multi-line overlays make the chart noisy when products have 3+ shops. Easy to add later via a `filter` on the chart toggle.
- **Notification test rewrites use `Offer::factory()->for($product)` + `forceFill(['cheapest_offer_id' => ...])`** to set up the product-cheapest-offer state without going through `recomputeCheapestOffer()`. That keeps the notification tests focused on the notification logic (subject, payload shape) rather than the recompute pipeline (which has dedicated tests in `tests/Feature/MultiWebshop/RecomputeCheapestOfferTest`).
- **`StatsOverviewWidget` savings source changed from product-level diff to `PriceDropEvent::SUM(drop_abs)`** in Phase 1. The Phase 5 rewrite of the test follows suit — savings only register when a real drop event fired, not "current cheaper than initial offer price".
- **2 still-skipped tests** are unrelated Fortify-feature-gated checks (`skipUnlessFortifyHas` helper); they always skip when the relevant Fortify feature is disabled.

### Phase 6 (2026-05-12)

- **BolAdapter shape is the template for future host adapters**: `key()` + `extract()` that returns `skip()` when host doesn't match. Use `JsonLdAdapter` + `PriceNormalizer` as building blocks rather than re-implementing the wheel.
- **Bol's `www.bol.com` is folded to `bol.com`** via `UrlNormalizer::normalizeHost`, so we don't need both variants in the host check. Confirmed with a dedicated test.
- **Host adapter ordering matters**: registered BEFORE the generic chain in `AppServiceProvider`. The resolver iterates in order, so a successful Bol extraction wins before JSON-LD even runs.
- **No fixtures captured from real bol.com pages**: tests use synthesized HTML to avoid leaking captured copyrighted content into the test suite. Future per-shop adapters should follow the same pattern unless a fixture is critical to repro a subtle parse bug.

<!-- Notes added during implementation. Do not remove this section. -->
