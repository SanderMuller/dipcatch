# DipCatch

Track product prices across the web. Get notified when a price drops more than your threshold.

## What it does

- Public homepage at `/` pitches the product and collects wait-list emails (table: `waitlist_signups`, IP-rate-limited 5/hr).
- User creates a product and attaches one or more shop URLs to it. The probe step auto-extracts price/title/image from the page (host-specific adapter → JSON-LD → microdata → OpenGraph → generic), or accepts user CSS selectors when extraction can't find a price.
- App re-fetches each active shop on a schedule; the cheapest live shop sets the product's headline price.
- When the cheapest price drops more than `X` (absolute or %) below a reference price, user gets a notification.
- Dashboard lists every tracked product with image, name, cheapest shop, cheapest price, and per-product drilldown (price history chart + per-shop status).
- "Add shop" form: URL → live probe → preview/variant chooser → confirm. URLs are normalized + deduped per product.
- Optional per-product public share link (unguessable 32-char slug at `/p/{slug}`, owner-rotatable, `noindex,nofollow`): exposes only product summary, eligible shops, and price-history chart — no notes, thresholds, or private fields.

## Core concepts

- **Product** — the abstract item: title, image, currency, drop thresholds, denormalized `cheapest_shop_id` + `cheapest_price`.
- **Shop** — a URL at a specific webshop attached to a product; carries health (`ok` / `failing` / `dead`), per-host failure counters, optional user-supplied CSS selectors, and the persisted `adapter_key` hint.
- **PriceCheck** — timestamped per-shop fetch result (price, currency, status, error if any).
- **ProductCheapestHistory** — segmented timeline of which shop was cheapest when, used for the price-history chart and the drop-reference window.
- **PriceDropEvent** — denormalized notification row carrying `price_check_id` + `triggered_by_shop_id`; powers chart markers and the savings widget without JSON probing.
- **Notification** — emitted when the cheapest shop's newest `PriceCheck` price drops by more than threshold vs. a 30-day median reference (falls back to initial price when <7 samples).

## Stack

- PHP 8.5 + Laravel 13
- Filament v5 (admin panel + user-facing app panel) + Fortify auth (invite-only)
- Livewire 4 + Flux 2
- Postgres on Laravel Cloud
- HTTP-only fetching via `ShopFetcher` (robots.txt + SSRF guard + WAF detection + per-host rate limit + body cap), with a chain-of-responsibility `AdapterResolver` over price extractors — no headless browser in v1
- Queue worker + scheduler on Laravel Cloud
- Mail + Filament in-app bell + web push (`laravel-notification-channels/webpush`)
- Pest 4, PHPStan (Larastan), Pint, Rector — full quality stack

## Specs

Implementation-ready specs live in [`specs/`](specs/README.md). All v1 launch specs and post-launch refactors are shipped; their files are removed once fully implemented (see the strikethrough entries in the spec index for what shipped). `notifications.md` still has a deferred Phase 5 admin queue widget.

## Local dev

```bash
composer setup    # install deps, copy env, key:generate, migrate, build assets
composer dev      # serve + queue + pail + vite (concurrent)
```

## Deployment (Laravel Cloud)

DipCatch targets [Laravel Cloud](https://cloud.laravel.com/) — fully managed, no SSH/Docker.

### Required services

- **Postgres** add-on attached → `DATABASE_URL` injected.
- **Queue worker** (1 small instance), `redis` driver. Runs `php artisan queue:work --queue=scrapes,digests,default --tries=1` — `scrapes` carries `CheckShopPrice`, `digests` carries `SendDailyDigest`.
- **Scheduler** enabled — runs `php artisan schedule:run` minutely.

### Required env vars

| Key                              | Purpose                                                     |
|----------------------------------|-------------------------------------------------------------|
| `APP_URL`                        | Public base URL (used in invitation + push payload links).  |
| `APP_KEY`                        | Standard Laravel key.                                       |
| `DATABASE_URL`                   | Auto-injected by Laravel Cloud.                             |
| `MAIL_*`                         | Mail provider for invites + drop notifications + alerts.    |
| `MAIL_FROM_ADDRESS` / `_NAME`    | From-address shown to recipients.                           |
| `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD` | Seeded by `AdminUserSeeder` on `db:seed`. |
| `DIPCATCH_FETCHER_USER_AGENT`    | Custom UA for `ShopFetcher` (default: a real-browser UA, since Cloudflare/Akamai block any UA that admits to being a bot). Override only when a shop's robots.txt demands a bot UA. |
| `DIPCATCH_FETCHER_RATE_LIMIT_PER_MINUTE` | Per-host fetch budget (default 30). Shared by probe + queued recheck paths. |
| `DIPCATCH_RECHECK_INTERVAL_HOURS` / `_JITTER_MINUTES` | Recheck cadence + jitter (defaults 6 / 30). |
| `DIPCATCH_SHOP_FAILING_AFTER` / `_DEAD_AFTER`         | Health thresholds on the main failure counter (defaults 3 / 10). |
| `DIPCATCH_SHOP_FAILING_5XX_AFTER` / `_DEAD_5XX_AFTER` | Separate higher thresholds for transient upstream 5xx (defaults 10 / 30). |
| `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Web push (generate via `php artisan webpush:vapid`). |
| `FAILED_JOB_MONITOR_NOTIFIABLE`  | Comma-separated admin emails for failed-job alerts.         |

### Health monitoring

Health checks are exposed inside the admin panel at `/admin/health-check-results`. Active checks: environment, debug-mode, cache, database, schedule, used-disk-space, CPU load, security advisories, plus the custom `LastSuccessfulScrapeCheck` (warns if any active shop on an active product hasn't seen a successful fetch in 48h, fails after 96h).

### Cache + lock driver

Cache + queue + unique-job locks all run on Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`). The Redis connection backs `withoutOverlapping()` on the scheduler, the per-host `ShopFetcher` rate-limit bucket, and `ShouldBeUnique` locks on `CheckShopPrice`.

### First deploy

1. Provision Postgres add-on.
2. Set the env vars above.
3. Push to `main` → Laravel Cloud builds + runs migrations.
4. Run `php artisan db:seed` once to create the initial admin user.
5. Sign in at `/login`, create invitations from `/admin/invitations`.
