# Shopping List

<!-- spec:planned-at 0f5bbf60937725489a859e4b410b9c96599b6977 2026-09-28 -->

## Overview

A user puts products on one shopping list, across all shops. The full list groups each product under the shop where it is the best buy now, the same shop the product card and the dashboard's "Where to shop this week" lead with. The user crosses items off in the shop, clears the crossed-off items afterwards, and can print the list. A list icon next to the notification bell shows what is on the list.

## Assumptions

- **Scope:** only the signed-in account's own products can go on its list. A product the user does not track cannot go on the list. The list is not a free-text list.
- **One list per account.** There are no named lists, no sharing and no second list. The user confirmed "one list across all shops" (2026-09-27).
- **Storage:** two nullable timestamp columns on `products` (`listed_at`, `list_checked_at`), not a new table. One list per account and one product row per user make a table add nothing. A product delete removes its list entry with it. A table becomes the right shape only when named or shared lists arrive (see Open Questions).
- **Grouping:** by best-buy shop host, from the same rule `DashboardDigest` uses (`bestBuyShop()`: `HeadlinePrice::of($product)->shop`, only when `eligibleShops()` contains it). The user chose grouping on 2026-09-28.
- **Crossing off:** a crossed-off item stays on the list, struck through, until the user taps "Clear crossed off". That action removes every crossed-off item from the list. The user chose this on 2026-09-28.
- **Paused products** stay on the list and group like any other product. Pausing stops checks and alerts. It does not say the user stopped buying the product.
- **No quantity and no note per item** in this version.
- **All plans:** the list is free for every account. It adds no fetches, no AI and no cost, and the product cap already bounds its size.
- **The header dropdown only shows the list.** Crossing off happens on the full list page, not in the dropdown.
- **Print** prints the items not crossed off, grouped by shop, through the browser's own print dialog (`window.print()`). There is no PDF.
- **Copy** is English source strings, like the rest of the app. `lang/nl.json` carries only marketing-page keys (a test enforces it), so the app's Dutch copy is out of scope.

---

## 1. Data Model

Migration `YYYY_MM_DD_HHMMSS_add_shopping_list_to_products_table.php`:

```php
if (! Schema::hasColumn('products', 'listed_at')) {
    Schema::table('products', fn (Blueprint $table) => $table->timestamp('listed_at')->nullable());
}

if (! Schema::hasColumn('products', 'list_checked_at')) {
    Schema::table('products', fn (Blueprint $table) => $table->timestamp('list_checked_at')->nullable());
}
```

- `listed_at`: when the product went on the list. Null means the product is not on the list.
- `list_checked_at`: when the user crossed the item off. It is only meaningful while `listed_at` is set.
- The migration appends the columns and needs no index: the list is at most the product cap (250) per user, and queries already filter on `products.user_id`.

`Product` (`app/Models/Product.php`):

- Cast both columns to `datetime` in `casts()`, like `last_notified_at` and the other timestamps there.
- `#[Scope] onShoppingList()`: `whereNotNull('listed_at')`.
- `isOnShoppingList(): bool`.

List writes must not touch `products.updated_at`. `DashboardDigest::tripProducts()` orders by `latest('updated_at')`, so a list change would reorder the dashboard. An Eloquent query `update()` still sets `updated_at` (`Builder::addUpdatedAtColumn()`), so every list write runs inside `Product::withoutTimestamps(...)`, as one conditional update. Phase 1 puts the four writes on `Product`, so the page and the entry points share them:

- `addToShoppingList()`: `whereKey($this->id)->update(['listed_at' => now(), 'list_checked_at' => null])`
- `setListChecked(bool $checked)`: `whereKey($this->id)->onShoppingList()->update(['list_checked_at' => $checked ? now() : null])`
- `removeFromShoppingList()`: `whereKey($this->id)->update(['listed_at' => null, 'list_checked_at' => null])`
- `static clearCheckedFor(User $user): int`: `where('user_id', $user->id)->onShoppingList()->whereNotNull('list_checked_at')->update(['listed_at' => null, 'list_checked_at' => null])`

Each one refreshes `$this` afterwards (the static one excepted), so a caller that renders from the same model sees the new state. `togglePaused()` does the same with `$this->product->refresh()`.

