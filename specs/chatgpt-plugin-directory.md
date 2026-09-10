# ChatGPT Plugin Directory and Connections Onboarding

<!-- spec:planned-at 9cb5300384b1382189ceeb3c24b91ac622ae0606 2026-09-10 -->

## Overview

DipCatch already hosts a remote MCP server at `POST /mcp` with Passport OAuth. ChatGPT Free cannot paste that URL. A reviewed listing in the ChatGPT Plugins Directory is the path that can reach ChatGPT users. This spec makes the server pass that review, and it replaces the Connections page’s “point an MCP client at this URL” line with Connect buttons and plain copy. Claude gets an official install link that works without a directory listing.

## Assumptions

- **Directories in this spec:** ChatGPT Plugins Directory is the listing target. Connections also gets a Connect to Claude button that prefills Claude’s custom-connector dialog. This spec does **not** submit DipCatch to the Claude Connectors Directory, and it does not ship a Claude Code plugin, a Desktop `.mcpb`, or a self-install script. (Recommended default; the directory-scope question was skipped.)
- **Server transport stays remote HTTP + OAuth.** No local stdio wrapper and no `claude_desktop_config.json` installer.
- **ChatGPT Install button stays hidden until `DIPCATCH_CHATGPT_PLUGIN_URL` is a valid ChatGPT https URL.** The listing URL does not exist until OpenAI publishes the plugin. The page still explains ChatGPT in copy.
- **The copyable `/mcp` URL stays on the page**, under an “Other assistants” heading, for clients that are not Claude or ChatGPT.
- **Privacy gains an assistants paragraph.** OpenAI’s plugin review asks for a privacy policy that matches what the tools expose. The Connections page is not enough.
- **Tool annotations use Laravel MCP attributes** `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsOpenWorld]` from `Laravel\Mcp\Server\Tools\Annotations\` (`laravel/mcp` v0.9.5). Values follow the table in §2. Set all three on every tool; an empty `annotations` object leaves OpenAI’s defaults. `#[IsIdempotent]` is out of scope.
- **Human titles use `#[Title]`** from `Laravel\Mcp\Server\Attributes\Title`. Without it, `title()` becomes `Str::headline` of the class name (`List Products Tool`). OpenAI’s scan shows titles to reviewers.
- **`recheck` is not read-only.** It dispatches `CheckShopPrice` and writes new `PriceCheck` rows.
- **`create_product` and `add_shop` are open-world.** Both probe a shop URL on the public internet, including the unconfirmed preview call.
- **`set_threshold` is closed-world and destructive.** It overwrites stored threshold fields (`SetThresholdTool.php:49-65`). OpenAI treats overwrite as `destructiveHint: true`. `remove_shop` and `delete_product` stay destructive; they only touch this user’s records.
- **Challenge token and ChatGPT listing URL live on `config/dipcatch.php`**, not `config/mcp.php`. `laravel/mcp` already owns the `mcp` config key (`redirect_domains`, OAuth issuer). A new app `config/mcp.php` that only has listing keys is a footgun on `vendor:publish`. Env names follow the existing `DIPCATCH_` prefix: `DIPCATCH_CHATGPT_PLUGIN_URL`, `DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN`. Never commit a real token.
- **An unset challenge token makes `GET /.well-known/openai-apps-challenge` return 404.**
- **App-panel copy stays English** via `__()` with no `lang/nl.json` entries. Privacy strings are marketing: they need Dutch keys or `MarketingTranslationsTest` fails.
- **Connect buttons use `flux:button` with `:href` and `target="_blank"`**, same pattern as `resources/views/livewire/products/product-show.blade.php:154`.
- **Claude install URL is built from `url('/mcp')`**, not a hard-coded `dipcatch.eu`, so local and staging work.
- **In-app copy strings below are the ones to ship**, not placeholders. Tone matches the privacy page: short, plain. Primary Claude and ChatGPT cards do not say “MCP”. The Other assistants card may.
- **The ChatGPT button renders only for an `https` URL whose host is `chatgpt.com` or a subdomain of it.** A missing, empty, `http`, or other-host value uses the waiting copy. This stops a bad env value becoming a phishing `href`.
- **Portal submission (OpenAI form, demo account, reviewer prompts) is a MEDIUM phase.** Code phases make the app review-ready. A human still submits the listing and then sets `DIPCATCH_CHATGPT_PLUGIN_URL`.
- **`mcp.redirect_domains` stays `*`.** Connections supports Claude, ChatGPT, and other MCP clients via DCR. Do not allowlist ChatGPT-only redirect hosts in this spec; that would break the Claude button and the Other assistants path.

