# Homepage Relaunch

<!-- spec:planned-at 1e127f57a3e53ab9c45d6a36456a7e9fceaba6ee 2026-09-02 +uncommitted -->

## Overview

Re-aims the homepage at what DipCatch actually is today: a Dutch supermarket price comparator with unit prices, promo detection and drop alerts — not a generic "track products across the web" tool. The hero copy, the phone mock, and the step cards get rewritten around the compare-across-shops story; a live example links to a real public share page; an FAQ answers the questions that stop hesitant sign-ups; and the marketing pages get a Dutch version. The quick wins (meta/OG, supported-shop row, bottom CTA, privacy page, footer) already shipped in `87082c8` and are out of scope here.

## Assumptions

- **English stays the app language; only the marketing pages get Dutch** — the Filament app, emails, and the public share page keep English. See Open Question 1 for the default-language choice.
- **The live example is one product the owner tracks, referenced by its share slug via config** (`site.demo_share_slug`) — not a synthetic fixture. Rationale: the public share page (`routes/web.php:16`, `PublicProductController`) already renders real prices with favicons and unit prices; anything synthetic would be less convincing and needs maintenance. If the config is empty, the link does not render.
- **The phone mock stays static HTML (no Livewire, no live data)** — a mock that always looks good beats a live widget that depends on today's promos. Amounts and shops in the mock are plausible but fixed; they are labelled as an example in an `aria-label`, not sold as live.
- **Price display in the mock uses the app's `MoneyFormatter` style (`EUR 1.69`)**, so the homepage matches the app. Changing the app-wide format to `€ 1,69` is a separate decision (Open Question 2).
- **FAQ content is written by the implementer from verified behaviour** — every answer below is traced to code (see Section 4) and the spec lists the exact claims. No marketing hyperbole; Simplified Technical English for the EN copy.

---

## 1. Current State

`resources/views/welcome.blade.php` (after `87082c8`):

- Hero: `$h1 = 'Catch every price drop.'`, `$sub = 'Track products across the web. DipCatch checks prices on a schedule…'` (lines 2–3). Generic; no mention of shops, comparison, or unit prices.
- Phone mock: `$tracked` array (lines 14–18) shows Sony WH-1000XM5 on `amazon.com`, Kindle Paperwhite on `bol.com`, Dyson V15 on `mediamarkt.nl`. `mediamarkt.nl` has no adapter and no dataset; electronics is not the target use.
- Steps (`$steps`, lines 8–12): already rewritten around link → other shops → dip. Keep.
- Supported-shop row, bottom CTA, footer: shipped, keep.
- `<head>`: `partials.head` + meta/OG (shipped). `<html lang>` follows `app()->getLocale()` which is always `en` (`config/app.php:83`, no `lang/` directory exists).
- Public share page: `GET /p/{slug}` (32-char alphanumeric, IP-throttled) renders title, cheapest price, 90-day chart, shop list with favicons and unit prices, and Open Graph tags. Requires `products.share_slug` set on the product.
- Money: `App\Support\MoneyFormatter::format()` → `"EUR 1.69"` (currency code, dot decimal, comma thousands).

## 2. Hero and Phone Mock

### 2.1 Copy (EN)

- H1: **Same product, every supermarket, one alert.**
- Sub: **DipCatch watches the groceries you buy anyway across AH, Jumbo, Dirk, Lidl, Aldi and more, compares them on price per kilo, and tells you when one drops.**
- Badge stays "Open beta". CTA stays "Create a free account" + verification note.

The H1 must fit `max-w-[24ch]` at `sm:text-6xl` on two lines; the implementer checks this in the browser at 390 px, 768 px and 1280 px.

### 2.2 Phone mock content

Replace `$tracked` with three grocery notifications built from the shops DipCatch supports today. Values are fixed examples, not live data:

| Icon | Product | Shop | From → to | Unit price line |
|---|---|---|---|---|
| 🥔 | Lay's Naturel 200 g | ah.nl | EUR 2.19 → **EUR 1.69** (bonus) | EUR 8.45 /kg · cheapest of 4 shops |
| 🧀 | Beemster Extra Belegen 48+ 150 g | dirk.nl | EUR 3.49 → **EUR 1.69** | EUR 11.27 /kg · was cheapest at ah.nl |
| 🧻 | Page toiletpapier 24 rollen | jumbo.com | EUR 12.99 → **EUR 9.99** | EUR 0.42 /stuk |

