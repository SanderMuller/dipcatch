# Public Product Sharing

## Overview

Let a user generate an unguessable, revocable link that shows one of their tracked products to anyone — no signup, no login. The page shows the product summary, the price-history chart, and the per-shop list, but never the edit actions, notes, or the add-shop form. Useful for "look at this drop I caught" links shared into a chat or social post.

This is a small feature spec, not on the launch-readiness build order in `specs/README.md`.

User decisions locked at spec time (defaults):

- **Share unit**: one product per link.
- **Access**: unguessable random slug, link-only, owner can revoke (rotates the slug). No login required for the viewer.
- **Visible data**: product title + image + current cheapest price + price-history chart + per-shop list (host + price + last-checked). Owner-only data (notes, edit actions, raw URLs of dead shops, the AddShop form) stays hidden.

---

## Current state

- `Product` is owner-scoped via `products.user_id`. Edit / view today goes through `App\Filament\App\Resources\Products\Pages\ViewProduct` inside the auth-required Filament app panel.
- `Shop` rows expose `host`, `current_price`, `current_in_stock`, `health`, `last_checked_at`, `url`, `notes` (private), `price_selector` / `title_selector` / `image_selector` (private).
- `ProductCheapestHistory` holds the segmented timeline used by the existing `PriceHistoryChart` Filament widget.
- The widget is Livewire / Filament-bound — it doesn't render outside the panel. Public page needs its own chart rendering.
- Existing routes are panel-scoped (`/app/*`) or auth+verified (`/dashboard`, `/profile/*`). No public read surface exists today besides `/` (the marketing page).

---

## Data model

Single migration `add_share_slug_to_products_table`:

```php
$table->string('share_slug', 32)->nullable()->unique()->after('cheapest_price');
```

- Null = not shared. Non-null = publicly accessible at `/p/{slug}`.
- 32-char base62 random string (`Str::random(32)`) — ~190 bits of entropy, not enumerable.
- `unique` index — enforces no collisions; the controller looks up by exact match.
- Re-sharing generates a NEW slug (old link 404s), so "revoke + re-share" is a single rotate operation.

`Product` model: no cast change (string column), helper methods:

```php
public function isPubliclyShared(): bool { return $this->share_slug !== null; }
public function publicShareUrl(): ?string { ... }  // route('product.public', $this->share_slug) or null
```

---

## Backend

`App\Http\Controllers\PublicProductController` (single-action `__invoke`), `GET /p/{slug}`:

- **No auth middleware.** `web` + a per-IP throttle (`throttle:public-product` — registered in `FortifyServiceProvider`, e.g. 120/min per IP).
- **Independent lookup.** Look up by `share_slug` only — do NOT route through `ProductPolicy::view()` or Filament's `ProductResource::getEloquentQuery()`, both of which scope to `auth()->id()` and would 403/empty for guests. Use a plain `Product::query()->where('share_slug', $slug)->firstOrFail()`.
- **Column projection — explicit allowlist, not full models.** `Shop` carries private fields (`notes`, `price_selector`, `title_selector`, `image_selector`, raw `url`) and so does `Product` (`drop_threshold_*`, `last_notified_*`, `cheapest_shop_id`). The controller must `select()` only the columns the view actually renders:
  - Product: `id, title, image_url, currency, cheapest_price, share_slug`
  - Shop: `id, product_id, host, current_price, current_in_stock, last_checked_at, url` (kept for the click-through link)
  - ProductCheapestHistory: `cheapest_price, started_at, ended_at`
- **Eligibility filter on shops** — match the production "eligible shop" logic from `Product::recomputeCheapestShop` (`app/Models/Product.php:106`): `active = true` AND `current_in_stock = true` AND `health != dead` AND `current_price NOT NULL`. Order by `current_price` ascending.
- Eager-loads the recent `ProductCheapestHistory` segments for the 90-day chart window (last 90 days of `started_at`).
- Returns a Blade view `public.product` with: product, eligible shops, chart payload (decimal arrays).
- Response headers: `X-Robots-Tag: noindex, nofollow` so a bot that picks up a link doesn't keep it in an index.

Route registration in `routes/web.php` (outside the `auth+verified` group):

```php
Route::get('p/{slug}', PublicProductController::class)
    ->where('slug', '[A-Za-z0-9]{32}')   // exact length — rejects junk-length probes before hitting the DB / rate-limiter
    ->middleware('throttle:public-product')
    ->name('product.public');
```

---

## Owner UI

On `ViewProduct` (the existing product view page in the Filament app panel), add a header action `share`:

