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
8. ~~`checkjebon-price-source.md`~~ — ✅ shipped (daily checkjebon.nl dataset in Postgres for AH + Dirk + Lidl; probe + recheck bypass the scraper for these hosts; `CheckjebonFreshnessCheck` health check).

## Post-launch refactors + features

- ~~`multi-webshop-price-tracking.md`~~ — ✅ shipped (Product/Shop split, adapter chain, per-shop checks, ProductCheapestHistory timeline).
- ~~`test-helper-hoist.md`~~ — ✅ shipped (`withJsonLd` + `jsonLdPage` consolidated in `tests/Pest.php`; `phpstan.neon` `scanFiles` added).
- ~~`failure-code-enum.md`~~ — ✅ shipped (`App\Enums\ProbeFailure`, `ProbeOutcome::extractionFailed()` + `shouldOfferManualSelector()`, `CheckShopPrice::failureOutcome` via `ScrapeStatus::tryFrom`).
- ~~`email-digest.md`~~ — ✅ shipped (replaced per-drop email with daily 09:00 user-local digest; `users.timezone` + `last_digest_sent_at`, `SendDailyDigest` job, `DispatchDailyDigestsCommand`).
- ~~`shop-notes.md`~~ — ✅ shipped (free-text `shops.notes` column, App-panel `edit_notes` action + indicator column, admin read-only column).
- ~~`timezone-autodetect.md`~~ — ✅ shipped (`users.timezone_detected_at` + browser `Intl` detection on first authenticated page load; atomic conditional UPDATE so explicit save in NotificationSettings can't be clobbered).
- ~~`url-first-product-creation.md`~~ — ✅ shipped (paste-URL-first create flow: probe fills title/image, tier-default thresholds, one Confirm creates product + first shop; manual form kept at `/create-manual`).
- ~~`public-product-sharing.md`~~ — ✅ shipped (per-product `share_slug` + public `/p/{slug}` route, Chart.js price-history + OG/Twitter meta, atomic conditional UPDATE on share/rotate/stop to refuse last-writer-wins between owner tabs, SRI-pinned CDN scripts).

## Decisions (locked)

- **Stack baseline:** PHP 8.5, Laravel 13, Filament v5.6+, Fortify v1, Livewire 4 + Flux 2, Pest 4, Larastan 3, Postgres, Laravel Cloud — all already installed; specs *configure* these, never reinstall.
- **DTOs:** all service contracts use `Spatie\LaravelData\Data` (already installed).
- **Status enum:** single backed enum `App\Enums\ScrapeStatus` shared by `products.last_status`, `price_checks.status`, and DTOs.
- **Schedule registration:** `bootstrap/app.php` `withSchedule(...)` (Laravel 13 — no `Kernel.php`).
- **Failed-job alerts:** `spatie/laravel-failed-job-monitor` (already installed); no custom command.
- **Health checks:** `shuvroroy/filament-spatie-laravel-health` panel + custom `LastSuccessfulScrapeCheck`.
- **Validation:** `sandermuller/laravel-fluent-validation` idiom.
- **Auth:** invite-only (admin creates users in Filament admin panel). Fortify owns login routes; both panels delegate to it.
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
