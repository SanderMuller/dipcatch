#!/usr/bin/env python3
"""Measure music tracks for a release video. The agent cannot listen, so it chooses from these numbers.

Needs ffmpeg and numpy (`pip install numpy`). Run from tools/remotion/:

  python3 scripts/analyse-music.py list music/              # compare all tracks: tempo, drive, level
  python3 scripts/analyse-music.py map music/<track>.mp3    # loudness/drive per 2 s: find breakdowns and drops
  python3 scripts/analyse-music.py beat music/<track>.mp3 --start 42 --end 98
  python3 scripts/analyse-music.py loop music/<track>.mp3 --start 84 --end 99 --bars 4

The method and the targets (tempo, drop alignment, -17 LUFS) are in .ai/docs/release-video.md.
"""
import argparse
import glob
import os
import re
import subprocess
import sys

import numpy as np

SR = 11025


def decode(path: str) -> np.ndarray:
    raw = subprocess.run(
        ['ffmpeg', '-loglevel', 'error', '-i', path, '-ac', '1', '-ar', str(SR), '-f', 'f32le', '-'],
        check=True,
        capture_output=True,
    ).stdout
    return np.frombuffer(raw, dtype=np.float32)


def lufs(path: str) -> float | None:
    out = subprocess.run(['ffmpeg', '-hide_banner', '-i', path, '-af', 'ebur128', '-f', 'null', '-'], capture_output=True, text=True).stderr
    found = re.findall(r'^\s+I:\s+(-?[\d.]+) LUFS', out, re.M)
    return float(found[-1]) if found else None


