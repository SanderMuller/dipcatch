# DipCatch Specs

Implementation-ready specs. Build order = file order below. Each spec ends with `## Open Questions` (must be resolved before that section's phases run) and `## Findings` (filled during implementation).

## Build order

1. ~~`foundation.md`~~ — ✅ shipped (Postgres, Filament v5 panels, invite-only auth, migrations, deployment).
2. ~~`scraper.md`~~ — ✅ shipped (HTTP scrape service, price/currency parsing, robots.txt + throttle, JSON-LD).
3. ~~`product-management.md`~~ — ✅ shipped (Product resource, two-step add wizard, edit/view, bulk + re-scrape with cooldown).
4. ~~`scheduling.md`~~ — ✅ shipped (24h scheduler, queue jobs with retries + jitter, cleanup commands).
5. ~~`drop-detection.md`~~ — ✅ shipped (30d median reference, adaptive tier defaults, threshold evaluator, re-notify state machine).
6. **[notifications.md](notifications.md)** — email + Filament bell + web push, per-user channel toggles, profile UI. Phases 1–4 shipped; Phase 5 hardening partially shipped (per-user hourly rate-limit done; admin queue-depth widget deferred).
7. ~~`dashboard.md`~~ — ✅ shipped (stats widgets, active drops, recent notifications, savings chart, per-product price history chart).
8. ~~`checkjebon-price-source.md`~~ — ✅ shipped (daily checkjebon.nl dataset in Postgres for AH + Lidl-via-boodschaapje; probe + recheck bypass the scraper for these hosts; `CheckjebonFreshnessCheck` health check. Dirk moved to direct scraping afterwards: its JSON-LD carries the live promo price, the dataset only the regular price).

## Post-launch refactors + features

- **[seo-and-ai-discoverability.md](seo-and-ai-discoverability.md)** — P1/P2 of the 2026-09-06 SEO audit: robots.txt opens `/register` and states an AI-crawler policy, sitemap and `llms.txt` routes, shared head partial with OG/Twitter, `Organization`/`WebSite`/`SoftwareApplication`/`Offer` JSON-LD, `dipcatch.eu` identity plus a `/bot` page, and copy repositioned to repeat purchases. Shipped 2026-09-06. Fixed a live bug where Laravel 13's `@context` Blade directive made the homepage JSON-LD unparseable.

- **[landing-pages-and-marketing-polish.md](landing-pages-and-marketing-polish.md)** — use-case landing pages (`/price-alerts/{slug}`), branded 404/500 views, and marketing markup cleanup judged against the Markdown twin. Laravel Cloud's Markdown for Agents is already enabled at the edge, so no application code renders Markdown. Follows the spec above. Shipped 2026-09-06; the 503 was dropped because nothing invokes `errors::503`. Two ops tasks stay open in the Laravel Cloud dashboard.

- ~~`unit-aware-drops.md`~~ — ✅ shipped (a drop is decided on the comparable figure: per unit once a product compares per unit, on pack prices otherwise. Drop events store the comparison unit and both unit prices; a shop joining a product is never a drop; the latch remembers which basis it was armed in).

- ~~`unit-prices-everywhere.md`~~ — ✅ shipped 2026-09-24 (the per-unit price leads wherever a product price is shown: product page, cards, dashboard, alerts, digest, bell, push, public share page, markdown copies and MCP, with the pack price and size beneath. `HeadlinePrice` and `PackLine` on the resolved pack size; drop events store the pack they fired on; target-price alerts lead with the pack price; the public page resolves on the owner page's rules; the piece label is translated. Follow-ups shipped the same week: the hover details panel, unit-first previews, variant chooser and suggestions).

- ~~`unit-pricing.md`~~ — ✅ shipped (normalized unit price per shop from source size data with title fallback; superseded in presentation by `unit-prices-everywhere.md`).

- ~~`multi-webshop-price-tracking.md`~~ — ✅ shipped (Product/Shop split, adapter chain, per-shop checks, ProductCheapestHistory timeline).
- ~~`test-helper-hoist.md`~~ — ✅ shipped (`withJsonLd` + `jsonLdPage` consolidated in `tests/Pest.php`; `phpstan.neon` `scanFiles` added).
- ~~`failure-code-enum.md`~~ — ✅ shipped (`App\Enums\ProbeFailure`, `ProbeOutcome::extractionFailed()` + `shouldOfferManualSelector()`, `CheckShopPrice::failureOutcome` via `ScrapeStatus::tryFrom`).
- ~~`email-digest.md`~~ — ✅ shipped (replaced per-drop email with daily 09:00 user-local digest; `users.timezone` + `last_digest_sent_at`, `SendDailyDigest` job, `DispatchDailyDigestsCommand`).
- ~~`shop-notes.md`~~ — ✅ shipped (free-text `shops.notes` column, App-panel `edit_notes` action + indicator column, admin read-only column).
- ~~`timezone-autodetect.md`~~ — ✅ shipped (`users.timezone_detected_at` + browser `Intl` detection on first authenticated page load; atomic conditional UPDATE so explicit save in NotificationSettings can't be clobbered).
- ~~`url-first-product-creation.md`~~ — ✅ shipped (paste-URL-first create flow: probe fills title/image, tier-default thresholds, one Confirm creates product + first shop; manual form kept at `/create-manual`).
- ~~`public-product-sharing.md`~~ — ✅ shipped (per-product `share_slug` + public `/p/{slug}` route, Chart.js price-history + OG/Twitter meta, atomic conditional UPDATE on share/rotate/stop to refuse last-writer-wins between owner tabs, SRI-pinned CDN scripts).
- ~~`bundle-prices.md`~~ — ✅ shipped (single-item price and bundle terms stored beside `current_price` for Jumbo and Albert Heijn; the required quantity is always shown; Dirk stays on scalar prices).
- ~~`history-depth.md`~~ — ✅ shipped (free accounts read 90 days of history, Pro 365 days and All time and is never pruned; the window rule lives in `App\Billing\HistoryWindow`).
- ~~`promotion-window.md`~~ — ✅ shipped (the running promotion window is stored on the shop and shown under the price, from AH, Dirk, DekaMarkt, Aldi and schema.org).
- ~~`flux-user-app-migration.md`~~ — ✅ shipped (the user-facing app moved from the Filament `app` panel to Flux Pro; the admin panel stays Filament. Product edit and the savings-by-month chart landed after the spec was last updated).

- ~~`mcp-server.md`~~ — ✅ shipped 2026-09-07 (`laravel/mcp` server behind Passport OAuth with the `mcp:use` scope; list, create and inspect products, attach shops, read prices and history; add-product and add-shop logic shared with the web through `ShopDraft`).

- **[chatgpt-plugin-directory.md](chatgpt-plugin-directory.md)** — list DipCatch in the ChatGPT Plugins Directory: MCP tool annotations, OpenAI domain-challenge endpoint, Connections Connect/Install buttons and copy, privacy text for connected assistants. Claude gets an install link; ChatGPT Free cannot paste `/mcp`.

- ~~`adapter-canary.md`~~ — ✅ shipped 2026-09-18 (one known URL per host adapter, fetched daily; the canary command and its health check share `CanaryEntries`).

- ~~`confirm-large-drops-before-alerting.md`~~ — ✅ shipped (a drop of 40% or more alerts only when the shop's previous eligible reading also qualified, with a re-fetch 10 minutes later; dataset and API shops exempt. Phase 2 shipped 2026-09-24: the product page says "Confirming a large drop" while one reading waits for its second, from `LargeDropConfirmation::isAwaited()`).

- ~~`superadmin-and-comped-accounts.md`~~ — ✅ shipped 2026-09-07 (Users screen in the admin panel with each account's plan; comped Pro through `users.comped_until`, read by `Subscribes::plan()` and `ProUsers::ids()`).

## Decisions (locked)

- **Stack baseline:** PHP 8.5, Laravel 13, Filament v5.6+, Fortify v1, Livewire 4 + Flux 2, Pest 4, Larastan 3, Postgres, Laravel Cloud — all already installed; specs *configure* these, never reinstall.
- **DTOs:** all service contracts use `Spatie\LaravelData\Data` (already installed).
- **Status enum:** single backed enum `App\Enums\ScrapeStatus` shared by `products.last_status`, `price_checks.status`, and DTOs.
- **Schedule registration:** `bootstrap/app.php` `withSchedule(...)` (Laravel 13 — no `Kernel.php`).
- **Failed-job alerts:** `spatie/laravel-failed-job-monitor` (already installed); no custom command.
- **Health checks:** `shuvroroy/filament-spatie-laravel-health` panel + custom `LastSuccessfulScrapeCheck`.
- **Validation:** `sandermuller/laravel-fluent-validation` idiom.
- **Auth:** open registration via Fortify; invitations remain for admin-created users. Fortify owns login routes; both panels delegate to it.
- **Panel access:** `User::canAccessPanel(Panel $panel)` — AppPanel for any auth user, AdminPanel for `is_admin`.
- **Currency:** detect per product (scraper-detected wins, user can override); per-user `default_currency` derived from locale.
- **FX:** out of scope for v1. Lifetime savings widget groups per currency; tier defaults are currency-blind.
- **JS rendering:** **out of scope for v1**. Failed scrapes flip `needs_js = true` and surface a UI hint. Cloudflare Browser Rendering deferred to v2.
- **Cadence:** every 24h per product; scheduler dispatches every 15 min in batches.
- **Reference price:** 30-day median. <7 samples → fall back to initial price.
- **Threshold:** percent OR absolute (whichever fires first). Adaptive defaults by price tier; per-product override.
- **Re-notify:** once per drop event; new low within event re-notifies; recovery to reference clears latch.
- **Drop event log:** every notification fired writes a denormalized `price_drop_events` row carrying `price_check_id` — used by chart markers and the savings widget (no JSON probing).
- **Push:** `laravel-notification-channels/webpush` package's own `push_subscriptions` table (multi-device); `User` uses the package's `HasPushSubscriptions` trait — no JSONB column on `users`.
- **Rate limits:** 1 req/host/8s + ±2s jitter, robots.txt honored. Invite redeem throttled 30/min/IP.
- **Storage:** Postgres. **Cache driver:** `database` (required for `withoutOverlapping` + `Cache::lock`).
- **Hosting:** Laravel Cloud.
- **Tests:** full Pest coverage (unit + feature). PHPStan + Pint enforced via `composer qa`.
- **Locale:** English UI only.