---

## 1. Current State

Connections (`resources/views/livewire/connections/connections-page.blade.php:1-8`) shows a copyable `url('/mcp')` and the line “Point an MCP client at this URL and authorise it with your account.” `ConnectionsPage::endpoint()` is `url('/mcp')` (`app/Livewire/Connections/ConnectionsPage.php:50-53`). Tests only assert that `/mcp` is visible (`tests/Feature/Mcp/ConnectionsPageTest.php:64-67`).

Nine tools on `DipCatchServer` (`app/Mcp/Servers/DipCatchServer.php:51-61`) have `#[Name]` and `#[Description]` only. None set read-only, destructive, or open-world hints. `tools/list` is already exercised with a `mcp:use` token (`tests/Feature/Mcp/McpServerTest.php:55-59`).

OAuth discovery already exists at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`. There is no `/.well-known/openai-apps-challenge`. Privacy (`resources/views/privacy.blade.php:52-68`) does not mention assistants. Public URLs already exist: `/privacy`, `/terms-of-service`, `/support` (`routes/web.php:54-56`). Production origin defaults to `https://dipcatch.eu` (`config/site.php:65`).

The eye-verify harness (`.github/eye-verify/connections.mjs:27`) looks for `/Nothing is connected/i`. The page says `Nothing connected yet.` That check is already wrong; this work must update it because the page copy changes.

## 2. Tool Annotations

Add attributes on each tool class. Marker form `#[IsReadOnly]` equals `true`. Use `#[IsOpenWorld(false)]` and `#[IsDestructive(false)]` wherever the MCP default would be wrong (open-world defaults to true; destructive defaults to true when the tool is not read-only).

| Tool | `IsReadOnly` | `IsDestructive` | `IsOpenWorld` | Why |
|------|--------------|-----------------|---------------|-----|
| `list_products` | true | false | false | Lists this user’s products. |
| `get_product` | true | false | false | Reads one owned product. |
| `price_history` | true | false | false | Reads stored history. |
| `create_product` | false | false | true | Probes a shop URL; confirm writes a product. |
| `add_shop` | false | false | true | Same probe + write on confirm. |
| `recheck` | false | false | true | Fetches shop pages and stores `PriceCheck` rows. |
| `set_threshold` | false | true | false | Overwrites stored threshold fields on this user’s product. |
| `remove_shop` | false | true | false | Deletes a shop. Cannot undo. |
| `delete_product` | false | true | false | Deletes a product and its history. Cannot undo. |

Keep existing `#[Name]` snake_case names. Keep existing `#[Description]` text. Add `#[Title(...)]` with these strings: `List products`, `Get product`, `Price history`, `Create product`, `Add shop`, `Recheck prices`, `Set threshold`, `Remove shop`, `Delete product`.

`Tool::toArray()` in `laravel/mcp` v0.9.5 already puts `annotations` on `tools/list` (`readOnlyHint`, `destructiveHint`, `openWorldHint`). Assert that JSON-RPC payload (same `Passport::actingAs(..., ['mcp:use'])` path as `McpServerTest.php:55-59`), not only by reflecting attributes. Also assert each tool’s `title`.

## 3. OpenAI Domain Challenge

OpenAI’s plugin portal asks for a plain-text token at:

```text
GET /.well-known/openai-apps-challenge
```

Append to `config/dipcatch.php` (do not add `config/mcp.php`):

```php
'chatgpt_plugin_url' => env('DIPCATCH_CHATGPT_PLUGIN_URL'),
'openai_apps_challenge' => env('DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN'),
```

