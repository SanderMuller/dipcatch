import React from 'react';
import {Composition} from 'remotion';
import {Changelog} from './changelog/Changelog';
import {FPS, totalFrames, validate} from './changelog/timeline';
import type {Entry} from './changelog/types';
import example from '../entries/best-buys-here.json';

// One composition for every "What's new" video: an entry JSON is its props (--props=entries/<slug>.json).
export const RemotionRoot: React.FC = () => (
	<Composition
		id="Changelog"
		component={Changelog}
		fps={FPS}
		width={1920}
		height={1080}
		durationInFrames={totalFrames(1)}
		defaultProps={example as Entry}
		calculateMetadata={({props}) => {
			validate(props);

			return {durationInFrames: totalFrames(props.features.length)};
		}}
	/>
);
