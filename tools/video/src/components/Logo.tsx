import React from 'react';
import {spring, useVideoConfig} from 'remotion';
import {C, font} from '../brand';
import {ramp, useFrame} from '../time';

// Rebuilt from public/images/dipcatch-logo.png (512 × 512): navy D, blue dip line, blue dot.
const D_PATH = 'M 128 90 H 250 A 180 170 0 0 1 250 430 H 128 Q 100 430 100 402 V 118 Q 100 90 128 90 Z';
const D_LENGTH = 1500;
const DIP_PATH = 'M 150 190 H 208 L 312 300 L 372 232';
const DIP_LENGTH = 390;

/** The mark. `progress` frames drive the build: D draws, the dip line falls, the dot drops in. */
export const LogoMark: React.FC<{size?: number; delay?: number; built?: boolean}> = ({size = 220, delay = 0, built = false}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	const d = built ? 1 : ramp(frame, delay, delay + 26);
	const dip = built ? 1 : ramp(frame, delay + 14, delay + 34);
	const dot = built ? 1 : spring({frame: frame - delay - 30, fps, config: {damping: 9, stiffness: 160, mass: 0.6}});
	return (
		<svg width={size} height={size} viewBox="0 0 512 512">
			<path d={D_PATH} fill="none" stroke={C.navy} strokeWidth={46} strokeLinejoin="round" strokeDasharray={D_LENGTH} strokeDashoffset={D_LENGTH * (1 - d)} />
			<path d={DIP_PATH} fill="none" stroke={C.blue} strokeWidth={40} strokeLinecap="round" strokeLinejoin="round" strokeDasharray={DIP_LENGTH} strokeDashoffset={DIP_LENGTH * (1 - dip)} />
			<g transform={`translate(312 ${386 - (1 - dot) * 90}) scale(${Math.max(0, dot)})`}>
				<circle r={42} fill={C.paper} />
				<circle r={30} fill={C.blue} />
			</g>
		</svg>
	);
};

/** Mark + "dipcatch" word mark. */
export const Logo: React.FC<{size?: number; delay?: number; built?: boolean}> = ({size = 120, delay = 0, built = false}) => {
	const frame = useFrame();
	const word = built ? 1 : ramp(frame, delay + 26, delay + 44);
	return (
		<div style={{display: 'flex', alignItems: 'center', gap: size * 0.14}}>
			<LogoMark size={size} delay={delay} built={built} />
			<div style={{overflow: 'hidden'}}>
				<div
					style={{
						fontFamily: font,
						fontWeight: 650,
						fontSize: size * 0.62,
						letterSpacing: -size * 0.025,
						color: C.ink,
						transform: `translateX(${(1 - word) * -40}%)`,
						opacity: word,
					}}
				>
					dipcatch
				</div>
			</div>
		</div>
	);
};