Document both keys in `.env.example`. Do not put a real token in the repo. Connections and the challenge controller read `config('dipcatch.chatgpt_plugin_url')` and `config('dipcatch.openai_apps_challenge')`.

A small invokable controller (for example `App\Http\Controllers\OpenaiAppsChallengeController`) returns:

- **200** `text/plain; charset=UTF-8` with the exact token and no extra whitespace, when the env value is a non-empty string.
- **404** when the value is missing or empty.

Register the GET route on the public web stack (`routes/web.php`), not behind `auth:api`. Do not wrap the body in JSON.

## 4. Connections Copy and Buttons

Rewrite the MCP card in `resources/views/livewire/connections/connections-page.blade.php`. Keep the connected-applications list and revoke behaviour unchanged (`ConnectionsPage.php:55-68`).

**Page intro (replace the current subtitle):**

- Heading stays `Connections`.
- Subtitle: `Connect Claude or ChatGPT to this account, or disconnect an app you already allowed.`

**Claude card (always visible):**

- Heading: `Claude`
- Body: `Opens Claude with DipCatch filled in. Review the URL, add the connector, then allow access.`
- Button: `Connect Claude`
- `href` from `ConnectionsPage::claudeInstallUrl()`:

```text
https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=DipCatch&connectorUrl={urlencoded endpoint}
```

`endpoint` is still `url('/mcp')`. `target="_blank"`. Button variant `primary`.

**ChatGPT card (always visible):**

- Heading: `ChatGPT`
- When `chatgptPluginUrl()` returns a string (see rules below):
  - Body: `Opens the DipCatch plugin in ChatGPT. Connect it there, then allow access.`
  - Button: `Install in ChatGPT` → that URL, `target="_blank"`. Variant is not `primary` (Claude already is).
- Otherwise:
  - No ChatGPT button.
  - Body: `ChatGPT needs DipCatch in its plugin directory. That listing is not live yet. Use Claude or this website until it is.`

`chatgptPluginUrl()` returns the configured string only when it is a valid `https` URL whose host is `chatgpt.com` or ends with `.chatgpt.com`. Otherwise it returns `null` (empty, `http`, other hosts, or unparsable).

**Other assistants card:**

- Heading: `Other assistants`
- Body: `Other MCP clients can use this URL and then sign in with this account.`
- Existing readonly copyable `flux:input` of `$endpoint`.

Build `claudeInstallUrl()` on `ConnectionsPage` (or a tiny dedicated class if the Livewire file would otherwise grow helpers it does not own). Percent-encode the endpoint with `rawurlencode`.

Do not mention Developer Mode, custom GPT Actions, or editing JSON on disk.

## 5. Privacy

In `resources/views/privacy.blade.php`, add one list item under **Who else sees it** (`privacy.blade.php:52-68`):

- Label: `An assistant you connect`
- Text: `If you connect Claude, ChatGPT, or another assistant on Connections, DipCatch sends that assistant the product data and tool results it asks for, and the assistant can change the products you track. Disconnect it on Connections to stop new access. That does not delete chats or other copies the assistant’s provider already stored. DipCatch does not send your password to the assistant.`

Add the English keys and Dutch translations to `lang/nl.json`. Bump `config/site.php` `privacy_updated_at` to the ship date (ISO `YYYY-MM-DD`). `MarketingTranslationsTest` will fail if a new `__()` key is missing from `lang/nl.json`.

## 6. ChatGPT Plugin Listing (human + env)

After the HIGH phases are on production, a human submits the plugin. OpenAI reviews it. The developer then publishes it. Only after publish is there a listing URL to put in `DIPCATCH_CHATGPT_PLUGIN_URL`.

1. Put `DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN` on Laravel Cloud (the value OpenAI’s portal shows).
2. Confirm production `GET /.well-known/openai-apps-challenge` returns that token, and that the OAuth discovery documents still match §7.
3. Submit an MCP-backed plugin (universal URL `https://dipcatch.eu/mcp`, OAuth). Do not publish a replacement `config/mcp.php`; package defaults already allow any OAuth redirect domain (`redirect_domains` = `*`).
4. When OpenAI approves, publish from the portal. Then copy the listing URL into production `DIPCATCH_CHATGPT_PLUGIN_URL`.

