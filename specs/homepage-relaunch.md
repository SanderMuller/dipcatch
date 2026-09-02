# Homepage Relaunch

<!-- spec:planned-at 1e127f57a3e53ab9c45d6a36456a7e9fceaba6ee 2026-09-02 +uncommitted -->

## Overview

Re-aims the homepage at what DipCatch actually is today: a Dutch supermarket price comparator with unit prices, promo detection and drop alerts — not a generic "track products across the web" tool. The hero copy, the phone mock, and the step cards get rewritten around the compare-across-shops story; a live example links to a real public share page; an FAQ answers the questions that stop hesitant sign-ups; and the marketing pages get a Dutch version. The quick wins (meta/OG, supported-shop row, bottom CTA, privacy page, footer) already shipped in `87082c8` and are out of scope here.

## Assumptions

- **English stays the app language; only the marketing pages get Dutch** — the Filament app, emails, and the public share page keep English. See Open Question 1 for the default-language choice.
- **The live example is a real tracked product, referenced by its share slug via config** (`site.demo_share_slug`) — not a synthetic fixture. Rationale: the public share page (`routes/web.php:16`, `PublicProductController`) already renders real prices with favicons and unit prices; anything synthetic would be less convincing and needs maintenance. The product should live in a **dedicated demo account** (for example `demo@dipcatch.eu`) rather than the owner's personal account, so the homepage never exposes a personal watch list and the link survives personal clean-ups. If the config is empty, the link does not render — and Phase 1 is not *done* until the slug is set in production or the owner explicitly accepts shipping without the link (Open Question 3).
- **The phone mock stays static HTML (no Livewire, no live data)** — a mock that always looks good beats a live widget that depends on today's promos. Amounts and shops in the mock are plausible but fixed; they are labelled as an example in an `aria-label`, not sold as live.
- **Price display in the mock uses the app's `MoneyFormatter` style (`EUR 1.69`)**, so the homepage matches the app. Changing the app-wide format to `€ 1,69` is a separate decision (Open Question 2).
- **The shop list in the FAQ and in `config('site.supported_hosts')` names only hosts with a merged adapter or data source** — `aldi.nl` is listed in `config/site.php` today ahead of the `AldiAdapter` that is in progress in another branch; the implementer re-checks `config/dipcatch.php` at implementation time and drops any host without one.
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
| 🧀 | Beemster Extra Belegen 48+ 150 g | dirk.nl | EUR 3.49 → **EUR 1.69** | EUR 11.27 /kg · cheapest of 3 shops |
| 🧻 | Page toiletpapier 24 rollen | jumbo.com | EUR 12.99 → **EUR 9.99** | EUR 0.42 /stuk |

Each card shows the shop favicon (`App\Support\Favicon::url($host)`) instead of the current "DipCatch" label, the product line, the drop line in emerald, and the unit-price line in muted text. Card 1 keeps the emerald ring. The mock container gets `role="img"` and an `aria-label` ("Example alerts: Lay's at ah.nl dropped to EUR 1.69, …") so screen readers get one sentence instead of nine fragments.

### 2.3 Live example link

Under the hero CTA (guests only): **"See a live example →"** linking to `route('product.public', ['slug' => config('site.demo_share_slug')])`, `target="_blank" rel="noopener"`. Rendered only when `config('site.demo_share_slug')` is a 32-char alphanumeric string **and** a product with that `share_slug` exists (one cached query, 10 min, so a revoked share does not leave a dead link). Env: `SITE_DEMO_SHARE_SLUG`.

## 3. Dutch Marketing Pages