Each card shows the shop favicon (`App\Support\Favicon::url($host)`) instead of the current "DipCatch" label, the product line, the drop line in emerald, and the unit-price line in muted text. Card 1 keeps the emerald ring. The mock container gets `role="img"` and an `aria-label` ("Example alerts: Lay's at ah.nl dropped to EUR 1.69, …") so screen readers get one sentence instead of nine fragments.

### 2.3 Live example link

Under the hero CTA (guests only): **"See a live example →"** linking to `route('product.public', ['slug' => config('site.demo_share_slug')])`, `target="_blank" rel="noopener"`. Rendered only when `config('site.demo_share_slug')` is a 32-char alphanumeric string **and** a product with that `share_slug` exists (one cached query, 10 min, so a revoked share does not leave a dead link). Env: `SITE_DEMO_SHARE_SLUG`.

## 3. Dutch Marketing Pages

- Add `lang/nl.json` with translations for every `__()` string in `welcome.blade.php` and `privacy.blade.php` only. The app panel is untouched.
- Locale resolution for the two marketing routes (`home`, `privacy`): a `MarketingLocale` middleware sets the locale from, in order: `?lang=nl|en` query (also stored in the session), the session value, the `Accept-Language` header (first tag `nl*` → `nl`, anything else → `en`), then the default from Open Question 1.
- A small language toggle (NL / EN) next to the appearance toggle in the header of both pages; it links to the current URL with `?lang=`.
- `<html lang>` and the `og:locale` meta follow the resolved locale. `hreflang` alternates (`nl`, `en`, `x-default`) are added to `<head>` of both pages.
- The Filament app and emails stay English; `App::setLocale` is scoped to the marketing routes via the middleware, so nothing else changes.

## 4. FAQ Section

Placed between "How it works" and the bottom CTA. Six items, native `<details>` disclosures (same pattern as the Add-a-shop header, `add-shop-header.blade.php`, no JS), each question an `<summary>`. Claims and their sources:

| Question | Answer (EN) | Traced to |
|---|---|---|
| Which shops work? | AH, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz, Vomar, bol.com, Amazon.nl, Zooplus have dedicated support; most other webshops that show a price on the product page work too. AH bonus and Dirk promo prices are detected. | `config/dipcatch.php` adapters list; `config/site.php` supported hosts; `AhApiSource`, `DirkAdapter` |
| How often are prices checked? | Automatically, about every 6 hours (the interval is `dipcatch.recheck.interval_hours`, default 6, plus up to 30 minutes of jitter); you never trigger a check yourself. | `config/dipcatch.php:57-60` |
| Is it free? | Yes, during the beta. No card, no trial timer. | registration open (`config/fortify.php`), no billing code |
| Do I need an extension or app? | No. Paste a link in the browser; alerts arrive by email digest, in-app bell, or browser push if you enable it. | `NotificationSettings` page; `notification-settings.blade.php` |
| Can I compare different pack sizes? | Yes. DipCatch reads the pack size and shows a price per kilo, litre or piece next to every shop. | `App\Support\PackSize`, `Shop::unitPrice()` |
| Can I share a comparison? | Yes. Every product has an optional public page with the current prices and a 90-day chart; anyone with the link can view it, nothing about your account is shown. | `product-sharing-modal.blade.php`, `PublicProductController` |

Each answer is at most three sentences. The FAQ gets `FAQPage` JSON-LD (`Question`/`Answer` pairs) generated from the same array, so copy lives once.

## 5. Config and Tests

