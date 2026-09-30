---
name: changelog-video
description: "Makes a short on-brand video (about 10–20 s) for a \"What's new\" entry: captures the real app states with tools/changelog-media, composes them in the Remotion template in tools/video, checks stills, then renders once. Activates when: the changelog skill writes an entry for a feature a user can see, or when user mentions: changelog video, what's new video, feature video, short video for a feature."
---

# Changelog video

A "What's new" entry can carry a short video that shows the feature the way it works in the app, so a user recognises it there. The video shows the app's real screens: a script captures each state from a seeded local account, and the template in `tools/video/` zooms to what changes and clicks where the user clicks. Brand, timing, camera, cursor and music are code. A new video is a capture flow plus one JSON file.

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

1. **Trace the feature.** Read the Blade view and the component the change touched. Write down every state the feature has and the control that leads to each one. For example, for the shopping list: the card's list icon and "On list", the groups per shop, skipping a shop, crossing off, "Clear crossed off", Print, and the header menu. Pick the one to three states the video shows, one click each.

2. **Capture them.** Add a flow to `flows` in `tools/changelog-media/capture-screens.mjs` per state change. A flow opens the page, calls `shot(focus, click)` before the click, does the click, and calls `shot(focus, undefined, highlight)` after it. `focusOf()` and `clickOf()` read the boxes off the page.
   - Pick the viewport so the focus is about 350 to 650 CSS px wide. Wider, and the text is too small in a 1280 × 720 video. Narrower, and the zoom goes soft. The list page reads well at 560, the products page at 1024.
   - Add data the flow needs to the `video` scenario in `tools/changelog-media/seed.php`. Use fictional product names, real shop hosts, and the drawn packs in `tools/changelog-media/photos/` as photos. Never capture a real account: the video is published.
   - A flow that writes to the list or to a product runs after the flows that only read. Seed again before each run.

   ```bash
   php tools/changelog-media/seed.php video
   node tools/changelog-media/capture-screens.mjs [flow ...]
   php tools/changelog-media/seed.php video --teardown
   ```

   The captures go to `tools/video/public/captures/<flow>/` (gitignored, several MB). Run the script again after any UI change.

3. **Write `tools/video/entries/<slug>.json`.** Use the slug of the changelog entry. The shape is `Entry` in `src/changelog/types.ts`, and `entries/shopping-list.json` is a worked example.
   - `features`: 1 to 3, one state change each. Each has a `kicker` (the page, at most 24 characters), a `headline` (at most 3 lines of at most 14 characters), an `accent` word from the headline, a `sub` sentence (at most 90 characters) and a `moment`.
   - `moment`: `{"kind": "screens", "capture": "<flow>"}`. The render reads the flow's `steps.json`.
   - `where`: where to find it in the app, as the navigation names it: `Products › Shops › Best buys here`. At most 48 characters.

   The template refuses an entry whose text does not fit, and names the field. Shorten the text; do not change the limits.

   **Fallback: drawn moments.** `filter`, `button`, `notification` and `list` draw a simplified UI; `entries/example-all-kinds.json` shows them (check only, never render it to `public/`). Use one only for a state the app cannot show on a local site, such as a push notification on a phone. A drawn moment can differ from how the feature works: the first shopping-list video used `button` and the user rejected it for that. A run that uses one says so in its report, and names what the drawing leaves out.

4. **Check with stills.** `npm run check -- <slug>` renders, per feature, a still at the click and one at the result, at half size, into `out/<slug>/check-<time>/check.jpg`. Read it, then do the fidelity check:
   - Every state the headline and the sub claim is visible in a capture.
   - At the click, the cursor tip is on the real control.
   - At the result, the blue ring is on the part that changed, and no tooltip or popover covers it.
   - The app's text is readable.

   Fix the flow or the JSON and run it again. This takes seconds; the full render takes about two minutes.

5. **Render.** `npm run render -- <slug>` writes `public/changelog/<slug>.mp4` (1280 × 720, H.264, with music) and `public/changelog/<slug>.jpg` (the poster: the first feature's result). Make a contact sheet and read it once:

   ```bash
   ffmpeg -v error -i ../../public/changelog/<slug>.mp4 -vf "fps=2,scale=320:-1,tile=6x5" -frames:v 1 -y out/<slug>/sheet.jpg
   ```

6. **Link it from the entry** in `config/changelog.php` with `'video' => '<slug>'`. The "What's new" page shows the poster with a play control, and plays with sound only when the user starts it. An entry has a video or a screenshot, not both. The `changelog` skill owns the rest of the entry.

7. **Commit** the entry JSON, the capture flow, the MP4 and the poster. Never commit `tools/video/public/captures/`, `tools/video/public/*.m4a`, `node_modules/` or `out/`.

## Camera and cursor

The `screens` moment in `src/changelog/moments.tsx` owns these rules. Keep them when you change it:

- The click lands on `ACT`. The next capture fades in, and the camera moves to its focus and settles about 1 s after the click.
- The cursor enters inside the card, on the side away from the headline, and shows well before the click. After the click it rests in the empty bottom-right corner, off the control and off the text.
- Zoom only to a focus that holds something. The camera frames the focus box and adds a slow push-in, so a held state does not look frozen.
- The image and the cursor sit in one box, so the scene's tilt moves them together.

## Timing

`src/changelog/timeline.ts` is the source: intro 3 s (`INTRO` 90), 5 s per feature (`FEATURE` 150), end card 3 s (`END` 90), with a 12-frame overlap (`T`) at each cut. Two scripts copy numbers from it. Change them with it:

- `scripts/render.sh`: `FIRST = INTRO - T`, the frame the first feature starts on. The next feature starts `FEATURE - T` later. The poster is `FIRST + 100`.
- `scripts/music.sh`: `START = 12.1 - FIRST / 30`, so the music lifts as the first feature pushes in. Run `npm run music` again after a change.

## Keep it cheap

- One check round, one render. Do not render the full video to look at a layout: that is what the stills are for.
- Do not add scenes, change timing, or style one video by hand. A new need goes into the template once, so the next entry can use it.

## Report

State the file paths, the length, whether it has music, and what you checked: stills, fidelity check, contact sheet. Say what you did not check. Nobody listens to the audio in this flow, so name the music bed you used, and headless Chromium cannot play the MP4.
