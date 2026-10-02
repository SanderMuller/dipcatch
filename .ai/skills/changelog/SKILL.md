---
name: changelog
description: >-
  Decides whether the commits about to be pushed to main earn an entry on the in-app "What's new" page (config/changelog.php), and writes it in three passes — draft, simplify, humanizer. Activates when: pushing to main, about to push, shipping a feature, adding a shop, or when user mentions: changelog, what's new, release note for users, in-app changelog.
---

# In-App Changelog

The "What's new" page at `/app/changelog` (`app.changelog`) reads `config/changelog.php`. Every signed-in user can open it from the "More" menu. This skill keeps it current without turning it into a commit log.

This is not `CHANGELOG.md`. CI rewrites that file on each release. See the `release-notes` guideline.

## When to use this skill

- Before every push to `main`.
- When the user asks to add something to the changelog or "What's new".

## 1. Collect the commits

```bash
git fetch origin main --quiet
git log origin/main..HEAD --no-merges --format='%h %ad %s%n%b' --date=short
git diff origin/main..HEAD --stat
```

When the range is empty, stop: nothing is about to be pushed.

Read `config/changelog.php` too, so you do not write an entry that already exists. If a change extends a feature that already has an entry from the last few days, update that entry instead of adding a second one.

## 2. Decide what earns an entry

Most pushes earn nothing. An empty result is the normal outcome, not a failure.

Ask one question per change: **would a user notice this, and would they want to know?**

| Earns an entry | Category |
|---|---|
| A new page, tool, or thing a user can do | `feature` |
| A clear change to how an existing feature works | `feature` |
| A shop added to `site.supported_hosts`, or a shop that stopped working for good | `shop` |
| A new Pro feature, or a change to what Pro or Free includes | `pro` |
| A fix to a bug users hit: a wrong price, a missed alert, a broken page, a wrong suggestion | `fix` |

| Never earns an entry |
|---|
| Refactors, renames, code style (`CS`), PHPStan or Rector fixes |
| Tests, CI, tooling, `.ai/`, specs, docs for developers |
| "Apply codex-review feedback" and other follow-ups to a change in the same push |
| Small styling, spacing, copy tweaks, and layout on one screen size |
| Dependency updates, config, performance work users do not feel |
| Admin-only (Filament) and internal changes |
| A fix to a bug that never reached `main`, or that shipped in the same push |

Group related commits into one entry. Five commits that build the shopping list are one entry about the shopping list. A day with three small user-visible fixes can be one `fix` entry that names all three.

For a new shop, check `git diff origin/main..HEAD -- config/site.php` for the `supported_hosts` change. Use the shop name users know, not the host.

## 3. Write the entry — three passes

Keep each pass in a scratch file under `internal/` (gitignored), not in the repository.

### Pass 1: draft

Write down what changed for the user: what they can do now, where they find it, and who gets it (everyone, or Pro only). Get the facts right. Read the commit body and the code if the subject is not enough. Check the words the app uses for buttons and pages (`grep -rn "__('" resources/views`), and use the same words.

### Pass 2: simplify

Rewrite the draft so anyone can read it:

- Say what the user gets, not how DipCatch does it. One or two sentences on the benefit usually carry the whole entry. Leave out the mechanism: when a step starts, what runs first, what a check compares, how a limit or a fallback works.
- Describe what the user sees in their own words ("results come in while you wait"), not the interface parts that changed ("a progress bar replaces the spinner").
- A title of at most 8 words that names the thing, in sentence case. No full stop.
- A body of 1 to 3 short paragraphs, at most about 50 words in total.
- Say "you". Everyday words. Short sentences.
- No internal names: no class names, no "Jev", "TypeSafe", "Serper", "MCP" or "Livewire". Say "AI check", "AI assistant", "search".
- No numbers from the code (thresholds, limits, cron times) unless the user sees them.
- Name the place in the app where the feature lives: the dashboard, the product page, Settings.
- Public repository: no customer names, no e-mail addresses, no internal hosts. Shop names are fine.