- `config/site.php`: add `demo_share_slug` (env `SITE_DEMO_SHARE_SLUG`), `faq` is not config — it lives in the view's `@php` array, next to `$steps`, so translators see it in one file.
- `.env.example`: `SITE_DEMO_SHARE_SLUG=`.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `SITE_DEMO_SHARE_SLUG` unset or malformed | No live-example link renders (Phase `hero`, test) |
| Demo product's share revoked (`share_slug` null) | Existence check fails → link hidden within 10 min (cache TTL) (Phase `hero`, test with cache cleared) |
| Visitor with `Accept-Language: nl-NL,nl;q=0.9,en;q=0.8` | Locale `nl`; `<html lang="nl">`; `?lang=en` overrides and sticks in the session (Phase `nl`, tests) |
| Visitor with `Accept-Language: de` | Default locale from Open Question 1 (Phase `nl`, test) |
| Signed-in user on the homepage | Locale logic still applies; app remains English after clicking "Open app" (Phase `nl`, test asserts `/app` renders English) |
| Screen reader on the phone mock | One `aria-label` sentence; inner text is `aria-hidden` (Phase `hero`, manual check with VoiceOver noted in Findings) |
| FAQ JSON-LD | Validates in Google's Rich Results test; answers contain no HTML (Phase `faq`, unit test asserts valid JSON with 6 `Question` entities) |
| H1 wraps to three lines on 390 px | Reduce to `text-4xl` at base (already) and check the `[24ch]` cap; adjust copy rather than shrinking type below `text-4xl` (Phase `hero`, browser check) |

## Implementation

### Phase 1: Hero, mock, live example (Priority: HIGH)

**ID:** hero · **Depends:** none

- [ ] Replace `$h1`/`$sub` with the Section 2.1 copy — keep `max-w-[24ch]` / `max-w-[48ch]`.
- [ ] Rebuild `$tracked` and the mock cards per Section 2.2 — favicon via `Favicon::url()`, unit-price line, `role="img"` + `aria-label` on the container, inner content `aria-hidden`.
- [ ] Add `site.demo_share_slug` config + env example; render the "See a live example" link per Section 2.3 with the cached existence check.
- [ ] Tests — homepage shows the new H1; mock contains the three shops and no `mediamarkt.nl`; live-example link renders only with a valid slug that exists; cache hides it after revoke.
- [ ] Browser check at 390 / 768 / 1280 px, light and dark; note results in Findings.

### Phase 2: FAQ (Priority: HIGH)

**ID:** faq · **Depends:** none

- [ ] Add the six-item `$faq` array and the `<details>` section per Section 4, between "How it works" and the bottom CTA.
- [ ] Emit `FAQPage` JSON-LD from the same array.
- [ ] Read the re-check interval from `config('dipcatch.recheck.interval_hours')` in the "How often" answer so the copy cannot drift.
- [ ] Tests — six questions visible; JSON-LD is valid JSON with six `Question` entities; answers contain no HTML.

### Phase 3: Dutch marketing pages (Priority: MEDIUM)

**ID:** nl · **Depends:** hero, faq

- [ ] `lang/nl.json` covering every `__()` string in `welcome.blade.php` and `privacy.blade.php` (after Phases 1–2 so the strings are final).
- [ ] `MarketingLocale` middleware (query → session → `Accept-Language` → default), applied to the `home` and `privacy` routes only.
- [ ] Header language toggle on both pages; `<html lang>`, `og:locale`, `hreflang` alternates.
- [ ] Tests — the Edge Cases table's locale rows; `/app` stays English for a signed-in user after visiting the Dutch homepage; every key in `lang/nl.json` is used by one of the two views (no orphans) and every `__()` string in the two views has a translation (no leaks).

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The public share page renders for a guest with only `share_slug` set** — the live example depends on `PublicProductController` needing nothing else; if it requires auth or extra state, the link design changes.
2. **`__()` in Blade resolves through `lang/nl.json` without a `lang/` publish step** — Laravel 13 reads `lang/*.json` when the directory exists; if the app has `lang_path` customised, Phase 3 changes.

---

## Open Questions

1. **Default language for visitors without a Dutch `Accept-Language`: English or Dutch?** Every supported shop is Dutch, so NL-first is the natural default; English-first keeps the page readable for the beta testers who are not Dutch. The spec is written NL-first-neutral: Phase 3 needs this decided before the middleware's fallback is set.
2. **Should the app switch to `€ 1,69` style formatting?** `MoneyFormatter` renders `EUR 1.69` everywhere. Dutch users expect `€ 1,69`. This is an app-wide change (Filament tables, emails, share page, mock) and is not part of this spec; decide separately. The mock follows whatever the app does.
3. **Which product is the live example?** Needs a product you own with sharing enabled and at least three shops (the Lay's product qualifies today). Set `SITE_DEMO_SHARE_SLUG` on Laravel Cloud when chosen.

---

<!-- ## Resolved Questions
1. **{Original question?}** **Decision:** {What was decided.} **Rationale:** {Why.}
-->

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
