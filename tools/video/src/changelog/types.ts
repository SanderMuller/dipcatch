import type {ArtKind} from '../components/ProductArt';

export type ProductRow = {
	title: string;
	art: ArtKind;
	/** Shop host, as the app shows it: "ah.nl". */
	shop: string;
	/** As the app prints it: "€4.34 /kg". */
	price: string;
};

/**
 * One UI moment per feature. Each kind is a small, fixed animation of a real
 * app surface; a new kind is new code, a new entry is only a new JSON file.
 */
export type Moment =
	| {
			/** A switch turns on and the list keeps only the matching cards. */
			kind: 'filter';
			title: string;
			toggle: string;
			items: (ProductRow & {keep: boolean})[];
	  }
	| {
			/** A button on a product is pressed and the product shows its new state. */
			kind: 'button';
			product: ProductRow;
			button: string;
			done: string;
	  }
	| {
			/** A push notification lands on a phone lock screen. */
			kind: 'notification';
			title: string;
			body: string;
	  }
	| {
			/** Rows build up one by one, the first one gets a badge. */
			kind: 'list';
			title: string;
			rows: {label: string; value: string; shop?: string}[];
			badge: string;
	  };

export type Feature = {
	/** The page or area: "Products", "Dashboard". At most 24 characters. */
	kicker: string;
	/** Headline lines, at most 3 lines of at most 14 characters. */
	headline: string[];
	/** The one word in the headline shown in brand blue. */
	accent?: string;
	/** One sentence, at most 90 characters. */
	sub: string;
	moment: Moment;
};

export type Entry = {
	/** 1 to 3 features. */
	features: Feature[];
	/** Where to find it in the app: "Products › Best buys here". At most 48 characters. */
	where: string;
	/** Play the music bed in public/music.m4a. */
	music?: boolean;
};
