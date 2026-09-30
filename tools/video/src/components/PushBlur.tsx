import React from 'react';
import {AbsoluteFill, Easing, interpolate} from 'remotion';
import type {TransitionPresentation, TransitionPresentationComponentProps} from '@remotion/transitions';

type Props = Record<string, never>;

const ease = Easing.bezier(0.7, 0, 0.2, 1);

// The old scene slides left and blurs, the new one slides in from the right.
const Push: React.FC<TransitionPresentationComponentProps<Props>> = ({children, presentationDirection, presentationProgress}) => {
	const p = ease(presentationProgress);
	if (presentationDirection === 'exiting') {
		return (
			<AbsoluteFill style={{transform: `translateX(${-p * 35}%) scale(${1 - p * 0.06})`, filter: `blur(${p * 14}px)`, opacity: 1 - p * 0.4}}>{children}</AbsoluteFill>
		);
	}
	return (
		<AbsoluteFill style={{transform: `translateX(${(1 - p) * 100}%)`, boxShadow: '-40px 0 80px rgba(60,45,10,0.18)', filter: `blur(${interpolate(p, [0, 1], [10, 0])}px)`}}>
			{children}
		</AbsoluteFill>
	);
};

export const pushBlur = (): TransitionPresentation<Props> => ({component: Push, props: {}});
