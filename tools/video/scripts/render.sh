#!/bin/sh
# Renders one "What's new" video from entries/<slug>.json.
#
#   npm run check -- <slug>    cheap pass: one still per click and per scene result, tiled into out/<slug>/check-<time>/check.jpg
#   npm run render -- <slug>   the final MP4 and its poster, in ../../public/changelog/
set -eu

MODE="$1"
SLUG="${2:?Usage: npm run check|render -- <slug>}"
PROPS="entries/$SLUG.json"
OUT="out/$SLUG"
PUBLIC="../../public/changelog"

if [ ! -f "$PROPS" ]; then
	echo "No entry at $PROPS" >&2
	exit 1
fi

mkdir -p "$OUT"

# Keep these in step with src/changelog/timeline.ts: FIRST = INTRO - T is the
# frame the first feature scene starts on, and each next one starts
# FEATURE - T (138) later. In a scene the click lands at about frame 46 and the
# result has settled by about frame 100 (ACT 40 and SLOW 1.15 in moments.tsx).
FIRST=78
FEATURES=$(python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))['features']))" "$PROPS")
FRAMES="50"
i=0
while [ "$i" -lt "$FEATURES" ]; do
	FRAMES="$FRAMES $((FIRST + i * 138 + 46)) $((FIRST + i * 138 + 100))"
	i=$((i + 1))
done
FRAMES="$FRAMES $((FIRST + FEATURES * 138 + 70))"

if [ "$MODE" = "check" ]; then
	# A fresh folder per run, so stills from an earlier, longer entry never join the sheet.
	OUT="out/$SLUG/check-$(date +%Y%m%d-%H%M%S)"
	mkdir -p "$OUT"
	n=0
	for frame in $FRAMES; do
		npx remotion still Changelog "$OUT/still-$n.jpg" --props="$PROPS" --frame="$frame" --scale=0.5 --log=error
		n=$((n + 1))
	done
	ffmpeg -v error -y -pattern_type glob -i "$OUT/still-*.jpg" -vf "tile=2x$(((n + 1) / 2))" -frames:v 1 "$OUT/check.jpg"
	echo "$OUT/check.jpg: $n stills"
	exit 0
fi

mkdir -p "$PUBLIC"
# 1280 x 720 is enough for the in-app page and keeps the file small.
npx remotion render Changelog "$PUBLIC/$SLUG.mp4" --props="$PROPS" --scale=0.6667 --crf=23 --log=error
# The poster is the first feature's result, so the page shows the feature before play.
npx remotion still Changelog "$PUBLIC/$SLUG.jpg" --props="$PROPS" --frame=$((FIRST + 100)) --scale=0.6667 --jpeg-quality=85 --log=error
ls -la "$PUBLIC/$SLUG.mp4" "$PUBLIC/$SLUG.jpg"
