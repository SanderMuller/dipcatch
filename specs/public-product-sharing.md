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
- Looks up `Product::where('share_slug', $slug)->firstOrFail()` (returns 404 on miss).
- Eager-loads `shops` (only `active = true`, ordered by current_price) + the open `ProductCheapestHistory` segment + the recent history segments for the chart window.
- Returns a Blade view `public.product` with: product, shops, chart payload (decimal arrays).
- Response headers: `X-Robots-Tag: noindex, nofollow` so a bot that picks up a link doesn't keep it in an index.

Route registration in `routes/web.php` (outside the `auth+verified` group):

```php
Route::get('p/{slug}', PublicProductController::class)
    ->where('slug', '[A-Za-z0-9]+')
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

---

## Phases

### Phase 1 — Schema + model + share/revoke action

1. Migration: `share_slug` (string, 32, nullable, unique) on `products`.
2. `Product` model: factory default null, `isPubliclyShared()` + `publicShareUrl()` helpers.
3. Filament `share` action on `ViewProduct` page (generate / rotate / stop).
4. Tests:
   - factory round-trip + uniqueness constraint
   - Filament action: generates a 32-char slug, rotates produces a different slug, stop nulls it

### Phase 2 — Public controller + route + minimal Blade view

1. `App\Http\Controllers\PublicProductController` + route + throttle limiter.
2. Blade view: header + shops table (no chart yet).
3. Tests:
   - happy path: valid slug → 200, page contains product title + shop list
   - unknown slug → 404
   - null `share_slug` on a real product → 404 (can't fetch via the empty-string slug)
   - inactive / out-of-stock / dead shops omitted from the shop list
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

---

## Findings

(filled during implementation)