### Listing fields

| Field | Value |
|-------|--------|
| Name | `DipCatch` |
| Short description | `Track shop prices and get told when they drop.` |
| Long description | `DipCatch watches the products you buy more than once across Dutch supermarkets and webshops. Connect it so ChatGPT can list what you track, add a shop URL, set a drop alert, and recheck a stale price. Writes need your DipCatch account via OAuth.` |
| Logo | `public/images/dipcatch-logo.png` |
| Category | Shopping (or the portal’s closest equivalent) |
| Website | `https://dipcatch.eu` |
| Support | `https://dipcatch.eu/support` |
| Privacy | `https://dipcatch.eu/privacy` |
| Terms | `https://dipcatch.eu/terms-of-service` |
| MCP URL | `https://dipcatch.eu/mcp` (universal) |
| Auth | OAuth |
| Publisher | The verified OpenAI Platform identity for this product |
| Countries | The countries DipCatch already serves; do not invent extra markets |
| CSP | None. This plugin has no MCP App UI. If the portal requires a CSP anyway, submit the minimum it accepts and STOP if it demands UI origins DipCatch does not host. |
| Release notes | `Initial DipCatch plugin: list products, add shops, set drop alerts, recheck prices.` |

### Review pack

Starter prompts (paste into the portal as-is; replace the shop URL with the demo account’s stored URL):

- `Show me everything I track and which shop is cheapest.`
- `What is on the first product in my list?`
- `Add this shop URL as a new product, and stop before you confirm if the price looks wrong: {demo shop URL}`
- `Set a 10 percent drop alert on that product.`
- `This price looks stale. Recheck it.`

Demo account: no MFA. At least two products, two shops on one of them, a threshold set, and a short price history. Put the real demo email, password, and shop URL into the portal fixture fields. Do not leave `{product}` or `{url}` in submitted cases.

| Kind | Prompt / scenario | Expected behaviour | Result shape | Why it must not complete | Fixtures |
|------|-------------------|--------------------|--------------|--------------------------|----------|
| Positive | `Show me everything I track and which shop is cheapest.` | `list_products` | Structured list of this user’s products with cheapest shop and price | — | Two products |
| Positive | `What is on {title of the first demo product}?` | `get_product` | One product with shops | — | That product’s id |
| Positive | `Add this shop URL as a new product, and stop before you confirm if the price looks wrong: {demo shop URL}` | `create_product` without `confirm` | Preview title, price, shop; `draft` token; nothing stored | — | Demo account shop URL |
| Positive | After that preview, user says `Yes, add it.` | `create_product` with `draft` and `confirm: true` | Stored product summary | — | Draft from the previous call |
| Positive | `Set a 10 percent drop alert on {title of the first demo product}.` | `set_threshold` with percent 10 | Product summary with that percent | — | Existing product |
| Negative | `Stop tracking {title}.` with no `Yes, delete it` in the chat | Model must ask; `delete_product` must not run on that turn | No deletion | Irreversible delete needs an explicit confirm | Existing product still present |
| Negative | `create_product` with `confirm: true` and no `draft` | Tool error | Error, nothing stored | Confirm without a preview can store the wrong scrape | None |
| Negative | `get_product` with another user’s id | Tool error `No such product.` | Error, no leak of the other account | Must not reveal another account’s data | A second account’s product id |

## 7. OAuth discovery ChatGPT needs

`laravel/mcp` already serves the two well-known documents (`McpServerTest.php:24-27` only asserts HTTP 200). OpenAI will not complete OAuth unless those documents match the MCP authorization spec. This spec does **not** add a custom authorization server. It adds tests that fail loudly if the package metadata is short, then STOP.

Assert on `GET /.well-known/oauth-protected-resource`:

- JSON has a `resource` string equal to `url('/mcp')` (the canonical MCP URL). If laravel/mcp instead advertises `url('/')`, STOP and report; do not change the public `/mcp` path to match metadata.
- JSON has `authorization_servers` as a non-empty list.