The `onShoppingList()` condition on cross-off keeps a product another tab already removed off the list. Crossing it off must not bring it back.

`ProductFactory`: a `listed()` state and a `listedAndChecked()` state.

## 2. Best-buy grouping

`DashboardDigest::bestBuyShop()` is private (origin/main `app/Support/DashboardDigest.php:121`). Make the rule public in one place so the list and the dashboard cannot drift apart:

- Move it to `HeadlinePrice` as `public function buyableShop(): ?Shop`. It returns `$this->shop` when the eligible shops contain it, and null otherwise. `DashboardDigest::bestBuyShop()` calls it.

`App\Support\ShoppingList` (new, `final readonly`), built by `ShoppingList::forUser(User $user)`:

- It loads the user's products `onShoppingList()` with `cheapestShop` and `shops` eager-loaded: three queries, whatever the list's size. `HeadlinePrice`, `eligibleShops()` and `comparablePacks()` then work on the loaded `shops` and query nothing more.
- `groups`: a `list<array{host: string, shop: ?Shop, items: list<Product>}>`. `shop` is null only for the "No shop sells this now" group.
  - Each product goes under `HeadlinePrice::of($product)->buyableShop()->host`.
  - A product with no buyable shop goes into one last group, "No shop sells this now", with `host = ''`.
  - Group order: the "No shop sells this now" group is always last, whatever its size. The shop groups come before it: most open (not crossed-off) items first, then host A to Z. The dashboard trips also break a tie on their on-offer count; the list has no on-offer count, so it breaks ties on the host alone.
  - Item order inside a group: open items first, then crossed-off ones. Within each part, the order is `listed_at`, oldest first.
- `openCount`: the number of listed products that are not crossed off.
- `checkedCount`: the number of crossed-off products.

Each item shows the product title, its thumb, and the headline price at the best-buy shop (`HeadlinePrice`, as on the card). An item in the "No shop sells this now" group shows no price: `HeadlinePrice` falls back to the last cheapest shop and its last price, and that price is not one anyone can pay now. When a deal is running at that shop, it also shows the deal label (`PromotionLabel::runningDeal($shop)`).

## 3. Full list page

- Route: `Route::livewire('shopping-list', ShoppingListPage::class)->name('shopping-list')` inside the `app.` group in `routes/web.php`, so the name is `app.shopping-list` and the URL is `/app/shopping-list`.
- Component: `App\Livewire\ShoppingList\ShoppingListPage`, view `livewire.shopping-list.shopping-list-page`, title "Shopping list".
- Header: the heading "Shopping list", the line "{open} to buy", and two actions:
  - **Print**: `flux:button` with a printer icon and `x-on:click="window.print()"`.
  - **Clear crossed off ({count})**: shown only when `checkedCount > 0`. It confirms first with a Flux modal: "Remove {count} crossed-off items from the list?"
- Each group is a `flux:card`. The group heading shows the shop favicon and host (`{!! \App\Support\Favicon::html($host) !!}`, as `shop-link.blade.php` does; the method returns escaped HTML as a string, so `{{ }}` would print the markup) and "{n} to buy". The "No shop sells this now" group has no favicon.
- Each item row has:
  - a checkbox that crosses the item off (`toggleChecked(string $productId)`), labelled with the product title for screen readers. A crossed-off row shows a struck-through title in muted text;
  - the title, which links to `app.products.show`;
  - the headline price and, when one is running, the deal label;
  - a remove button with an `x-mark` icon (`remove(string $productId)`) and the accessible name "Remove {title} from shopping list".
- Empty state: "Your shopping list is empty." and "Add products from their page with Add to shopping list." with a link to `app.products.index`.
- Every action first checks `Str::isUuid($id)` and answers 404 when it is not a UUID. On Postgres, comparing a `uuid` column with a string that is not a UUID raises a query error, which would be a 500. Then it finds the product with `auth()->user()->products()->findOrFail($id)`. Another user's product id gives a 404 (Livewire 4.4.6 turns `ModelNotFoundException` into a 404).
- After each change, the component dispatches `shopping-list-changed` so the header icon refreshes (see section 4).

### Print

Tailwind `print:` variants. The repo has none yet, so this is new ground. In print:

