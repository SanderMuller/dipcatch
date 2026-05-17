# Per-Shop Notes

## Overview

Add a free-text per-shop annotation: "this shop ships only to NL", "needs coupon CODE10 at checkout", "the price they show is excl. tax", etc. One nullable `notes` text column on `shops`, editable from the Shop edit / detail surfaces, displayed read-only on the list / per-product view.

User decision locked at spec time:

- **Notes only**, no tags. Tags are deferred — if added later, the preferred shape is per-user `tags` table via the Spatie `laravel-tags` package (see Open Questions).

This is a small feature spec, not on the launch-readiness build order in `specs/README.md`.

---

## Current state

- `shops` table (`database/migrations/2026_04_26_162646_zz_create_shops_table.php`) has no annotation column today.
- Filament Admin Shops resource (`app/Filament/Admin/Resources/Shops/`) renders the shop list for ops triage; no edit form for shop-owned metadata yet.
- App-side: the per-product Shops relation manager (`app/Filament/App/Resources/Products/RelationManagers/ShopsRelationManager.php`) lets users add / edit / delete shops on their products — that's the primary edit surface for end-user-owned data.

---

## Data model

Single migration `add_notes_to_shops_table`:

```php
$table->text('notes')->nullable();
```

- Free text, nullable. No length cap — `text` already affords ~64KB and we don't expect anyone to paste a novel.
- Belongs to whoever owns the shop (transitively via the product owner). No new authorization.

`App\Models\Shop` model:

- Add `'notes'` to `$guarded` exclusion (or to `$fillable` if the model uses fillable) — check the existing convention. The current model uses `$guarded = []` for the most part (verify) so no model change is strictly required, but a `notes()` accessor that null-coalesces to `''` would simplify the Filament form.

---

## UI changes

### App panel — `ShopsRelationManager`

Add a `Textarea::make('notes')` to the create + edit actions on the per-product Shops relation manager:

- Label: "Notes (private)"
- Placeholder: "Anything worth remembering about this shop — shipping limits, coupons, payment quirks…"
- `->rows(3)->maxLength(64000)->columnSpanFull()`.

In the table view, show a small note-present indicator (e.g. a Heroicon `chat-bubble-oval-left` icon column) when `notes !== null`, with a tooltip showing the first ~120 chars.

### Admin panel — Admin Shops resource

Read-only: render the notes as a `TextColumn` below the table (or in the `ViewShop` infolist if there's one). Admins can see but not edit user-owned annotations.

### Public-facing — Add Shop wizard

**Not in scope.** Adding a notes textarea to the AddShop probe form clutters the URL-paste flow. Notes are post-creation annotations.

---

## Phases

### Phase 1 — Schema + model

1. Migration adds `notes` to `shops`.
2. Verify `Shop` model fillable / guarded handles the new column. Add to test factory as `'notes' => null`.
3. Test: round-trip via factory + `->update(['notes' => '...'])` + refresh.

### Phase 2 — App panel relation manager

1. Add `Textarea` to `ShopsRelationManager` create + edit actions.
2. Add note-present indicator column to the table.
3. Test (Livewire / Filament action): create a shop with notes, edit notes, clear notes, verify persistence + display.

### Phase 3 — Admin panel read-only display

1. Add `TextColumn::make('notes')->limit(60)->tooltip(fn ($state) => $state)` to the admin Shops table.
2. Test: admin sees the notes value when present.

### Phase 4 — Verify

1. `vendor/bin/pest --compact`.
2. `vendor/bin/pint --dirty --format agent`.
3. `vendor/bin/phpstan analyse --memory-limit=2G`.

---

## Open Questions

- **Q1:** maximum length on the `notes` field? Default = no app-level cap (text column ~64KB). If we surface notes in places where a giant blob hurts (admin table tooltip, mobile view), we'd cap at the UI layer with `->limit(N)`. **Default:** no app cap; UI caps where needed.
- **Q2:** XSS / markdown handling? Default = render as plain text via Blade's `{{ }}` escape. No markdown, no autolink. Keeps the surface minimal; revisit only if users ask. **Default:** plain text.
- **Q3 (future, deferred):** if tags get added later, the stated preferences are: per-user `tags` table (each user maintains their own set) via the Spatie `laravel-tags` package (polymorphic + slug + locale support, useful if tagging grows to articles/products). Not scoped here.
- **Q4:** include the note in the per-product Filament infolist (`ProductInfolist`) so users see their notes alongside the cheapest-shop summary? **Default:** no — keep notes scoped to the shop list / edit, not the product summary. Avoid duplication.

---

## Findings

(filled during implementation)