Assert on `GET /.well-known/oauth-authorization-server`:

- JSON has `authorization_endpoint` and `token_endpoint`.
- `issuer` equals one of the `authorization_servers` values from the protected-resource document.
- `code_challenge_methods_supported` is a list that contains `S256`.
- `token_endpoint_auth_methods_supported` is a non-empty list.
- The document advertises a client-registration path ChatGPT can use: `registration_endpoint` (DCR) and/or `client_id_metadata_document_supported` true (CIMD).

Assert on unauthenticated `POST /mcp`:

- Status 401 (already tested).
- If a `WWW-Authenticate` header is present, it includes `resource_metadata`. If the header is absent, record that in Findings and STOP only when ChatGPT’s connect flow fails for missing discovery (do not invent a custom challenge format).

Do not add Pest coverage for a live ChatGPT token exchange, JWT audience rewriting, or an OpenID UserInfo endpoint. Those are STOP items if the portal or a real ChatGPT connect fails, not new product code.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `DIPCATCH_CHATGPT_PLUGIN_URL` unset or empty | ChatGPT card shows waiting copy; no ChatGPT button. Phase `connections-copy` tests. |
| `DIPCATCH_CHATGPT_PLUGIN_URL` is a valid `https://chatgpt.com/...` (or subdomain) URL | ChatGPT button href is exactly that URL. Phase `connections-copy` tests. |
| `DIPCATCH_CHATGPT_PLUGIN_URL` is `http`, `javascript:`, or a non-chatgpt host | Treat as unset: waiting copy, no button. Phase `connections-copy` tests. |
| `DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN` unset | Challenge GET is 404. Phase `challenge-endpoint` tests. |
| Challenge token set | GET returns 200, `text/plain`, body equals the token with no JSON wrapper and no trailing explanation. Phase `challenge-endpoint` tests. |
| Claude install URL encoding | `connectorUrl` is `rawurlencode(url('/mcp'))`; name is `DipCatch`. Phase `connections-copy` tests. |
| Guest hits Connections | Same `auth` + verified middleware as other `/app` routes (`routes/web.php:152-164`). This spec does not change that group. FluxShellTest already covers guest redirect on the app prefix via the dashboard. |
| Guest hits challenge URL | Public 200 or 404 as above; no login. Phase `challenge-endpoint` tests. |
| Revoke still works | Disconnect list unchanged. Existing revoke tests stay green. |
| `tools/list` without `mcp:use` | Still 401/403. Existing `McpServerTest` stays green. |
| Annotation defaults | Closed-world tools set `#[IsOpenWorld(false)]`; destructive tools set `#[IsDestructive]`; read tools set `#[IsReadOnly]`. Phase `annotations` tests. |
| Dutch privacy | New assistants strings exist in `lang/nl.json`. Phase `privacy-assistants` tests (existing `MarketingTranslationsTest`). |
| Eye-verify empty state | Harness matches the empty-list string actually on the page, and still sees `/mcp` plus `Connect Claude`. Phase `connections-copy`. |
| ChatGPT Free user | Copy never tells them to paste `/mcp` or enable Developer Mode. Phase `connections-copy` tests assert those phrases are absent. |
| OAuth metadata missing `S256` or `resource` | Phase `oauth-discovery` tests fail. Do not write a custom OAuth server. |

## Implementation

### Phase 1: Tool annotations (Priority: HIGH)

**ID:** `annotations` · **Depends:** none

- [x] Add `#[IsReadOnly]`, `#[IsDestructive]`, and `#[IsOpenWorld]` (with explicit `false` where needed) to the nine tool classes per the table in §2 — OpenAI Scan Tools rejects tools that omit these hints.
- [x] Add `#[Title]` on each tool with the strings in §2 — the package default is `List Products Tool`, which is the class headline, not a reviewer title.
- [x] Tests — new file `tests/Feature/Mcp/McpToolAnnotationsTest.php`: `Passport::actingAs` + `POST /mcp` `tools/list` returns the nine names, titles, and hint values in §2. Do not edit `McpServerTest.php` (keeps this phase write-disjoint). Existing unauthenticated and wrong-scope cases stay in `McpServerTest`.

