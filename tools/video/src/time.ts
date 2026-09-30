import {Easing, interpolate, useCurrentFrame} from 'remotion';

// Everything inside a scene runs 15% slower than real time, so first-time viewers can follow.
export const SLOW = 1.15;

export const useFrame = (): number => useCurrentFrame() / SLOW;

const smooth = Easing.bezier(0.33, 0, 0.2, 1);

// 0 → 1 between two (slowed) frames, eased and clamped.
export const ramp = (frame: number, from: number, to: number, easing: (t: number) => number = smooth): number =>
	interpolate(frame, [from, to], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing});

export const mix = (a: number, b: number, t: number): number => a + (b - a) * t;
