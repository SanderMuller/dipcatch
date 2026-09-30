#!/bin/sh
# Cuts the music bed every changelog video uses into public/music.m4a (gitignored).
#
# The track is Mixkit "Motivating Mornings" (Ahjay Stelino), the one the main promo
# uses. Mixkit's terms (9.4) forbid making the track itself available to others, so it
# is never committed: point MUSIC_SOURCE at your local copy.
#
#   MUSIC_SOURCE=~/Documents/remotion/dipcatch/music/"Ahjay Stelino - Motivating Mornings.mp3" npm run music
set -eu

SOURCE="${MUSIC_SOURCE:-$HOME/Documents/remotion/dipcatch/music/Ahjay Stelino - Motivating Mornings.mp3}"

if [ ! -f "$SOURCE" ]; then
	echo "No music track at: $SOURCE" >&2
	echo "Set MUSIC_SOURCE to a local copy of Mixkit 'Motivating Mornings', or render with \"music\": false." >&2
	exit 1
fi

# The track lifts at 12.1 s. Starting at 10.5 s lands the lift on the first feature scene (frame 48, 1.6 s).
START=10.5
LENGTH=40

LUFS=$(ffmpeg -hide_banner -ss "$START" -t "$LENGTH" -i "$SOURCE" -af ebur128 -f null - 2>&1 | awk '/I:/ {value=$2} END {print value}')
GAIN=$(python3 -c "print(round(-17 - ($LUFS), 2))")

mkdir -p public
ffmpeg -v error -y -ss "$START" -t "$LENGTH" -i "$SOURCE" -vn -af "volume=${GAIN}dB" -c:a aac -b:a 192k public/music.m4a
echo "public/music.m4a: ${LENGTH} s from ${START} s, gain ${GAIN} dB (target -17 LUFS)"
