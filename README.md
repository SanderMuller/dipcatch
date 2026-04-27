# DipCatch

Track product prices across the web. Get notified when a price drops more than your threshold.

## What it does

- Public homepage at `/` pitches the product and collects wait-list emails (table: `waitlist_signups`, IP-rate-limited 5/hr).
- User adds a product by URL + CSS selector(s) for price (and optionally image/title).
- App scrapes price on a schedule.
- When the latest price drops more than `X` (absolute or %) below a reference price, user gets a notification.
- Dashboard lists every tracked product with image, name, initial price, initial check date, last seen price, last check date, and a delete/edit action.
- "Add product" button opens a form: URL + price selector (+ optional image selector, title selector, drop threshold).

## Core concepts

- **Product** — URL + selectors + threshold + initial snapshot (price, image, captured at).
- **PriceCheck** — timestamped scrape result (price, raw value, status, error if any).
- **Notification** — emitted when newest `PriceCheck` price drops by more than threshold vs. reference price (initial or rolling baseline — TBD).

## Stack

- PHP 8.5 + Laravel 13
- Filament v5 (admin panel + user-facing app panel) + Fortify auth (invite-only)
- Livewire 4 + Flux 2
- Postgres on Laravel Cloud
- HTTP-only scraping (`symfony/dom-crawler` + `symfony/css-selector` + JSON-LD fallback) — no headless browser in v1
- Queue worker + scheduler on Laravel Cloud
- Mail + Filament in-app bell + web push (`laravel-notification-channels/webpush`)
- Pest 4, PHPStan, Pint, Rector — full quality stack

## Specs

Implementation-ready specs live in [`specs/`](specs/README.md). All v1 specs (`foundation`, `scraper`, `product-management`, `scheduling`, `drop-detection`, `notifications`, `dashboard`) have shipped; their files are removed once fully implemented. The remaining spec is `notifications.md` (LOW-priority hardening — Phase 5 admin queue widget deferred).

## Local dev

```bash
composer setup    # install deps, copy env, key:generate, migrate, build assets
composer dev      # serve + queue + pail + vite (concurrent)
```

## Deployment (Laravel Cloud)

DipCatch targets [Laravel Cloud](https://cloud.laravel.com/) — fully managed, no SSH/Docker.

### Required services

- **Postgres** add-on attached → `DATABASE_URL` injected.
- **Queue worker** (1 small instance), `database` driver. Runs `php artisan queue:work --queue=scrapes,default --tries=1`.
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
| `SCRAPER_USER_AGENT`             | Custom UA for the scraper (default `DipCatchBot/1.0 ...`).  |
| `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Web push (generate via `php artisan webpush:vapid`). |
| `FAILED_JOB_MONITOR_NOTIFIABLE`  | Comma-separated admin emails for failed-job alerts.         |

### Health monitoring

Health checks are exposed inside the admin panel at `/admin/health-check-results`. Active checks: environment, debug-mode, cache, database, schedule, used-disk-space, CPU load, security advisories, plus the custom `LastSuccessfulScrapeCheck` (warns if any active product hasn't seen a successful scrape in 48h, fails after 96h).

### Cache + lock driver

Cache driver is `database` (cache + cache_locks tables migrated automatically). Required for `withoutOverlapping()` on the scheduler and the per-host scrape throttle locks.

### First deploy

1. Provision Postgres add-on.
2. Set the env vars above.
3. Push to `main` → Laravel Cloud builds + runs migrations.
4. Run `php artisan db:seed` once to create the initial admin user.
5. Sign in at `/login`, create invitations from `/admin/invitations`.
