# Browser-Discovered Timezone on First Login

## Overview

Every user currently starts on `users.timezone = 'Europe/Amsterdam'` (the migration default). A non-Dutch user would silently get yesterday's 11:00 / 03:00 / 17:00 digest until they manually open the Notification Settings page. Detect the browser's IANA timezone on first authenticated page load and persist it; never overwrite a value the user explicitly chose.

This is a small feature spec, not on the launch-readiness build order in `specs/README.md`.

User decisions locked at spec time (defaults — speak up to override):

- **Storage flag**: dedicated `users.timezone_detected_at` (`timestampTz`, nullable) column. Null = never auto-detected; auto-detect fires until set. `NotificationSettings::save()` also stamps it so an explicit save blocks future auto-detection.
- **Trigger**: every authenticated app-panel page load. Server-side **atomic** — the controller updates via a single `WHERE id = ? AND timezone_detected_at IS NULL` SQL statement so a concurrent explicit save can't be clobbered (see Codex finding #1 below).
- **UX**: silent. No toast, no banner. Consistent with the digest opt-in.
- **Scope**: every authenticated user, **including admins** when they're using the app panel. The earlier "admins excluded" line was wrong — `User::canAccessPanel('app')` returns `true` for everyone, so admins do receive digests if they opt in. Excluding them would need an explicit `is_admin` check we don't actually want.

---

## Current state

- `users.timezone` exists ([migration:13](../database/migrations/2026_05_17_134441_add_digest_columns_to_users_table.php), default `'Europe/Amsterdam'`).
- `users.last_digest_sent_at` ditto.
- `App\Rules\IanaTimezone` validates strings against `IanaTimezones::isValid()` ([Rule:18](../app/Rules/IanaTimezone.php)).
- `App\Filament\App\Pages\NotificationSettings::save()` already writes `timezone` directly ([page:94](../app/Filament/App/Pages/NotificationSettings.php)).
- Filament's base layout already renders `<meta name="csrf-token" content="{{ csrf_token() }}">` — JS can read it without an extra render hook.
- App panel runs CSRF + auth + verified middleware ([provider:53](../app/Providers/Filament/AppPanelProvider.php)).
- Project controller convention is **flat under `App\Http\Controllers\`** (`PushSubscriptionController`, `InvitationController`). No nested `Profile/` namespace.
- Validation convention is `SanderMuller\FluentValidation\FluentRule` (see `PushSubscriptionController.php:15-20`), not Laravel array rules.

---

## Data model

Single migration `add_timezone_detected_at_to_users_table`:

```php
$table->timestampTz('timezone_detected_at')->nullable();
```

`User` model: add `'timezone_detected_at' => 'datetime'` cast. Factory default null.

---

## Backend

`App\Http\Controllers\AutoDetectTimezoneController` (single-action `__invoke`), `POST /profile/timezone/auto-detect`:

- `web` + `auth` + `verified` middleware (same stack as the existing push routes in `routes/web.php`).
- Validates `timezone` via FluentRule with the existing `IanaTimezone` rule:
  ```php
  $request->validate(['timezone' => FluentRule::string()->required()->rules([new IanaTimezone()])]);
  ```
- **Atomic conditional update** — single SQL statement, no read-then-write:
  ```php
  User::query()
      ->whereKey($request->user()->id)
      ->whereNull('timezone_detected_at')
      ->update([
          'timezone' => $data['timezone'],
          'timezone_detected_at' => now(),
      ]);
  ```
  Returns `response()->json(['ok' => true])` regardless of row count (the request is fire-and-forget from the client). Affected rows = 0 means "already set"; no error.
- CSRF protected via Laravel's default web stack.

Route registration in `routes/web.php` (mirror the `auth`+`verified` group that already wraps `push/subscribe`).

---

## NotificationSettings save() change

When the user explicitly clicks "Save preferences", also stamp `timezone_detected_at = now()`. Prevents auto-detect from running again — even if the user chose to keep the default `Europe/Amsterdam`, explicit save is the strongest signal of intent.

---

## Frontend

Filament render hook in `App\Providers\Filament\AppPanelProvider`:

- `PanelsRenderHook::BODY_END` — inject a `<script>` block that:
  1. Reads a server-rendered `<meta name="dipcatch-timezone-detected" content="true|false">` (rendered via `PanelsRenderHook::HEAD_END`, reads `auth()->user()->timezone_detected_at !== null`).
  2. If `false`, reads `Intl.DateTimeFormat().resolvedOptions().timeZone`.
  3. POSTs to `/profile/timezone/auto-detect` with the CSRF token from Filament's existing `<meta name="csrf-token">`.
  4. Fire-and-forget; failures log to console, otherwise silent.

The `dipcatch-timezone-detected` meta tag is the new render hook; the CSRF tag is already there.

---

## Phases

### Phase 1 — Schema + model

1. Migration adds `users.timezone_detected_at`.
2. `User::casts()` gains the datetime cast.
3. `UserFactory` default `null`.
4. Test: round-trip + new-user default.

### Phase 2 — Controller + route

1. `AutoDetectTimezoneController` + POST route in `routes/web.php` (auth + verified stack).
2. Tests (all server-side, no JS needed):
   - happy path (sets both fields, returns ok=true)
   - idempotent (row count 0 when `timezone_detected_at` is already set; controller still returns ok=true, **previous timezone unchanged**)
   - invalid timezone rejected with 422
   - unauthenticated request rejected with 302/401
   - CSRF rejected without the token

### Phase 3 — NotificationSettings save bumps `timezone_detected_at`

1. Update `NotificationSettings::save()` to set `timezone_detected_at = now()`.
2. Test: explicit save populates the field; a subsequent POST to the auto-detect endpoint does not overwrite the value.

### Phase 4 — Filament render hook + JS

1. Register `PanelsRenderHook::HEAD_END` (meta tag) + `BODY_END` (script) in `AppPanelProvider`.
2. Blade view for the script body (or inline string).
3. Phase 2 tests cover the contract. The browser `Intl` value itself is manual-only — capture a manual smoke-test outcome in Findings.

### Phase 5 — Verify

1. `vendor/bin/pest --compact`.
2. `vendor/bin/pint --dirty --format agent`.
3. `vendor/bin/phpstan analyse --memory-limit=2G`.

---

## Open Questions

- **Q1:** browser reports an unrecognised timezone (very old browser, custom build). **Default:** server rejects via the `IanaTimezone` rule with a 422; JS swallows the failure silently. User stays on the existing default until they pick one manually.
- **Q2:** show a "we detected your timezone as X" notice on the next NotificationSettings page load? **Default:** no — silent, consistent with the rest of the app. Easy to add later.
- **Q3:** apply this to the admin panel too? **Default:** no — admins have a separate panel and the auto-detect runs everywhere they use the app panel anyway. Adding an admin-panel render hook would just duplicate work.

---

## Findings

(filled during implementation)