Too deep:

> With Pro, DipCatch starts looking for more shops as soon as you paste a product link, while you still check the preview. Each shop appears once it's checked, so a slow shop no longer holds up the others.
>
> A progress bar replaces the spinner while it searches.

At the user's level:

> Finding more shops for a product is faster now. They come in one by one, so you can look at the first ones while DipCatch keeps searching.

### Pass 3: humanizer

Invoke the `humanizer` skill on the simplified text. Keep the result plain and neutral: this is a release note, so no jokes and no hype. Contractions are fine. This skill sets the voice for these entries; the `voice` guideline's Simplified Technical English rule does not apply to them, because an end user reads them.

## 4. Pick a link and media

Each entry can carry a link, and a video or a screenshot. Add them only where they help a user find or recognise the feature.

| Entry | Link | Media |
|---|---|---|
| A feature with a page, a switch, or a setting | Yes: the route of that page | — |
| A feature a user does in steps (add, filter, tick off) | Yes | A video, with the `changelog-video` skill |
| A feature that is a new panel, card or list to look at | Yes | A screenshot |
| A Pro feature behind a setting | Yes: `product-features.edit` or the setting's page | A screenshot of what it shows, if anything |
| A fix, a new shop, a change with nothing new to see | No | No |

- `link` is a route name and a short button label: `['route' => 'app.shopping-list', 'label' => 'Open your shopping list']`. Use the route name, never a URL.
- Make one video at most per push. It takes a check round and a render.
- An entry has a video or a screenshot, not both.

### Screenshots

`tools/changelog-media/` takes them from the local site with a seeded throwaway account. Never take one from your own account or from production: the files are published with the repository.

```bash
php tools/changelog-media/seed.php screenshots
node tools/changelog-media/capture.mjs <name>
php tools/changelog-media/seed.php screenshots --teardown
```

- A shot is one function in `shots` in `capture.mjs`. It opens the page and returns the element, and optionally a `frame` (the card around it) and a viewport width. Add a function for a new shot. If the feature needs data the seed does not make, add it to the `screenshots` scenario in `seed.php` with fictional product names and real shop names.
- The script hides everything else on the page and keeps 40 px of page background on each side, so the shot is not cramped. It writes `public/changelog/<name>.png` at 2× in light mode.
- Look at every PNG before you use it. Check that the element is complete, that nothing from a neighbour shows, and that there is no name, e-mail address or debug bar. Pick a viewport width at which the text is not cut short.
- Give the `image` an `alt` that says what the screenshot shows.

## 5. Add it to the config

Add one array to `entries` in `config/changelog.php`:

```php
[
    'date' => '2026-09-30',          // the day it ships, YYYY-MM-DD
    'category' => 'feature',         // feature, shop, pro or fix
    'title' => 'Suggested shops on your dashboard',
    'body' => 'The dashboard now lists …',
    'image' => ['src' => 'changelog/suggested-shops.png', 'alt' => 'The suggested shops card on the dashboard …'],
    'link' => ['route' => 'app.dashboard', 'label' => 'Go to your dashboard'],
    // or 'video' => 'shopping-list', for public/changelog/shopping-list.mp4 and .jpg
],
```

- Plain text only. Do not use HTML or Markdown. A blank line (`"\n\n"` in a double-quoted string) starts a new paragraph.
- Put new entries at the top of the list, so the file reads newest first. The page sorts by date anyway.

Then run the page tests:

```bash
vendor/bin/pest tests/Feature/Changelog --compact || true
```

`every committed changelog entry is valid, and its link and media exist` fails on a bad date, an unknown category, a route that does not exist, or a missing media file.

## 6. Report

Tell the user which commits you judged, which entry you added or updated, and which commits you skipped and why, in one line each. Name the link and media you added, and the screenshots you looked at. When nothing earned an entry, say that in one sentence.
