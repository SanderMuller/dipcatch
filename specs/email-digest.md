# Email Digest Mode

## Overview

Replace the per-drop email with a once-daily digest delivered at 09:00 user-local time. Email becomes a batched summary: one message per day per user grouped by product, listing every drop that fired in the prior 24h. Filament in-app bell + web push stay per-drop (they're already low-cost / in-channel).

User decisions locked at spec time:

- **Replace** the existing per-drop email path entirely; no `'per_drop' | 'digest'` toggle. One code path.
- **Daily at 09:00 user-local time.** Adds a `users.timezone` column (default `Europe/Amsterdam` per the launch-locale assumption).

This is a cleanup + feature spec, not on the launch-readiness build order in `specs/README.md`.

---

## Current state

- `App\Notifications\PriceDropNotification::via()` (`app/Notifications/PriceDropNotification.php:56`) routes to `mail` whenever the user's `notify_via_email` boolean is true.
- `toMail()` (line 73) renders a per-drop subject + body: product name, old price, new price, percent drop, shop, link.
- Users table has `notify_via_email` / `notify_via_filament` / `notify_via_push` toggles (`app/Models/User.php:34-36`); no `timezone` column today.
- `PriceDropEvent` rows are already the persistent record of each fired notification, carrying `user_id`, `price_check_id`, `triggered_by_shop_id`, `fired_at` (per `notifications.md` § "Drop event log").
- Tests asserting per-drop email delivery: `tests/Feature/Notifications/PriceDropNotificationTest.php` + `tests/Feature/Notifications/HourlyRateLimitTest.php` (touches the rate-limit on the per-drop path).

---

## Data model changes

### `users` table

Add two columns via a new migration (`add_digest_columns_to_users_table`):

```php
$table->string('timezone', 64)->default('Europe/Amsterdam');
$table->timestampTz('last_digest_sent_at')->nullable();
```

- `timezone` — IANA name. Validated against `DateTimeZone::listIdentifiers()` on profile edit.
- `last_digest_sent_at` — when the most recent digest *succeeded* (mail handed to the transport). Used by the dispatch query as the "include drops fired since" lower bound.

No column on `price_drop_events` — the existing `fired_at` + the new `users.last_digest_sent_at` together drive inclusion.

### `users` model

- Add `'timezone' => 'string'` and `'last_digest_sent_at' => 'datetime'` to `casts()`.
- Add to fillable + Fortify `Fillable` attribute (`app/Models/User.php:18`).

---

## New job + command

### `App\Jobs\SendDailyDigest`

- Constructor takes a `User`.
- `handle()`:
  1. Collect `PriceDropEvent` rows where `user_id = $user->id` AND `fired_at > coalesce($user->last_digest_sent_at, now()->sub(24h))`. Cap at, say, 100 rows per digest to bound the email size.
  2. If empty → return early. Do **not** send an empty digest. Do **not** update `last_digest_sent_at`; a later non-empty window will pick up.
  3. Group rows by `product_id`. Eager-load product + triggering shop + their `cheapest_price` context.
  4. `Mail::to($user)->send(new PriceDropDigestMail($user, $groupedDrops))`.
  5. On successful queue dispatch (use `Mail::send`, not `Mail::queue` — `SendDailyDigest` is already a queued job; queueing the mail inside doubles up), update `$user->last_digest_sent_at = now()`.
- `tries = 3`, `backoff = [60, 300, 1800]` — transient mail failures retry, permanent failures (invalid email) raise and land in `failed_jobs`.
- `uniqueId()` = `"daily-digest:{$user->id}:{$dateKey}"` where `$dateKey` is the digest's local-date so a second dispatch the same local day is a no-op. `uniqueFor` ≈ 24h.

### `App\Console\Commands\DispatchDailyDigestsCommand`

- Signature: `dipcatch:dispatch-daily-digests`.
- Scheduled `everyMinute()` via `bootstrap/app.php` `withSchedule(...)` (matches existing `RecheckActiveShopsCommand` pattern), `withoutOverlapping`, `onOneServer`.
- Logic:
  - For each distinct user timezone present in `users` (cheap groupby), check whether the current UTC time corresponds to ≥ 09:00 local that day **and** the user's `last_digest_sent_at` is null or on a prior local date.
  - For matching users, dispatch `SendDailyDigest` onto a low-priority `digests` queue.
- Batch cap (e.g. 500 per minute) to avoid mailer-burst rate-limit hits. Configurable via `dipcatch.digest.batch_size`.

---

## Mail template

`App\Mail\PriceDropDigestMail` (Laravel Mailable) backed by a Blade view `resources/views/emails/price-drop-digest.blade.php`:

- Subject: `"{$count} price drops today"` (e.g. "3 price drops today"). Subject in user's locale once locales land; English-only for now.
- Body: one section per product, listing each drop (old → new, percent, shop, timestamp). Link to product page.
- Footer: "Manage your digest in Profile → Notifications" link.

---

## Phases

### Phase 1 — Schema + model

1. Migration adds `users.timezone` + `users.last_digest_sent_at`. Don't backfill `last_digest_sent_at` — null is the correct initial state ("never received a digest, include the past 24h on first send").
2. Update `User` model: casts, fillable, `Fillable` attribute.
3. Add `App\Rules\IanaTimezone` (or inline closure) for validating the timezone string at profile edit.
4. Test: model attribute round-trip + invalid timezone rejected.

### Phase 2 — Job + command + scheduling

1. `App\Jobs\SendDailyDigest` (per design above).
2. `App\Console\Commands\DispatchDailyDigestsCommand`.
3. Register in `bootstrap/app.php` schedule alongside `RecheckActiveShopsCommand`.
4. Test: command dispatches for due users, skips not-due users, respects batch cap, idempotent on re-run within the same local day.

### Phase 3 — Mail template + assembly

1. `App\Mail\PriceDropDigestMail`.
2. `resources/views/emails/price-drop-digest.blade.php`.
3. `SendDailyDigest::handle()` collects rows, groups, sends, updates `last_digest_sent_at`.
4. Test: empty-window → no mail, no `last_digest_sent_at` update; non-empty → mail sent with expected drops; idempotent re-run skips already-sent drops.

### Phase 4 — Remove per-drop email path

1. `PriceDropNotification::via()`: drop the `mail` branch. Keep `database` + `webpush` branches.
2. Delete `toMail()` from `PriceDropNotification`.
3. Update `tests/Feature/Notifications/PriceDropNotificationTest.php` — remove email assertions; keep Filament bell + push assertions.
4. `tests/Feature/Notifications/HourlyRateLimitTest.php` — re-scope to whatever per-channel limit remains; the per-drop email limit becomes moot but the same per-user limit may apply to bell + push.
5. Profile UI (`resources/views/...profile.blade.php` or Filament page): rename the `notify_via_email` toggle to "Send me a daily email digest" so it reflects the new semantics. Same boolean column, new label + help text.

### Phase 5 — Verify

1. `vendor/bin/pest --compact`.
2. `vendor/bin/pint --dirty --format agent`.
3. `vendor/bin/phpstan analyse --memory-limit=2G`.

---

## Open Questions

- **Q1:** what's the cutoff for "include drops since"? Default = `coalesce(last_digest_sent_at, now()->subDay())`. Alternative = strict 24h window regardless of `last_digest_sent_at`. The coalesce form handles the first-ever digest and also fills a multi-day gap if a user's mail bounced and digests didn't update for days. **Default:** coalesce. Cap the included window at 7 days max to avoid a backlog blowup.
- **Q2:** dedicated `digests` queue or share `default`? **Default:** new `digests` queue. Lets the queue worker config (`--queue=scrapes,default,digests`) bias the run order so a noisy fetch backlog doesn't block the 09:00 burst.
- **Q3:** what does the profile page show when the user has `notify_via_email = false`? Hide the digest description entirely, or render it greyed-out with a "turn on email to receive your daily digest" affordance? **Default:** render greyed-out — discoverability matters more than visual minimalism.
- **Q4:** Filament admin queue widget (the long-deferred `notifications.md` Phase 5) — does this spec close it as well, since `digests` is a new queue worth watching? **Default:** no — out of scope here. Leave the deferred widget alone.

---

## Findings

- **Q1 default applied**: lookback coalesces null → 24h, caps at `dipcatch.digest.lookback_days` (default 7). Validated by a dedicated test.
- **Q2 default applied**: dispatched on a new `digests` queue. Assertion in the command test pins this so a future refactor can't silently re-route the load.
- **Q3 (profile UI)**: kept the email toggle visible with sharpened label ("Daily email digest") + helper text explaining the new semantics ("One summary email per day at 09:00 in your local timezone … Real-time email per drop is no longer sent."). Other toggles got matching "Real-time" annotations so the digest/real-time split is legible.
- **Q4 (admin queue widget)**: untouched — out of scope.
- **TestNotification kept its email path**: a "send test email" probe is a delivery sanity check, not a per-drop preview. Reworded its body to set expectations ("Real price-drop alerts arrive as a daily digest at 09:00 in your local time.") instead of removing the channel.
- **HourlyRateLimitTest** had user setups assuming `notify_via_email` triggered the per-drop send. With email decoupled from `PriceDropNotification`, those users' `via()` returned `[]` and the notification never sent. Flipped the test setups to `notify_via_filament => true` so there's a real-time channel for the rate-limiter to gate.
- **Orphaned view**: deleted `resources/views/notifications/price-drop.blade.php` (the per-drop markdown template). Its only consumer was the removed `toMail()` method.
- **PHPStan + the `groupBy` callback**: had to loosen `PriceDropDigestMail`'s `@param` to accept `Collection<int|string, array{product: ?Product, events: EloquentCollection<int, Model>}>` because `->values()` on an Eloquent `Collection<int, PriceDropEvent>` widens back to `Collection<int, Model>` and Laravel's stubs don't carry the narrowing. Functional impact: nil — the blade view still iterates `$events` as `PriceDropEvent` instances.
