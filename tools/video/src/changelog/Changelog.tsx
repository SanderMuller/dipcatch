import React from 'react';
import {AbsoluteFill, Html5Audio, interpolate, staticFile} from 'remotion';
import {TransitionSeries, linearTiming} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {C, font} from '../brand';
import {Background, FeatureLayout, Headline, Kicker, SubLine, type Word} from '../components/ui';
import {Logo} from '../components/Logo';
import {pushBlur} from '../components/PushBlur';
import {ramp, useFrame} from '../time';
import {MomentView} from './moments';
import {END, FEATURE, INTRO, T, totalFrames} from './timeline';
import type {Entry, Feature} from './types';

const Intro: React.FC = () => {
	const frame = useFrame();
	return (
		<AbsoluteFill>
			<Background seed={21} />
			<AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 34}}>
				<Logo size={150} delay={0} />
				<div style={{fontFamily: font, fontSize: 40, fontWeight: 600, color: C.blue, opacity: ramp(frame, 26, 36), transform: `translateY(${(1 - ramp(frame, 26, 36)) * 14}px)`}}>
					New in dipcatch
				</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};

const headlineWords = (feature: Feature): Word[][] =>
	feature.headline.map((line) => line.split(' ').map((text) => ({text, color: text === feature.accent ? C.blue : undefined})));

const FeatureScene: React.FC<{feature: Feature; index: number; of: number}> = ({feature, index, of}) => (
	<FeatureLayout
		seed={22 + index}
		visualWidth={feature.moment.kind === 'notification' ? 560 : 900}
		text={
			<>
				<Kicker index={of > 1 ? String(index + 1).padStart(2, '0') : 'New'} label={feature.kicker} />
				<Headline delay={6} size={96} lines={headlineWords(feature)} />
				<SubLine delay={18}>{feature.sub}</SubLine>
			</>
		}
		visual={<MomentView moment={feature.moment} />}
	/>
);

const End: React.FC<{where: string}> = ({where}) => {
	const frame = useFrame();
	const p = ramp(frame, 8, 22);
	return (
		<AbsoluteFill>
			<Background seed={29} />
			<AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 30, fontFamily: font}}>
				<div style={{fontSize: 34, fontWeight: 500, color: C.ink2, opacity: p}}>Find it at</div>
				<div
					style={{
						fontSize: 56,
						fontWeight: 700,
						letterSpacing: -1.5,
						color: C.ink,
						background: C.paper,
						border: `1.5px solid ${C.line}`,
						borderRadius: 24,
						padding: '18px 34px',
						opacity: p,
						transform: `translateY(${(1 - p) * 20}px)`,
					}}
				>
					{where}
				</div>
				<div style={{marginTop: 40, opacity: ramp(frame, 26, 36)}}>
					<Logo size={80} built />
				</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};

export const Changelog: React.FC<Entry> = ({features, where, music = true}) => {
	const total = totalFrames(features.length);
	return (
		<AbsoluteFill style={{background: C.canvas}}>
			{music ? (
				<Html5Audio
					src={staticFile('music.m4a')}
					volume={(f) => interpolate(f, [0, 12, total - 45, total], [0, 1, 1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}
				/>
			) : null}
			<TransitionSeries>
				<TransitionSeries.Sequence durationInFrames={INTRO}>
					<Intro />
				</TransitionSeries.Sequence>
				{features.map((feature, i) => (
					<React.Fragment key={i}>
						<TransitionSeries.Transition presentation={pushBlur()} timing={linearTiming({durationInFrames: T})} />
						<TransitionSeries.Sequence durationInFrames={FEATURE}>
							<FeatureScene feature={feature} index={i} of={features.length} />
						</TransitionSeries.Sequence>
					</React.Fragment>
				))}
				<TransitionSeries.Transition presentation={fade()} timing={linearTiming({durationInFrames: T})} />
				<TransitionSeries.Sequence durationInFrames={END}>
					<End where={where} />
				</TransitionSeries.Sequence>
			</TransitionSeries>
		</AbsoluteFill>
	);
};