- Add `lang/nl.json` with translations for every `__()` string in `welcome.blade.php` and `privacy.blade.php` only. The app panel is untouched. Configuration-backed copy moves into the views as `__()` strings so it translates too: `config('site.description')` is replaced by `__('DipCatch watches the price of …')` in both the meta description and `og:description` (the `description` key is removed from `config/site.php`).
- **Explicit, stateless locale**: a `MarketingLocale` middleware on the `home` and `privacy` routes resolves the locale from `?lang=nl|en` only; any other or absent value gives the **fixed default** from Open Question 1. There is no `Accept-Language` negotiation: the bare URL always renders the same language, so crawlers, caches and humans see one stable default page, and `?lang=` URLs are the two stable variants. Nothing locale-related is written to the session. (The routes still carry Laravel's `web` middleware, so the ordinary session cookie is present as on every page — the privacy statement already describes it; the locale adds no state to it.) A malformed `?lang` value (`?lang=fr`, `?lang=<script>`) is treated as absent and never reflected into the page.
- The middleware sets the locale with `App::setLocale()` and restores the previous locale in a `finally` block, so under Octane a Dutch marketing request cannot leak `nl` into the next request served by the same worker.
- A small language toggle (NL / EN) next to the appearance toggle in the header of both pages; each option links to the current route with `?lang=`. Links between the two marketing pages propagate the current `?lang` value when one is present.
- `<html lang>` and `og:locale` (`nl_NL` / `en_US`) follow the resolved locale. `hreflang` alternates in `<head>` of both pages: `nl` → `?lang=nl`, `en` → `?lang=en`, `x-default` → the bare URL. `<link rel="canonical">`: a `?lang=` URL points at itself; the bare URL points at the `?lang=` variant of the default language.
- The Filament app and emails stay English; `App::setLocale` is scoped to the marketing routes via the middleware, so nothing else changes.

## 4. FAQ Section

Placed between "How it works" and the bottom CTA. Six items, native `<details>` disclosures (same pattern as the Add-a-shop header, `add-shop-header.blade.php`, no JS), each question an `<summary>`. Claims and their sources:

| Question | Answer (EN) | Traced to |
|---|---|---|
| Which shops work? | AH, Jumbo, Dirk, Lidl, SPAR, DekaMarkt, Poiesz, Vomar, bol.com, Amazon.nl and Zooplus have dedicated support (Aldi joins the list only once `AldiAdapter` is merged — see STOP condition 3), including AH bonus and Dirk promo prices. Many other webshops publish product data (schema.org, Open Graph) that DipCatch can read; shops behind bot protection or with prices that only load in JavaScript may not work — you see the result before you confirm. | `config/dipcatch.php` adapters list; `config/site.php` supported hosts; `AhApiSource`, `DirkAdapter` |
| How often are prices checked? | Automatically, about every 6 hours (the interval is `dipcatch.recheck.interval_hours`, default 6, plus up to 30 minutes of jitter); you never trigger a check yourself. | `config/dipcatch.php:57-60` |
| Is it free? | Yes, during the beta. No card, no trial timer. | registration open (`config/fortify.php`), no billing code |
| Do I need an extension or app? | No. Paste a link in the browser; alerts arrive by email digest, in-app bell, or browser push if you enable it. | `NotificationSettings` page; `notification-settings.blade.php` |
| Can I compare different pack sizes? | Yes. When DipCatch can read the pack size, it shows a price per kilo, litre or piece next to that shop, so a 200 g and a 370 g bag compare fairly. | `App\Support\PackSize`, `Shop::unitPrice()` |
| Can I share a comparison? | Yes. Every product has an optional public page with the current prices per shop and, where available, a chart of the last 90 days of the cheapest price; anyone with the link can view it, nothing about your account is shown. | `product-sharing-modal.blade.php`, `PublicProductController` |

Each answer is at most three sentences. The FAQ gets `FAQPage` JSON-LD (`Question`/`Answer` pairs) generated from the same array, so copy lives once. The JSON is emitted through a new `App\Support\JsonLd::script(array $data): HtmlString` helper that wraps `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` in the `<script type="application/ld+json">` element — never plain `json_encode` in the view — so a `</script>` inside an answer cannot end the script element. The helper is the test seam: it is unit-tested with script-ending text; the view test only asserts the six questions are present in the decoded JSON.

## 5. Config and Tests

- `config/site.php`: add `demo_share_slug` (env `SITE_DEMO_SHARE_SLUG`); remove `description` (Phase 3 moves it into `__()` strings).
- The FAQ array is not config: it lives in the view's `@php` block next to `$steps`, so the translatable copy sits in one file.
- `.env.example`: `SITE_DEMO_SHARE_SLUG=`.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `SITE_DEMO_SHARE_SLUG` unset or malformed | No live-example link renders (Phase `hero`, test) |
| Demo product's share revoked (`share_slug` null) | Existence check fails → link hidden within 10 min (cache TTL) (Phase `hero`, test with cache cleared) |
| Bare URL, any `Accept-Language` (including none) | Always the default language (Open Question 1); identical HTML for `nl`, `de`, and absent headers (Phase `nl`, tests) |
| Signed-in user on the homepage | Locale logic still applies; app remains English after clicking "Open app" (Phase `nl`, test asserts `/app` renders English) |
| Screen reader on the phone mock | One `aria-label` sentence; inner text is `aria-hidden` (Phase `hero`, manual check with VoiceOver noted in Findings) |
| `?lang=en` / `?lang=nl` | That language renders regardless of headers (Phase `nl`, tests) |
| Dutch marketing request then a non-marketing request in the same Octane worker | Second request renders English — locale restored in `finally`, also when the first request threw (Phase `nl`, test drives both through the kernel in one process) |
| `?lang=fr` or `?lang=<script>` | Ignored; header/default decides; no error, no reflection of the value into the page (Phase `nl`, test) |
| FAQ JSON-LD, answer text contains `</script>` | Encoded as `\u003C/script\u003E`; the script element does not end early (Phase `faq`, test) |
| H1 wraps to three lines on 390 px | Reduce to `text-4xl` at base (already) and check the `[24ch]` cap; adjust copy rather than shrinking type below `text-4xl` (Phase `hero`, browser check) |

## Implementation

### Phase 1: Hero, mock, live example (Priority: HIGH)

**ID:** hero · **Depends:** none

- [ ] Replace `$h1`/`$sub` with the Section 2.1 copy — keep `max-w-[24ch]` / `max-w-[48ch]`.
- [ ] Re-read the "How it works" intro sentence and the bottom-CTA copy against the new hero so the page tells one story (compare across shops), and adjust wording where it still says "track products". Soften the shipped "+ most webshops that show a price" chip to "+ many other webshops" for the same reason as the FAQ answer (Section 4, "Which shops work?").
- [ ] Rebuild `$tracked` and the mock cards per Section 2.2 — favicon via `Favicon::url()`, unit-price line, `role="img"` + `aria-label` on the container, inner content `aria-hidden`.
- [ ] Add `site.demo_share_slug` config + env example; render the "See a live example" link per Section 2.3 with the cached existence check.
- [ ] Tests — homepage shows the new H1; mock contains the three shops and no `mediamarkt.nl`; live-example link renders only with a valid slug that exists; after the share is revoked the link is still present within the cache window and gone once the cache entry (keyed on the configured slug) is cleared or expired.
- [ ] Browser check at 390 / 768 / 1280 px, light and dark; note results in Findings.

### Phase 2: FAQ (Priority: HIGH)

**ID:** faq · **Depends:** hero — both phases edit `welcome.blade.php`, so they must not run concurrently

- [ ] Add the six-item `$faq` array and the `<details>` section per Section 4, between "How it works" and the bottom CTA.
- [ ] Add `App\Support\JsonLd::script()` and emit the `FAQPage` JSON-LD from the same array through it (Section 4).
- [ ] Read the re-check interval from `config('dipcatch.recheck.interval_hours')` in the "How often" answer so the copy cannot drift.
- [ ] Tests — `JsonLd::script()` unit test: data containing `</script>` yields exactly one `</script>` in the output and decodes back to the input; homepage test: JSON-LD decodes with six `Question` entities whose names match the six visible `<summary>` texts; answers contain no HTML.

### Phase 3: Dutch marketing pages (Priority: MEDIUM)

**ID:** nl · **Depends:** hero, faq

- [ ] `lang/nl.json` covering every `__()` string in `welcome.blade.php` and `privacy.blade.php` (after Phases 1–2 so the strings are final).
- [ ] Move `site.description` into `__()` strings in both meta tags; remove the config key.
- [ ] `MarketingLocale` middleware (`?lang` → fixed default; stateless; restores the previous locale in `finally`), applied to the `home` and `privacy` routes only.
- [ ] Header language toggle on both pages (propagating `?lang` between the two routes); `<html lang>`, `og:locale`, `hreflang` alternates, canonical rule per Section 3.
- [ ] Tests — the Edge Cases table's locale rows; exact `canonical`, `hreflang` and `og:locale` values for the bare URL and both `?lang` variants on both routes; the toggle's two hrefs; `/app` stays English for a signed-in user after visiting the Dutch homepage.
- [ ] Translation coverage tests — (a) **no leaks**: request both routes with `?lang=nl` while `Lang::handleMissingKeysUsing()` records every missed key, and assert the list is empty — this covers every string the two responses render, including partials and components; (b) **no orphans**: a static check that every key in `lang/nl.json` appears as a `__('…')` literal in `welcome.blade.php`, `privacy.blade.php`, or the partials/components they include (single-quoted, single-line literals are the project convention in these views; the test fails loudly on a key it cannot find rather than guessing).

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The public share page renders for a guest with only `share_slug` set** — the live example depends on `PublicProductController` needing nothing else; if it requires auth or extra state, the link design changes.
2. **Every host named in the FAQ and in `config('site.supported_hosts')` has a merged adapter or data source at implementation time** — if `AldiAdapter` (in progress elsewhere) has not landed, drop `aldi.nl` from both rather than promising it.
3. **`__()` in Blade resolves through `lang/nl.json` without a `lang/` publish step** — Laravel 13 reads `lang/*.json` when the directory exists; if the app has `lang_path` customised, Phase 3 changes.

---

## Open Questions

1. **Default language of the bare URL: Dutch or English?** Every supported shop is Dutch, so NL-first is the natural default; English-first keeps the page readable for beta testers who are not Dutch. Since there is no header negotiation, this choice decides what every first-time visitor sees. Phase 3 needs it before the middleware's fallback is set.
2. **Should the app switch to `€ 1,69` style formatting?** `MoneyFormatter` renders `EUR 1.69` everywhere. Dutch users expect `€ 1,69`. This is an app-wide change (Filament tables, emails, share page, mock) and is not part of this spec; decide separately. The mock follows whatever the app does.
3. **Which product is the live example, and in which account?** Blocks Phase 1's done-state. Recommended: create a dedicated demo account, track the Lay's product there with its four shops, enable sharing, and set `SITE_DEMO_SHARE_SLUG` on Laravel Cloud. Alternative: accept shipping Phase 1 with the link hidden.

---

<!-- ## Resolved Questions
1. **{Original question?}** **Decision:** {What was decided.} **Rationale:** {Why.}
-->

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