### Phase 2: OpenAI challenge endpoint (Priority: HIGH)

**ID:** `challenge-endpoint` · **Depends:** none

- [x] Append `chatgpt_plugin_url` and `openai_apps_challenge` to `config/dipcatch.php`, and document `DIPCATCH_CHATGPT_PLUGIN_URL` and `DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN` in `.env.example` — do not add `config/mcp.php`.
- [x] Add a public `GET /.well-known/openai-apps-challenge` that returns the exact token as `text/plain` or 404 when unset — OpenAI’s portal checks this URL.
- [x] Tests — new file `tests/Feature/Mcp/OpenaiAppsChallengeTest.php`: unset → 404; set → 200, plain text, body equals the configured token, `Content-Type` starts with `text/plain`; GET is unauthenticated.

### Phase 3: Connections buttons and copy (Priority: HIGH)

**ID:** `connections-copy` · **Depends:** `challenge-endpoint`

- [x] Replace the MCP-only intro with the Claude card, ChatGPT card, and Other assistants card from §4 — primary actions become buttons, not a pasted URL.
- [x] Add `claudeInstallUrl()` (or equivalent) on `ConnectionsPage` using `rawurlencode($this->endpoint())` — Claude’s documented install-link shape.
- [x] Show `Install in ChatGPT` only when `chatgptPluginUrl()` returns a string — empty, `http`, and non-chatgpt hosts stay on waiting copy.
- [x] Update `.github/eye-verify/connections.mjs` to assert `Connect Claude`, `/mcp`, and the real empty-list string — the current `/Nothing is connected/i` check already misses the page.
- [x] Tests — Livewire sees `Connect Claude` and the `claude.ai/customize/connectors` href with encoded `/mcp`; ChatGPT button absent by default, absent for a non-https or non-chatgpt URL, and present with a valid `https://chatgpt.com/...` URL; page does not contain `Developer Mode`; revoke tests still pass; `DashboardAndConnectionsTest` still sees `/mcp` and `Nothing connected yet.` unless that empty string is intentionally changed (if changed, update that test to the new string).

### Phase 4: Privacy assistants (Priority: HIGH)

**ID:** `privacy-assistants` · **Depends:** none

- [x] Add the assistants list item from §5 to `privacy.blade.php` and matching Dutch entries in `lang/nl.json` — review requires the privacy page to describe connected assistants.
- [x] Set `site.privacy_updated_at` to the ship date — the page and sitemap both read this value.
- [x] Tests — English privacy HTML contains the new label in `tests/Feature/PrivacyAssistantsTest.php`; `MarketingTranslationsTest` stays green; existing privacy route tests stay green.

### Phase 5: OAuth discovery assertions (Priority: HIGH)

**ID:** `oauth-discovery` · **Depends:** none

- [x] Add `tests/Feature/Mcp/McpOauthDiscoveryTest.php` with the JSON assertions in §7 — OpenAI will not finish OAuth if `S256` or `resource` is missing.
- [x] Do not change Passport, laravel/mcp config, or `routes/ai.php` in this phase — if a field is missing, STOP.
- [x] Tests — the assertions in §7; existing `McpServerTest` 401/403 cases stay green and unedited.

### Phase 6: Submit the ChatGPT plugin (Priority: MEDIUM)

**ID:** `plugin-directory-submit` · **Depends:** `annotations`, `challenge-endpoint`, `connections-copy`, `privacy-assistants`, `oauth-discovery`

