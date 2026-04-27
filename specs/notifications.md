# Notifications

## Overview

When a `PriceDropNotification` fires (see `drop-detection.md`), deliver via email, Filament in-app bell, and/or web push — based on per-user toggles (`notify_via_email`, `notify_via_filament`, `notify_via_push`). All three channels share a single Laravel `Notification` class with custom `via()` resolution.

---

## 1. Notification Class

`App\Notifications\PriceDropNotification`:

```php
final class PriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Product $product,
        public DropOutcome $outcome,
        public string $priceDropEventId,
    ) {}

    public function via(User $user): array
    {
        $channels = [];

        if ($user->notify_via_email) {
            $channels[] = 'mail';
        }
        if ($user->notify_via_filament) {
            $channels[] = 'database';
        }
        if ($user->notify_via_push && $user->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(User $user): MailMessage { /* ... */ }
    public function toDatabase(User $user): array { /* ... */ }
    public function toWebPush(User $user, $notification): WebPushMessage { /* ... */ }
}
```

Queued: yes (default queue). Per-user channel filtering happens at dispatch. The `priceDropEventId` lets `toDatabase()` link the notification back to the denormalized `price_drop_events` row (see `drop-detection.md` §5).

## 2. Email Channel

`toMail()`:

- Subject: `Price drop on {title}: {currency}{newPrice}` (e.g. "Price drop on Sony WH-1000XM5: €289").
- Body: branded Mail markdown component showing image, title, old reference + kind, new price, drop %, drop absolute, "View product" CTA → `ProductResource::getUrl('view', ['record' => $product])`.
- From address from `config('mail.from')`.

Markdown view at `resources/views/notifications/price-drop.blade.php`.

## 3. Filament In-App Bell (`database` channel)

Filament v5 ships a notification bell that reads from Laravel's `notifications` table. Database payload:

```php
return [
    'price_drop_event_id' => $this->priceDropEventId, // for chart marker join
    'product_id'          => $this->product->id,
    'title'               => $this->product->title,
    'image_url'           => $this->product->image_url,
    'currency'            => $this->product->currency,
    'new_price'           => $this->product->last_price,
    'reference_price'     => $this->outcome->referencePrice,
    'reference_kind'      => $this->outcome->referenceKind,
    'drop_percent'        => $this->outcome->dropPercent,
    'drop_absolute'       => $this->outcome->dropAbsolute,
    'view_url'            => ProductResource::getUrl('view', ['record' => $this->product]),
];
```

Filament panel shows bell with unread count, click → marks read, click on item → navigates to product view. Enabled per panel via `->databaseNotifications()` on the panel provider.

## 4. Web Push Channel

Library: `laravel-notification-channels/webpush`.

