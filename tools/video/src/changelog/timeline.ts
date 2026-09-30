import {staticFile} from 'remotion';
import type {Entry, ScreenStep} from './types';

// Fixed timing: an entry never chooses its own. Frames at 30 fps.
export const FPS = 30;
export const T = 12;
// Long enough to read "New in dipcatch" before the first feature pushes in.
export const INTRO = 90;
export const FEATURE = 150;
export const END = 90;

export const totalFrames = (features: number): number => INTRO + FEATURE * features + END - T * (features + 1);

const LIMITS = {features: [1, 3], headlineLines: 3, headlineChars: 14, sub: 90, kicker: 24, where: 48} as const;

/**
 * Refuses an entry whose text would not fit its box, before anything renders.
 * The limits are measured for Geist at the sizes the scenes use.
 */
export const validate = (entry: Entry): void => {
	const problems: string[] = [];
	const count = entry.features?.length ?? 0;

	if (count < LIMITS.features[0] || count > LIMITS.features[1]) {
		problems.push(`features: ${count}, expected 1 to 3`);
	}

	if (entry.where.length > LIMITS.where) {
		problems.push(`where: ${entry.where.length} characters, at most ${LIMITS.where}`);
	}

	entry.features.forEach((f, i) => {
		if (f.headline.length > LIMITS.headlineLines) {
			problems.push(`features[${i}].headline: ${f.headline.length} lines, at most ${LIMITS.headlineLines}`);
		}
		f.headline.forEach((line) => {
			if (line.length > LIMITS.headlineChars) {
				problems.push(`features[${i}].headline "${line}": ${line.length} characters, at most ${LIMITS.headlineChars}`);
			}
		});
		if (f.sub.length > LIMITS.sub) {
			problems.push(`features[${i}].sub: ${f.sub.length} characters, at most ${LIMITS.sub}`);
		}
		if (f.kicker.length > LIMITS.kicker) {
			problems.push(`features[${i}].kicker: ${f.kicker.length} characters, at most ${LIMITS.kicker}`);
		}
		if (f.accent !== undefined && !f.headline.some((line) => line.split(' ').includes(f.accent as string))) {
			problems.push(`features[${i}].accent "${f.accent}" is not a word of the headline`);
		}
	});

	if (problems.length > 0) {
		throw new Error(`The entry does not fit the template:\n- ${problems.join('\n- ')}`);
	}
};

/**
 * Reads each `screens` moment's steps from its capture folder, so the entry
 * JSON names the capture and the coordinates come from the page itself.
 */
export const resolveCaptures = async (entry: Entry): Promise<Entry> => ({
	...entry,
	features: await Promise.all(
		entry.features.map(async (feature) => {
			if (feature.moment.kind !== 'screens') {
				return feature;
			}

			const url = staticFile(`captures/${feature.moment.capture}/steps.json`);
			const response = await fetch(url);

			if (!response.ok) {
				throw new Error(`No capture at public/captures/${feature.moment.capture}/steps.json: run tools/changelog-media/capture-screens.mjs`);
			}

			const {steps} = (await response.json()) as {steps: ScreenStep[]};

			if (steps.length < 2 || steps.slice(0, -1).some((step) => step.click === undefined)) {
				throw new Error(`Capture ${feature.moment.capture}: needs two or more steps, and a click on every step but the last`);
			}

			return {...feature, moment: {...feature.moment, steps}};
		}),
	),
});