- Hide the app's top header (the `<header>` in `resources/views/layouts/app/sidebar.blade.php`), the page actions, the remove buttons and the crossed-off items: `print:hidden`. The layout change is one class on the `<header>`.
- Show each group heading with its items as a plain list, each with an empty square to tick by hand. The site's checkbox control does not print well.
- Use black on white, no card shadows or borders (`print:shadow-none print:border-0`), and keep a group heading on the same page as its first item (`print:break-after-avoid` on the heading). A group longer than a page continues on the next page. A group whose items are all crossed off is hidden in print, heading included.
- Print the date at the top: "Shopping list, {date}".

## 4. Entry points

### Product page

In `resources/views/livewire/products/product-show.blade.php`, next to the Edit button (origin/main :15-17):

- Not on the list: `flux:button size="sm" variant="ghost" icon="list-bullet"`, "Add to shopping list".
- On the list: `flux:button size="sm" variant="ghost" icon="check"`, "On shopping list". Clicking it removes the product from the list, and the tooltip says "Remove from shopping list".
- The action is `ProductShow::toggleShoppingList()`. It authorizes `update` (the same as `togglePaused()`), calls `addToShoppingList()` or `removeFromShoppingList()`, which refresh `$this->product`, and dispatches `shopping-list-changed`. The button label changes in the same response.
- Adding a product sets `listed_at = now()` and `list_checked_at = null`. Adding a crossed-off product again un-crosses it.

### Product list cards

In `resources/views/components/product-card/index.blade.php`, a small "On list" pill at the top-left of the thumb, in the style of the "Paused" label (:24-29). It uses the `list-bullet` micro icon and has `sr-only` text "On your shopping list". A product that is both paused and listed shows both pills, one on each side.

### Header icon and dropdown

- New partial `resources/views/partials/shopping-list-menu.blade.php`, included right before the bell in `resources/views/layouts/app/sidebar.blade.php:127`. That cluster also shows on mobile, so the icon needs no entry in the mobile menu.
- New component `App\Livewire\ShoppingList\HeaderMenu`, modelled on `App\Livewire\Notifications\Bell`:
  - The trigger is `flux:button variant="ghost" size="sm" icon="list-bullet"` with `sr-only` "Shopping list". It has a zinc badge with `openCount` when that is above 0, and "9+" above 9. The icon is not a cart.
  - The panel is a `flux:menu class="w-80"` with the heading "Shopping list" and up to 8 open items. Each item shows the title, the best-buy host and the price, and links to the product. An item with no buyable shop shows "No shop sells this now" and no price, by the same rule as the list page.
  - A line "and {n} more" follows when there are more open items.
  - After a separator comes a button "Open shopping list" that goes to `app.shopping-list`.
  - Empty state: "Nothing on your list yet." when nothing is listed. When every listed item is crossed off: "Everything is crossed off." with the same "Open shopping list" button, so the user can clear them.
  - It refreshes on `#[On('shopping-list-changed')]` for changes in the same tab, and polls with `wire:poll.60s`, as the bell polls, for changes the event cannot reach: another tab, and price checks that move a best buy or a price.
- The component renders on every app page, as the bell does, so it must stay bounded. It does not call `ShoppingList::forUser()`. It runs one aggregate query for the badge and the empty states, `count(*)` and `count(*) filter (where list_checked_at is null)` over the listed products, so it can tell an empty list from an all-crossed-off one. It also loads the first 8 open items by `listed_at` with `shops` and `cheapestShop` eager-loaded: four queries whatever the list's size. The best-buy host and price of each come from `HeadlinePrice::of($product)->buyableShop()`, as on the list page. The poll repeats those four queries once a minute per open app page.

## 5. Out of scope

