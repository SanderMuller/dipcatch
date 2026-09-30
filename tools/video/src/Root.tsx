import React from 'react';
import {Composition} from 'remotion';
import {Changelog} from './changelog/Changelog';
import {ProductArt, type ArtKind} from './components/ProductArt';
import {FPS, resolveCaptures, totalFrames, validate} from './changelog/timeline';
import type {Entry} from './changelog/types';
import example from '../entries/best-buys-here.json';

// One composition for every "What's new" video: an entry JSON is its props (--props=entries/<slug>.json).
export const RemotionRoot: React.FC = () => (
	<>
	<Composition
		id="Changelog"
		component={Changelog}
		fps={FPS}
		width={1920}
		height={1080}
		durationInFrames={totalFrames(1)}
		defaultProps={example as Entry}
		calculateMetadata={async ({props}) => {
			validate(props);

			return {durationInFrames: totalFrames(props.features.length), props: await resolveCaptures(props)};
		}}
	/>
	{/* One drawn pack as a product photo, for the seeded account the captures use:
	     npx remotion still ProductArt ../changelog-media/photos/coffee.png --props='{"kind":"coffee"}' */}
	<Composition
		id="ProductArt"
		component={({kind}: {kind: ArtKind}) => <ProductArt kind={kind} size={600} bg="#FFFFFF" radius={0} />}
		width={600}
		height={600}
		fps={FPS}
		durationInFrames={1}
		defaultProps={{kind: 'coffee' as ArtKind}}
	/>
	</>
);