- [ ] Set `DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN` on production to the portal’s token — domain verification fails without it.
- [ ] Confirm the OpenAI Platform org has Apps Management write access, a verified publisher identity, and is **not** an EU-residency project — OpenAI currently rejects MCP submissions from EU-residency projects.
- [ ] Complete one ChatGPT Developer Mode OAuth connect against production — OpenAI expects a working connect before review, not only well-known JSON.
- [ ] Run Scan Tools in the portal and read the discovered tools, annotations, and validation errors before submit.
- [ ] Submit using the listing fields and review pack in §6 — this is the ChatGPT onboarding path.
- [ ] After OpenAI approval, publish from the portal, then set production `DIPCATCH_CHATGPT_PLUGIN_URL` to the listing URL — submit is not publish.
- [ ] Tests — none new in Pest; the ChatGPT-button cases live in phase `connections-copy`. Before submit, `curl` production `/.well-known/openai-apps-challenge` and confirm the body equals the portal token.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`laravel/mcp` v0.9.5 does not put `readOnlyHint` / `destructiveHint` / `openWorldHint` on `tools/list` from the Annotation attributes** — OpenAI Scan Tools will fail; do not fake the hints in a custom middleware. (v0.9.5 `Tool::toArray()` does include `annotations`; stop only if a later bump drops that.)
2. **Registering `GET /.well-known/openai-apps-challenge` collides with laravel/mcp’s well-known OAuth routes** — do not break OAuth discovery to add the challenge.
3. **OpenAI requires a different challenge path or body than plain text at `/.well-known/openai-apps-challenge`** — match the live portal, do not keep a wrong path.
4. **`#[Title]` is missing from this package version** — do not wrap `tools/list`; report and keep `#[Name]` + `#[Description]` only.
5. **ChatGPT OAuth fails because redirect URIs are rejected** — do not add a partial `config/mcp.php` that overwrites `redirect_domains`; check package DCR and `mcp.redirect_domains` first.
6. **OAuth discovery JSON lacks `resource`, `S256`, or a DCR/CIMD registration path** — do not write a custom authorization server; report the missing fields from laravel/mcp.
7. **A real ChatGPT connect fails on token audience, `resource` echo, or refresh tokens** — report the failing step; do not add JWT audience rewriting unless that is the traced cause.
8. **OpenAI rejects the plugin because shop-page fetches violate its third-party scraping rules** — report the rejection; do not remove `create_product`, `add_shop`, or `recheck` to get listed. Those tools are the product.
9. **The OpenAI Platform project uses EU data residency** — OpenAI currently rejects MCP plugin submissions from those projects; do not submit until the org is on a supported residency.
10. **Protected-resource `resource` is not `url('/mcp')`** — report the actual value; do not move the MCP route.

---

## Open Questions

None.

---

## Resolved Questions

1. **Which assistant directories does this spec cover?** **Decision:** ChatGPT Plugins Directory plus an in-app Connect to Claude button. No Claude Connectors Directory submission, no Claude Code plugin, no Desktop `.mcpb`, no install script. **Rationale:** The user asked to build a plugin for the plugin directory after the ChatGPT Free constraint. Claude already has a documented install link that needs no review. The directory-scope question was skipped, so this is the recommended default.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **STOP 6 / 10, then overlay:** `laravel/mcp` v0.9.5 (and `main`) still advertise root `resource` = `url('/')` and omit `token_endpoint_auth_methods_supported`. No 0.9.x bump exists. Did not write a custom authorization server, did not add `config/mcp.php`, and did not move `POST /mcp`. `routes/ai.php` now registers the two root well-known routes first so the package `hasGetRoute` hook skips its copies. `McpOauthDiscoveryController` sets `resource` to `url('/mcp')` and `token_endpoint_auth_methods_supported` to `['none']` (DCR creates public clients with `token_endpoint_auth_method: none`). Nested `/mcp` metadata and `POST /oauth/register` stay the package's.
- Challenge 404 returns an empty `text/plain` body instead of `abort(404)`, so the well-known URL does not render the HTML error page.
- **Phase 6** is not done in this clone: no production Laravel Cloud env and no OpenAI Platform org access.
- **Package check (2026-09-10):** `laravel/mcp` latest stable is **v0.9.5** (already installed). `v1.0.0-beta.1` exists but is outside `^0.8||^0.9.5` and is a protocol break.
- **Production curl (https://dipcatch.eu, this code not deployed):** root protected-resource `resource` is `https://dipcatch.eu`; nested `/mcp` document `resource` is `https://dipcatch.eu/mcp`; authorization-server JSON has `S256` and `registration_endpoint`, not `token_endpoint_auth_methods_supported`; `GET /.well-known/openai-apps-challenge` is **404**.