- An MCP tool ("put these on my shopping list"). See Open Questions.
- Sharing a list, named lists, quantities and notes.
- A "shopping list" filter on the product list.
- Showing the list on the public share page (`/p/{slug}`) or in the daily digest.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Product has no buyable shop: all sold out, inactive, dead or unpriced | Goes into the last group, "No shop sells this now", with no price. Phase 1 grouping test. |
| Best-buy shop changes between visits | The group follows the current best buy. Nothing about the shop is stored on the list entry. Phase 1 test that moves the cheapest price. |
| Product deleted while on the list | The list entry is a column on the row, so it goes with the row. No orphan. Phase 1 test. |
| Product paused while on the list | Stays on the list, grouped as usual. Phase 1 test. |
| Another user's product id sent to an action | `findOrFail` on the user's own products gives a 404, and nothing changes. Phase 2 and 3 tests. |
| A string that is not a UUID sent to an action | The `Str::isUuid` guard gives a 404 before any query, not a Postgres error. Phase 2 test. |
| Cross off in one tab a product another tab already removed | The update carries `onShoppingList()`, so it changes nothing, and the product stays off the list. Phase 2 test. |
| Adding a product that is already listed and crossed off | Un-crosses it and keeps it on the list. Phase 3 test. |
| "Clear crossed off" with nothing crossed off | The button is not shown. The action is a no-op if it is called anyway. Phase 2 test. |
| Two tabs: one crosses off, the other clears | Each action writes a single conditional update, so the clear removes only items crossed off at that moment. Phase 2 test. |
| List change reorders the dashboard | Writes run inside `Product::withoutTimestamps()`. Phase 1 test that each of the four writes leaves `updated_at` unchanged. |
| Very long title or host | `truncate` in the dropdown and the rows. Print wraps the title instead of truncating it. Eye-verify with `StressSeeder`. |
| Printing with crossed-off items | The crossed-off items are hidden in print. Eye-verify with print emulation. |
| 250 products on the list | The list page runs three queries for the list. The header runs four whatever the size, and shows 8 items plus "and {n} more". Phase 1 and phase 3 query-count tests. |
| Mixed currencies | Each item shows its own headline price in its own currency. There is no total, so there is nothing to add up. |

## Implementation

### Phase 1: Data and grouping (Priority: HIGH)

**ID:** data · **Depends:** none

- [x] Migration adding `products.listed_at` and `products.list_checked_at`, both nullable, each statement guarded — section 1
- [x] `Product` casts, the `onShoppingList()` scope, `isOnShoppingList()` and the four list writes; factory states `listed()` and `listedAndChecked()` — section 1
- [x] Move the buyable-shop rule to `HeadlinePrice::buyableShop()` and call it from `DashboardDigest::bestBuyShop()` — one rule for the list and the dashboard
- [x] `App\Support\ShoppingList::forUser()` with `groups`, `openCount` and `checkedCount` — section 2
- [x] Tests — grouping by best-buy host; the "No shop sells this now" group, last even when it is the largest and on a count tie; group and item order; the best buy moving between shops; paused products stay; a deleted product leaves nothing on the list; each of the four writes leaves `updated_at` unchanged and refreshes the model; cross-off on a removed product changes nothing; clear removes only crossed-off items of this user; fixed query count with many items; `DashboardDigestTest` still passes

### Phase 2: Full list page and print (Priority: HIGH)

**ID:** list-page · **Depends:** data

- [x] Route `app.shopping-list` and the `ShoppingListPage` component and view — section 3
- [x] `toggleChecked`, `remove` and `clearChecked` (confirmation modal), each scoped to the user's products and each dispatching `shopping-list-changed` — section 3
- [x] Print styles and the Print button — section 3, Print
- [x] Tests — the page renders the groups and the empty state; guests are redirected; cross off and un-cross; remove; clear removes only crossed-off items; another user's product id gives a 404 and changes nothing; a non-UUID id gives a 404; crossing off a removed product leaves it off the list; the remove button carries its accessible name; a no-shop item shows no price; the event is dispatched
- [x] Eye-verify the print layout — Chrome print emulation with crossed-off items and a long list: header hidden, crossed-off items hidden, a fully crossed-off group hidden. Then print to PDF with a group placed near a page break, and check that its heading is on the same page as its first item: emulation shows the print CSS, not where pages break

### Phase 3: Entry points (Priority: HIGH)

**ID:** entry-points · **Depends:** data, list-page