def spectra(x: np.ndarray, hop: int = 256, n: int = 1024) -> np.ndarray:
    frames = max(0, (len(x) - n) // hop)
    idx = np.arange(n)[None, :] + hop * np.arange(frames)[:, None]
    return np.abs(np.fft.rfft(x[idx] * np.hanning(n), axis=1))


def onset_envelope(x: np.ndarray, hop: int = 128) -> tuple[np.ndarray, float]:
    flux = np.maximum(0, np.diff(np.log1p(10 * spectra(x, hop)), axis=0)).sum(1)
    flux = np.maximum(flux - np.convolve(flux, np.ones(32) / 32, 'same'), 0)
    return flux, SR / hop


def tempo(x: np.ndarray, lo: float = 70, hi: float = 180, top: int = 3) -> list[float]:
    """Tempo candidates in BPM, strongest first. Half/double-time mistakes are common: check them by ear."""
    flux, fps = onset_envelope(x)
    f = flux - flux.mean()
    ac = np.fft.irfft(np.abs(np.fft.rfft(f, 2 * len(f))) ** 2)[: len(f)]
    bpms = np.arange(lo, hi + 0.1, 0.1)
    score = np.array([
        sum(w * np.interp(60 * fps / b * k, np.arange(len(ac)), ac) for k, w in ((1, 1), (2, 0.5), (4, 0.25), (0.5, 0.5)))
        for b in bpms
    ])
    picked: list[float] = []
    for i in np.argsort(score)[::-1]:
        if all(abs(bpms[i] - p) > 4 for p in picked):
            picked.append(round(float(bpms[i]), 1))
        if len(picked) == top:
            break
    return picked


def onsets_per_second(x: np.ndarray) -> float:
    flux, _ = onset_envelope(x)
    thr = np.percentile(flux, 90)
    peaks = ((flux[1:-1] > flux[:-2]) & (flux[1:-1] > flux[2:]) & (flux[1:-1] > thr)).sum()
    return float(peaks / (len(x) / SR))


def db(x: np.ndarray) -> float:
    return float(20 * np.log10(np.sqrt(np.mean(x ** 2)) + 1e-9)) if len(x) else -180.0


def cmd_list(args: argparse.Namespace) -> None:
    files = sorted(glob.glob(os.path.join(args.folder, '*.mp3')) + glob.glob(os.path.join(args.folder, '*.wav')) + glob.glob(os.path.join(args.folder, '*.m4a')))
    print(f"{'track':50s} {'len':>6s} {'tempo candidates':>22s} {'onsets/s':>9s} {'LUFS':>6s}  loudness per 10 s (dB)")
    for f in files:
        x = decode(f)
        per10 = ' '.join(f'{db(x[i * SR:(i + 10) * SR]):4.0f}' for i in range(0, min(len(x) // SR, 90), 10))
        print(f'{os.path.basename(f)[:50]:50s} {len(x) / SR:5.0f}s {str(tempo(x[: SR * 90])):>22s} {onsets_per_second(x[: SR * 90]):9.1f} {lufs(f) or 0:6.1f}  {per10}')


def cmd_map(args: argparse.Namespace) -> None:
    x = decode(args.file)
    S = spectra(x)
    freqs = np.fft.rfftfreq(1024, 1 / SR)
    flux = np.maximum(0, np.diff(np.log1p(10 * S), axis=0)).sum(1)
    bass = S[:, freqs < 120].sum(1) / (S.sum(1) + 1e-9)
    fps = SR / 256
    print('  time   dB   drive  bass   (low dB = breakdown; a jump back up = a drop; bass ~0 = no kick/bass)')
    for t in range(0, len(x) // SR, 2):
        a, b = int(t * fps), int((t + 2) * fps)
        print(f'{t:5d}s {db(x[t * SR:(t + 2) * SR]):5.1f} {flux[a:b].mean():7.1f} {np.mean(bass[a:b]):5.2f}')


def beat_phase(x: np.ndarray, beat: float) -> float:
    """Offset of the first beat from the start of x, in seconds."""
    flux, fps = onset_envelope(x, hop=32)
    t = np.arange(len(flux)) / fps
    phases = np.linspace(0, beat, 200, endpoint=False)
    return float(phases[int(np.argmax([np.interp(np.arange(p, t[-1], beat), t, flux).sum() for p in phases]))])


def cmd_beat(args: argparse.Namespace) -> None:
    x = decode(args.file)[int(args.start * SR): int(args.end * SR)]
    bpm = tempo(x, top=1)[0]
    beat = 60 / bpm
    phase = beat_phase(x, beat)
    print(f'tempo {bpm} BPM, beat {beat:.4f} s, bar (4/4) {4 * beat:.4f} s, first beat at {args.start + phase:.3f} s')


def cmd_loop(args: argparse.Namespace) -> None:
    """Best start for a loop of N bars: the audio after start and after start+loop should match (and before both)."""
    x = decode(args.file)
    bpm = args.bpm or tempo(x[int(args.start * SR): int(args.end * SR)], top=1)[0]
    beat = 60 / bpm
    loop = args.bars * 4 * beat

    def window(s: float, d: float) -> np.ndarray:
        return np.log1p(spectra(x[int(s * SR): int((s + d) * SR)]))

    results = []
    # Candidates start on a beat, so the loop seam falls on a beat.
    s = args.start + beat_phase(x[int(args.start * SR): int(args.end * SR)], beat)
    while s + loop + 1 < min(args.end + loop, len(x) / SR):
        after = np.corrcoef(window(s, 1).ravel(), window(s + loop, 1).ravel())[0, 1]
        before = np.corrcoef(window(s - 0.5, 0.5).ravel(), window(s + loop - 0.5, 0.5).ravel())[0, 1]
        results.append((min(after, before), after, before, s))
        s += beat
    print(f'tempo {bpm} BPM, loop {args.bars} bars = {loop:.4f} s')
    print(' start     match after  match before')
    for worst, after, before, start in sorted(results, reverse=True)[:6]:
        print(f'{start:7.3f}s  {after:10.3f}  {before:12.3f}')


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest='cmd', required=True)
    s = sub.add_parser('list')
    s.add_argument('folder')
    s.set_defaults(fn=cmd_list)
    s = sub.add_parser('map')
    s.add_argument('file')
    s.set_defaults(fn=cmd_map)
    for name, fn in (('beat', cmd_beat), ('loop', cmd_loop)):
        s = sub.add_parser(name)
        s.add_argument('file')
        s.add_argument('--start', type=float, required=True)
        s.add_argument('--end', type=float, required=True)
        if name == 'loop':
            s.add_argument('--bars', type=int, default=4)
            s.add_argument('--bpm', type=float)
        s.set_defaults(fn=fn)
    args = p.parse_args()
    try:
        args.fn(args)
    except FileNotFoundError:
        sys.exit('ffmpeg not found. Install it (brew install ffmpeg).')


if __name__ == '__main__':
    main()
