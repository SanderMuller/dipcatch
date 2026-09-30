import {loadFont} from '@remotion/google-fonts/Geist';

// Brand kit (brandkit.png): Geist for display, body and numbers.
export const font = loadFont('normal', {weights: ['400', '500', '600', '700', '800'], subsets: ['latin']}).fontFamily;

export const C = {
	canvas: '#FFF9E8',
	paper: '#FFFDFC',
	ink: '#1D1D1B',
	ink2: '#55534E',
	ink3: '#8C8982',
	line: '#DEDAD2',
	blue: '#3554FF',
	navy: '#1E2A78',
	savings: '#13A970',
	savingsSoft: '#E3F5EC',
	deal: '#FF5A1F',
	alert: '#EC145A',
	chart: '#F6A900',
	softYellow: '#FFF0B8',
	softBlush: '#FDE7E0',
	upcoming: '#E6ECFF',
	white: '#FFFFFF',
};

export const FPS = 30;

export const shadow = '0 30px 80px -20px rgba(60, 45, 10, 0.22), 0 8px 24px -8px rgba(60, 45, 10, 0.12)';
