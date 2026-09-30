import type {Entry} from './types';

// Fixed timing: an entry never chooses its own. Frames at 30 fps.
export const FPS = 30;
export const T = 12;
export const INTRO = 60;
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