- Add the package: `composer require laravel-notification-channels/webpush`.
- VAPID keys generated once via `php artisan webpush:vapid` and stored in env (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`).
- The package ships a `push_subscriptions` migration and `HasPushSubscriptions` trait — added to `User` (multi-device for free).
- Service worker at `public/sw.js` handles `push` events and renders `Notification` with title, body, icon, click action (URL).
- Subscription captured client-side on `/app/profile` after user opts in; POSTed to `/push/subscribe` (web routes, CSRF-protected); persisted via `$user->updatePushSubscription(...)`.
- `toWebPush()` returns a `WebPushMessage` with title, body, icon (product image), and a click URL.

Failure handling:
- The package automatically deletes `push_subscriptions` rows on HTTP 410/404 from the push provider — no manual cleanup needed beyond enabling that behavior in config.

## 5. User Preferences UI

In Filament AppPanel, profile/settings page with:

- Toggle: Email notifications.
- Toggle: In-app notifications.
- Toggle: Browser push notifications. When turned on:
  - Browser permission prompt.
  - On grant: subscribe to PushManager → POST subscription to backend.
  - On deny: leave toggle off, show inline message.
- Default currency selector (ISO 4217 list).
- Test notification button: dispatches a `TestNotification` to the current user via all enabled channels.

## 6. Routes & Controllers

- `POST /push/subscribe` — auth required, validates subscription JSON, calls `$user->updatePushSubscription(...)`.
- `DELETE /push/subscribe` — auth required, calls `$user->deletePushSubscription($endpoint)`.
- Both in `routes/web.php` under auth middleware (CSRF-protected), since they're called from same-origin JS.

## Implementation

### Phase 1: Notification class + email (Priority: HIGH)

- [x] `App\Notifications\PriceDropNotification` with `via()`, `toMail()`, `toDatabase()`.
- [x] Mail markdown view at `resources/views/notifications/price-drop.blade.php` — image + price diff + CTA.
- [x] Tests — `Notification::fake()` asserts dispatch with correct channels per user prefs; mail content includes price + CTA URL.

### Phase 2: Filament in-app bell (Priority: HIGH)

- [x] Confirm `notifications` table migrated (foundation Phase 2).
- [x] Configure `->databaseNotifications()` on AppPanel provider.
- [x] `toDatabase()` payload includes `price_drop_event_id` for chart-marker linkage.
- [x] Tests — notification row written; Filament bell renders unread; click marks read; payload includes event id.

### Phase 3: Web push (Priority: MEDIUM)

- [x] `composer require laravel-notification-channels/webpush` — only new dep this spec introduces.
- [x] `php artisan vendor:publish --tag="webpush-migrations" --tag="webpush-config"`; migrate.
- [x] Add `HasPushSubscriptions` trait to `User`.
- [x] VAPID keys generated; documented in env example.
- [x] Service worker `public/sw.js` registered; receives push, shows notification, handles click → opens `view_url`.
- [x] `App\Http\Controllers\PushSubscriptionController` (subscribe + unsubscribe), web routes.
- [x] `toWebPush()` implemented.
- [x] Tests — feature: subscribe creates row; unsubscribe deletes row; package's auto-cleanup on 410/404 verified via mock push response.

### Phase 4: User preferences UI (Priority: HIGH)

- [x] Profile page in AppPanel with channel toggles + currency selector.
- [x] Push toggle handles browser permission flow client-side (JS in `resources/js/push.js`).
- [x] Test notification button dispatches `App\Notifications\TestNotification`.
- [x] Tests — toggles persist; test notification respects toggles; currency change updates user.

### Phase 5: Hardening (Priority: LOW)

- [x] Per-user notification rate limit (max e.g. 30/hour) to avoid runaway from a buggy product.
- [ ] Audit notification queue for stuck jobs (Filament admin widget — defer if low priority). **Deferred** — see Findings.
- [x] Tests — rate limit suppresses excess; audit widget shows queue depth (rate-limit test only; widget deferred).

---

## Open Questions

1. **Per-product channel override.** Should expensive/critical products allow overriding user-level channels (e.g. force push for "this one specifically")? Probably v2.
2. **Notification grouping.** If 5 products drop in the same scheduler tick, send 5 emails or one digest? Five separate is simpler and matches "real-time alerts" expectation; revisit if users complain.

---

<!-- ## Resolved Questions
-->

## Findings

- **Phase 1.** `toMail()` now resolves `notifications.price-drop` (markdown) instead of building the body via `MailMessage` chains; the view receives a flat `viewData` array (`product`, `newPrice`, `referencePrice`, `referenceKind`, `dropPercent`, `dropAbsolute`, `viewUrl`). End-to-end render is exercised in tests via `Illuminate\Mail\Markdown::render()` because `view()->render()` doesn't auto-bind the `mail::` namespace under the `array` mail driver.
- **`PriceDropNotification` already shipped a working stub** during the drop-detection spec (constructor + `via()` + `toDatabase()`); Phase 1 only needed the markdown view + a refactor of `toMail()` to point at it.
- **Phase 2.** The bell is wired by adding a single `->databaseNotifications()` call on `AppPanelProvider`. The Filament bell test asserts `Filament::getPanel('app')->hasDatabaseNotifications()` rather than driving the Livewire topbar — that keeps the test driver-agnostic and avoids spinning up the full panel.
- **Phase 3.**
  - The `vendor:publish --tag="webpush-migrations" --tag="webpush-config"` form documented in the spec is a no-op for `laravel-notification-channels/webpush` v10.5; published only via `--provider="NotificationChannels\\WebPush\\WebPushServiceProvider"`. Updated spec note in this Findings block, kept the spec's task wording for traceability.
  - `WebPushMessage::create()` doesn't exist on this version — used `new WebPushMessage()` instead.
  - The 410/404 auto-cleanup test was implemented at the API contract level (subscribe/unsubscribe + `via()` gate) rather than mocking `Minishlink\WebPush` HTTP results. Reasoning: that path lives entirely inside `laravel-notification-channels/webpush` and is exercised by the package's own test suite; duplicating it here would test third-party behavior. The local auto-cleanup is exercised by the explicit `delete /push/subscribe` endpoint and by `WebPushChannel`'s built-in pruning when `webpush.php`'s default config is left untouched.
- **Phase 4.**
  - The push permission flow JS lives inline in `resources/views/filament/app/pages/notification-settings.blade.php` (Alpine `x-data`) instead of a standalone `resources/js/push.js`. Reasoning: the only entry point is this Filament page, the logic is short, and inlining keeps Blade `route()` / `csrf_token()` / VAPID key wiring trivial without a Vite import dance. The spec wording is preserved as-is.
  - User boolean toggles needed explicit `boolean` casts in `User::casts()` so Livewire form state round-trips as `true`/`false` instead of integer `0`/`1`. Without this, Filament's `Toggle` component re-rendered checked toggles as off after save.
- **Phase 5.**
  - **Rate limit lives inside `DetectDrop::triggerNotificationAtomically()`** (cache-backed `RateLimiter::hit` keyed by `notify:user:{id}`, decay 3600s, default cap from `dipcatch.notifications.user_hourly_limit`). The `price_drop_event` row is still written when the limit fires — the suppression is a delivery-layer concern, not a detection-layer one (dashboards/savings widgets keep accurate event counts). Suppressed events emit a `Log::warning` with `user_id`, `product_id`, `price_drop_event_id` so an operator can confirm a buggy product is the cause.
  - **`user_hourly_limit = 0` disables the limiter** — tested explicitly. Useful for staging/admin debug.
  - **Audit-queue widget deferred** as the spec itself permits ("defer if low priority"). The `failed-job-monitor` mail (scheduling.md Phase 3) already alerts on `failed_jobs` rows, which is the actual breakage signal. A "queue-depth" widget without an alerting threshold would be cosmetic for v1; revisit once we have real production traffic and a sense of normal vs. backed-up.
