# DipCatch — Production Readiness Report

*2026-08-31 — verified against the code, a local test run, and a browser pass on the local app.*

## Verdict

The app is feature-complete for v1. All specs shipped except one low-priority admin widget. The UI (verified in browser: homepage, dashboard, product list, product detail, create form) is polished and usable. What stands between you and production use is: a broken PHPStan setup, two flaky throttle tests, the Laravel Cloud deploy itself, and one real product risk — scraping coverage for the shops you actually use.

## Verified state

- **Tests:** 452 of 456 pass (2 skipped, 2 failed). Both failures are throttle tests (`InvitationTest`, `PublicProductControllerTest`) that got `429` where `200` was expected. Both pass when rerun in isolation. Cause: the routes use `ThrottleRequestsWithRedis`, so the tests hit the real local Redis and share limiter state with other tests and earlier runs. Test-isolation bug, not an app bug.
- **PHPStan: does not run.** It aborts at boot: `Multiple services of type Rector\TypePerfect\Reflection\MethodNodeAnalyser found` (duplicate service registration, most likely from the recent dependency bumps — `rector/type-perfect` vs another extension registering the same service). The quality gate is currently blind.
- **Pint:** clean.
- **UI (seen in browser):** homepage with waitlist looks good; app dashboard (stats, active drops, alerts, savings chart) renders correctly; product list and product detail (3 shops, per-shop health, price history chart, notes, pause, sharing) all work. Dark theme consistent throughout.
- **Recheck cadence:** the spec's locked decision says every 24h per product, but the shipped default is **6h** (`config/dipcatch.php` reads `DIPCATCH_RECHECK_INTERVAL_HOURS`, default 6; the scheduler dispatches every 5 min). The config wins — shops get checked every ~6h unless the env var raises it. Decide which you want before pointing it at real shops.
- **Specs:** everything shipped. Only open item is the Phase 5 admin queue-depth widget in `notifications.md`, which the spec itself defers — `failed-job-monitor` mail already covers the real alert. Not a blocker.

## What to do before production use

### 1. Fix the quality gate (small, do first)
- Repair PHPStan boot (resolve the duplicate `MethodNodeAnalyser` service; check `phpstan.neon` includes against the bumped `rector/type-perfect` / `sandermuller/richter` versions), then run it — it has not checked the recent dep-bump commits.
- Fix the two throttle tests: clear the Redis limiter keys in the tests (or fake the limiter) so runs are isolated.

### 2. Deploy (the README's checklist is complete)
Nothing suggests this is deployed yet. Follow `README.md` → *First deploy*: Postgres add-on, env vars (incl. VAPID keys, mail provider, `FAILED_JOB_MONITOR_NOTIFIABLE`), queue worker (`scrapes,digests,default`), scheduler, `db:seed` for the admin user. Then verify on production: one real product end-to-end (add shop → probe → scheduled recheck → price stored), the daily digest, and web push from your phone/browser.

### 3. The real product risk: scraping coverage
This decides whether "tracking quite some products" actually works for *your* shops:

- Only **3 host adapters** exist (Amazon, Bol, Zooplus). Everything else falls through to JSON-LD → microdata → OpenGraph → generic, and then to manual CSS selectors.
- **No JS rendering in v1** (locked decision). Shops that render prices client-side, plus WAF-heavy shops (Cloudflare/Akamai), will fail or need selectors.
- **Plan:** just start adding your real product URLs in week one and watch the per-shop health column. For each failing host, either add a small host adapter (the pattern is cheap — `BolAdapter` is 1.6K) or pull the deferred Cloudflare Browser Rendering into scope if too many hosts need JS.

### 4. UX friction worth fixing (optional, but you asked about UX)
The flow works but adding a product takes more typing than it should: **Create Product** asks for title, image URL, currency and thresholds manually, and shops are attached afterwards from the product page — while the "Add a shop" probe already extracts title/image/price. A "paste URL first" create flow (probe fills title/image/currency, thresholds from tier defaults) would make bulk-adding products much faster. Everything else I drove felt good.

## Not verified
- Production deploy state (no access from here — confirm on Laravel Cloud).
- Real notification delivery (mail/push) — needs the deployed environment.
- The add-shop probe against live shops in this session (only reviewed the code and the existing product's data).
