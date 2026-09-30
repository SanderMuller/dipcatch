---
name: changelog-video
description: "Makes a short on-brand video (about 9–20 s) for a \"What's new\" entry from the Remotion template in tools/video: one JSON file per video, fixed scenes and timing, a still check, then one render. Activates when: the changelog skill writes an entry for a feature a user can see, or when user mentions: changelog video, what's new video, feature video, short video for a feature."
---

# Changelog video

A "What's new" entry can carry a short video that shows the feature the way it looks in the app, so a user recognises it there. The template in `tools/video/` does the work: brand, timing, animation and music are code. A new video is one JSON file. Keep it that way — that is what makes a video quick and cheap.

## When

- A feature a user can see: a new page, a filter, a button, a notification, a new list. One to three features per video.
- Skip it for a fix, a new shop, a copy change, or anything with no screen to show.

## Setup (once per clone)

```bash
cd tools/video
npm install
npm run music   # cuts the music bed into public/music.m4a
```

The music is Mixkit "Motivating Mornings". Mixkit's terms (9.4) forbid making the track available to others, so `public/*.m4a` is gitignored and the source track lives outside the repository. `npm run music` reads it from `MUSIC_SOURCE` (default `~/Documents/remotion/dipcatch/music/`). Without the track, set `"music": false` in the entry and say so in your report. The rendered MP4 carries the music and is fine to publish: it is our own video.

## Steps

1. **Trace the feature.** Read the Blade view and the component the change touched. Copy the real labels, the order of things, and the words on buttons and switches. Every claim in the video must match the code on `main`. Use fictional product names or plain generic ones, and real shop hosts.

2. **Write `tools/video/entries/<slug>.json`.** Use the slug of the changelog entry. The shape is `Entry` in `src/changelog/types.ts`; `entries/best-buys-here.json` is a worked example, and `entries/example-all-kinds.json` shows the other kinds (check only; never render it to `public/`).
   - `features`: 1 to 3. Each has a `kicker` (the page, at most 24 characters), a `headline` (at most 3 lines of at most 14 characters), an `accent` word from the headline, a `sub` sentence (at most 90 characters) and a `moment`.
   - `where`: where to find it in the app, as the navigation names it: `Products › Best buys here`. At most 48 characters.
   - `moment.kind` picks the animation:

   | kind | Shows | Use for |
   |---|---|---|
   | `filter` | A switch turns on; only the matching cards stay | Filters, toggles, views that narrow a list |
   | `button` | A button on a product is pressed; a ✓ state appears | Actions: add, follow, share, save |
   | `notification` | A push lands on a phone lock screen | Alerts and notification changes |
   | `list` | Rows build up; the first gets a badge | Comparisons, rankings, new lists |

   The template refuses an entry whose text does not fit, and names the field. Shorten the text; do not change the limits.

3. **Check with stills.** `npm run check -- <slug>` renders one still per scene at half size into `out/<slug>/check-<time>/check.jpg`. Read the image. Look for text under the mock-up, a label that differs from the app, and an empty card. Fix the JSON and run it again. This takes seconds; the full render takes about a minute.

4. **Render.** `npm run render -- <slug>` writes `public/changelog/<slug>.mp4` (1280 × 720, H.264, with music) and `public/changelog/<slug>.jpg` (the poster: the first feature's result). Make a contact sheet and read it once:

   ```bash
   ffmpeg -v error -i ../../public/changelog/<slug>.mp4 -vf "fps=2,scale=320:-1,tile=6x4" -frames:v 1 -y out/<slug>/sheet.jpg
   ```

5. **Link it from the entry** in `config/changelog.php`. The page has no `video` key yet: until it does, report the file paths and leave the entry as it is. The intended display is the poster with a play control and sound: never autoplay.

6. **Commit** the entry JSON, the MP4 and the poster. Never commit `tools/video/public/*.m4a`, `node_modules/` or `out/`.

## Keep it cheap

- One check round, one render. Do not render the full video to look at a layout: that is what the stills are for.
- Do not add scenes, change timing, or style one video by hand. When a feature needs a UI moment no `kind` covers, add the kind once to `src/changelog/moments.tsx` and the type to `types.ts`, so the next entry can use it. Act on the same beat as the others (`ACT`).
- Timing is fixed in `src/changelog/timeline.ts`: intro 2 s, 5 s per feature, end card 3 s.

## Report

State the file paths, the length, whether it has music, and what you checked (stills, contact sheet). Say what you did not check: nobody listens to the audio in this flow, so name the music bed you used.