- Label: "Share publicly" (if `share_slug === null`) or "Sharing enabled" (if set).
- Modal:
  - When unshared: button "Generate public link" → sets `share_slug = Str::random(32)`, saves, shows the URL with a copy-to-clipboard input. Notification: "Public link created".
  - When shared: shows the URL with copy button, plus two destructive actions:
    - "Rotate link" → new slug, old slug 404s. Useful if the previous link leaked.
    - "Stop sharing" → sets `share_slug = null`. Page becomes 404.
- The action is owner-only because `ProductResource::getEloquentQuery` already scopes to `auth()->id()`.

---

## Public page

`resources/views/public/product.blade.php`:

- Standalone layout (NOT the marketing welcome template; NOT the Filament panel). Minimal CSS via the existing Vite bundle.
- Sections (top-to-bottom):
  1. **Header**: product title + image, current cheapest price in big type, "as of {{ last_checked_at->diffForHumans() }}" caption, "Tracked on DipCatch" footer link to `/`.
  2. **Chart**: price-history line chart from `ProductCheapestHistory`, last 90 days. Render with Chart.js loaded from a CDN (or via the existing Vite bundle if a chart library is already present — verify during Phase 2).
  3. **Shops**: small table — shop host, current price, in-stock badge, "View" link to the shop URL (rel="noopener nofollow ugc"). Dead/inactive shops omitted.
- **Hidden**: edit actions, the AddShop form, shop notes, raw failure counters, the per-shop `health` badge (admin-level signal).
- **Meta tags**:
  - `<meta name="robots" content="noindex, nofollow">` (defense in depth — the X-Robots-Tag header is primary).
  - Open Graph: `og:title` = product title, `og:image` = product image, `og:description` = "Tracked on DipCatch: cheapest at {currency} {price}". Lets the link unfurl nicely in chat / social.
  - Twitter Card: `twitter:card = summary_large_image` if image present, else `summary`.
- **`og:image` / `<img>` validation.** `image_url` is user-supplied (set on the product create form). Blade `{{ }}` escapes the value into the attribute, but only emit it when it parses as an `http://` or `https://` URL — reject `javascript:`, `data:`, `file:` etc. before the meta tag renders. Use a tiny helper `$product->safeImageUrl(): ?string` that returns null for non-http(s) values, the view conditionally emits the meta tag + `<img>` only when non-null.

---

## Phases

### Phase 1 — Schema + model + share/revoke action

1. Migration: `share_slug` (string, 32, nullable, unique) on `products`.
2. `Product` model: `isPubliclyShared()` + `publicShareUrl()` + `safeImageUrl()` helpers (the last validates `http://` / `https://` prefix for the OG-tag emit).
3. `ProductFactory`: add `'share_slug' => null` default.
4. Filament `share` action on `ViewProduct` page (generate / rotate / stop).
5. Tests:
   - factory round-trip + uniqueness constraint
   - Filament action: generates a 32-char slug, rotates produces a different slug, stop nulls it
   - `safeImageUrl()` returns the URL for http/https, null for `javascript:` / `data:` / non-URL strings

### Phase 2 — Public controller + route + minimal Blade view