- [x] `ProductShow::toggleShoppingList()` and the button next to Edit — section 4, Product page
- [x] The "On list" pill on product cards — section 4, Product list cards
- [x] `HeaderMenu` component and partial next to the bell, refreshing on `shopping-list-changed` — section 4, Header icon
- [x] Tests — add, remove and re-add (un-crosses) from the product page, with the button label changing in the same response; update is authorized for the owner only; the card shows the pill only for listed products; the header badge count, "9+", the empty state, the all-crossed-off state and the "Open shopping list" link; a no-buyable-shop item shows no price in the dropdown; the poll attribute is present; the header refreshes on the event; the header's query count stays the same with 8 and with 250 listed products
- [x] Eye-verify — add from the product page, see the badge change, open the dropdown, open the list, cross off, clear, long titles with `StressSeeder`, dark mode, mobile width

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`HeadlinePrice` can expose the buyable shop without changing what the card or dashboard show** — if moving the rule changes any existing `DashboardDigestTest` or `ProductListTest` result, stop. The list must follow the dashboard, not redefine it.
2. **Tailwind 4 in this build compiles `print:` variants** — if they do not reach `public/build`, stop before writing a separate print stylesheet.

---

## Open Questions

1. **Should an assistant be able to add to the list over MCP?** A `set_shopping_list` tool fits the "your whole shopping list, one message" promo scene, but it is out of scope here. Decide before a follow-up spec.
2. **Should the product list get an "On shopping list" filter?** The pill shows list membership per card. A filter only helps if lists get long.

---

## Resolved Questions

1. **Group the full list by shop?** **Decision:** Yes, by each product's best-buy shop. **Rationale:** It matches "Where to shop this week" on the dashboard, and a list per shop is how people walk a shop. (User, 2026-09-28.)
2. **What happens to a crossed-off item?** **Decision:** It stays struck through until "Clear crossed off". **Rationale:** A mis-tap in the shop can be undone. (User, 2026-09-28.)
3. **One list or one per shop?** **Decision:** One list across all shops. (User, 2026-09-27.)
4. **Which icon?** **Decision:** A list icon (`list-bullet`), not a cart. (User, 2026-09-27.)

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **2026-09-28, implemented on `feature/shopping-list`.**
- **No Dutch strings.** The spec asked for them in `lang/nl.json`, but that file holds only the marketing pages' keys, and `MarketingTranslationsTest` fails on any key those pages do not render. The app itself is English-only, as `specs/README.md` records.
- **Items carry their headline.** `ShoppingList` groups hold `array{product, headline, shop, crossedOff}` rather than a bare `list<Product>`, so the view does not resolve `HeadlinePrice` a second time. `ShoppingList::item()` builds one, and the header menu reuses it.
- **The four list writes live on `Product`** (`addToShoppingList`, `setListChecked`, `removeFromShoppingList`, `clearCrossedOffFor`), each inside `withoutTimestamps()`. Tests prove `updated_at` does not move.
- **The header counts both states in one query** with `count(*)` and `count(list_checked_at)`, portable to SQLite and Postgres.
- **The clear modal closes from Alpine** (`$flux.modal(...).close()`), not from PHP, because `Flux::modal()` is untyped for PHPStan.
- **STOP condition 2 held:** `yarn build` emits the `print:` variants.
- **Review round (code, security, simplification, accessibility subagents):**
  - The product-page button names the next action, "Add to shopping list" or "Remove from shopping list", as one element, so focus stays on it; the spec's "On shopping list" label read as state on an action button.
  - Remove, clear and the product-page toggle announce themselves with a Flux toast; focus moves to the page heading after a remove or a clear, because the row or the trigger it was on is gone.
  - Crossed-off text is `zinc-500` (4.8:1), not `zinc-400`. The header trigger's name carries the count ("Shopping list, 3 to buy"), and "Open shopping list" is a menu item.
  - The one-line `shopping-list-menu` partial and the `DashboardDigest::bestBuyShop()` wrapper are gone; the layout mounts the component directly and the digest calls `HeadlinePrice::buyableShop()`.
  - One word in PHP for the state: `setCrossedOff()`, `crossedOffCount`, `toggleCrossedOff()`, factory state `listedAndCrossedOff()`. The column stays `list_checked_at`.
  - Actions take `mixed` ids, so an array sent by a client is a 404 rather than a TypeError.
- **Added after the first browser test (user request, 2026-09-28):** the product list's cards carry a small list button (`listToggle` on `x-product-card`, top-left of the picture) that adds or removes the product without leaving the page (`ProductList::toggleShoppingList()`). Adding flies a copy of the picture into the header's list icon (`resources/js/fly-to-list.js`, Web Animations), which then pulses. Reduced motion skips the flight; a timeout removes the copy if a hidden tab freezes the animation. The dashboard cards keep the plain "On list" label.