1. `App\Http\Controllers\PublicProductController` + route + throttle limiter.
2. Blade view: header + shops table (no chart yet).
3. Tests:
   - happy path: valid slug → 200, page contains product title + eligible shop list
   - unknown slug → 404
   - null `share_slug` on a real product → 404 (can't fetch via the empty-string slug)
   - wrong slug length (e.g. 16 chars) → 404 via the route regex *before* the controller runs
   - inactive / out-of-stock / dead-health / null-price shops omitted from the shop list (exhaustive coverage of all four eligibility predicates)
   - **private-data guard**: shop `notes`, `price_selector`, `title_selector`, `image_selector` do NOT appear in the response body
   - **private-product-data guard**: product `drop_threshold_pct` / `drop_threshold_abs` / `last_notified_price` do NOT appear
   - guest sees the page without any auth redirect (i.e. the controller doesn't trip an owner-policy reuse)
   - response headers include `X-Robots-Tag: noindex, nofollow`
   - throttle: 121st request in a minute returns 429

### Phase 3 — Chart + meta tags + UX polish

1. Add the price-history chart (Chart.js inline payload).
2. Add Open Graph + Twitter Card meta tags.
3. Test: chart payload renders with the right data; meta tags present with expected values.

### Phase 4 — Verify

1. `vendor/bin/pest --compact`.
2. `vendor/bin/pint --dirty --format agent`.
3. `vendor/bin/phpstan analyse --memory-limit=2G`.

---

## Open Questions

- **Q1:** chart rendering — Chart.js loaded from a CDN, or vendored via Vite? **Default:** verify during Phase 2 whether the app already bundles a chart library (Filament does for its widgets). If yes, reuse; otherwise CDN. Worst case: skip the chart in Phase 2, ship in Phase 3.
- **Q2:** when a public-page viewer clicks through to a shop URL via the "View" link, should we attribute the click? **Default:** no. No analytics in this app today; adding it for the public surface alone is scope creep. The link is `rel="noopener nofollow ugc"` so we don't pass authority either way.
- **Q3:** should the owner see how many people opened the public link? **Default:** no — adds a counter column + a hit middleware for a feature nobody has asked for. Easy to add later if real demand surfaces.
- **Q4:** rate-limit budget — 120/min/IP is generous for normal viewing (chat preview unfurls hit the URL once; a human refreshing manually never gets close). Tighten or expand based on real traffic. Defer until shipped.
- **Q5:** if the product owner deletes a shop / the product itself, the public link... 404s (FK cascade) for delete-product; just shows fewer shops for delete-shop. Default behavior is fine. Confirm with a test in Phase 2.
- **Q6:** "Stop sharing" rotates the slug to null and the new URL 404s — but social platforms (Slack, Twitter, etc.) cache the unfurled preview for hours to days. We can't invalidate those caches. **Default:** document this in the in-app share modal ("Existing chat previews may persist for some time"). No code change.

---

## Findings

- **Filament action UI refactored from ActionGroup to standalone header actions.** The spec proposed a single `share` action with a modal whose `extraModalFooterActions` carried rotate + stop. Filament's `callMountedAction()` test API takes an arguments array, not a child-action name, and the nested-action shape was awkward to drive from tests. Shipped three standalone header actions instead (`share`, `rotate_share`, `stop_share`) each `visible()`-gated by `share_slug` presence — cleaner test surface, no UX cost since the visibility predicates ensure only the right buttons show.
- **Eligibility-filter regression from a confused factory call.** Initial test setup passed `'host' => 'visible.test'` directly to `Shop::factory()->create()`, but the Shop model's `booted()` hook derives `host` from `url` via `UrlNormalizer::normalizeHost(parse_url(...))` on every create — so my custom hosts got silently overwritten to whatever the factory's default URL pattern produced (`shop.example.com`). Fixed by passing `url => 'https://visible.test/p/1'` and letting the hook derive the host.
- **Render hook from spec ("render meta via the same render hook")** dropped — the spec's plan was for an `HEAD_END` Filament render hook to emit a `<meta name="dipcatch-timezone-detected">`-style flag, but the public page is a standalone Blade view (no Filament panel context), so the meta tags are emitted directly in the view's `<head>`.
- **PHPStan + ProductCheapestHistory datetime casts.** Larastan doesn't infer the `started_at` / `ended_at` casts from the model's `casts()` method shape, so the chart payload builder had to `assert($started instanceof CarbonImmutable)` AND assign the `toIso8601String()` result to a `/** @var string */`-annotated local. Same widening pattern that hit User in the timezone-autodetect work — a wider follow-up could add `@property-read` annotations to ProductCheapestHistory + every other model that gets touched by Larastan.
- **`array_merge` test helper triggered Larastan `argument.type` warning** because Larastan can't see that merged keys are valid model attributes. Switched to PHP 8.1 array-spread (`[...$default, ...$attrs]`) with a single `@phpstan-ignore argument.type` annotation; cleaner than building two-level type narrowing for a test helper.
- **Chart payload format** is `[{x: ISO timestamp, y: decimal string}, ...]` with each segment contributing two points (started_at + ended_at) so the line is properly stepped. Open segment's right edge is "now". 90-day cutoff applied at query time via `started_at >= cutoff` — segments started before the cutoff are excluded entirely (test pins this).
- **Chart.js loaded from CDN.** Q1 default was "verify Phase 2 whether the app already bundles a chart library" — Filament does bundle Chart.js for its widgets but the public Blade view runs outside the panel asset pipeline, so reusing the bundled copy would require a separate Vite entry point. CDN (`https://cdn.jsdelivr.net/npm/chart.js@4.4.4`) is the pragmatic ship; one extra network hop on the public page. The view short-circuits the `<script src=...>` entirely when there's no history to render.
- **Stale `ProductResource` import in `PublicProductController`** caught by intelephense + Pint after a docblock `@see` was rewritten to a plain identifier. Dropped the import.
