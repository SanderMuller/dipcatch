import React from 'react';
import {AbsoluteFill, spring, useVideoConfig} from 'remotion';
import {C, font, shadow} from '../brand';
import {mix, ramp, useFrame} from '../time';
import {useVertical} from '../format';

/** Warm canvas with the two soft glows from the app, drifting slowly. */
export const Background: React.FC<{seed?: number}> = ({seed = 0}) => {
	const f = useFrame() + seed * 90;
	const a = Math.sin(f / 70);
	const b = Math.cos(f / 85);
	return (
		<AbsoluteFill style={{background: C.canvas, overflow: 'hidden'}}>
			<div
				style={{
					position: 'absolute',
					width: 1300,
					height: 1100,
					left: -420 + a * 60,
					top: -520 + b * 40,
					borderRadius: '50%',
					background: 'radial-gradient(closest-side, rgba(255, 219, 120, 0.55), rgba(255, 219, 120, 0))',
				}}
			/>
			<div
				style={{
					position: 'absolute',
					width: 1400,
					height: 1200,
					right: -520 + b * 50,
					bottom: -640 + a * 50,
					borderRadius: '50%',
					background: 'radial-gradient(closest-side, rgba(253, 200, 185, 0.6), rgba(253, 200, 185, 0))',
				}}
			/>
		</AbsoluteFill>
	);
};

export type Word = {text: string; color?: string};

export const words = (text: string, color?: string): Word[] => text.split(' ').map((t) => ({text: t, color}));

/** Word-by-word spring reveal from below a mask. */
export const Headline: React.FC<{lines: Word[][]; delay?: number; size?: number; stagger?: number; align?: 'left' | 'center'}> = ({
	lines,
	delay = 0,
	size = 104,
	stagger = 3,
	align = 'left',
}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	let index = 0;
	return (
		<div style={{fontFamily: font, fontWeight: 700, fontSize: size, lineHeight: 1.02, color: C.ink, letterSpacing: -size * 0.04, textAlign: align}}>
			{lines.map((line, li) => (
				<div key={li} style={{display: 'flex', gap: size * 0.24, justifyContent: align === 'center' ? 'center' : 'flex-start'}}>
					{line.map((word) => {
						const i = index++;
						const p = spring({frame: frame - delay - i * stagger, fps, config: {damping: 17, stiffness: 150, mass: 0.7}});
						return (
							<span key={i} style={{display: 'inline-block', overflow: 'hidden', paddingBottom: size * 0.14, marginBottom: -size * 0.14}}>
								<span style={{display: 'inline-block', color: word.color ?? C.ink, transform: `translateY(${(1 - p) * 110}%)`}}>{word.text}</span>
							</span>
						);
					})}
				</div>
			))}
		</div>
	);
};

/** Small step label: "01 · Track it". */
export const Kicker: React.FC<{index: string; label: string; delay?: number}> = ({index, label, delay = 0}) => {
	const frame = useFrame();
	const p = ramp(frame, delay, delay + 14);
	return (
		<div style={{display: 'flex', alignItems: 'center', gap: 14, opacity: p, transform: `translateX(${(1 - p) * -24}px)`, fontFamily: font}}>
			<div
				style={{
					height: 44,
					padding: '0 16px',
					borderRadius: 22,
					background: C.paper,
					border: `1.5px solid ${C.line}`,
					display: 'flex',
					alignItems: 'center',
					gap: 10,
					fontSize: 22,
					fontWeight: 600,
					color: C.ink2,
				}}
			>
				<span style={{width: 10, height: 10, borderRadius: 5, background: C.savings}} />
				<span style={{color: C.blue, fontWeight: 700}}>{index}</span>
				{label}
			</div>
		</div>
	);
};

export const SubLine: React.FC<{children: React.ReactNode; delay?: number; size?: number; maxWidth?: number}> = ({children, delay = 0, size = 34, maxWidth = 640}) => {
	const frame = useFrame();
	const p = ramp(frame, delay, delay + 16);
	const vertical = useVertical();
	return (
		<div style={{fontFamily: font, fontWeight: 450, fontSize: vertical ? Math.max(size, 38) : size, lineHeight: 1.38, color: C.ink2, maxWidth: vertical ? 920 : maxWidth, opacity: p, transform: `translateY(${(1 - p) * 20}px)`}}>
			{children}
		</div>
	);
};

/** White app card with the thin line border. */
export const Card: React.FC<{children: React.ReactNode; style?: React.CSSProperties; pad?: number}> = ({children, style, pad = 32}) => (
	<div style={{background: C.paper, border: `1.5px solid ${C.line}`, borderRadius: 26, padding: pad, boxShadow: shadow, fontFamily: font, color: C.ink, ...style}}>{children}</div>
);

export const Badge: React.FC<{children: React.ReactNode; bg: string; color: string; size?: number; style?: React.CSSProperties}> = ({children, bg, color, size = 20, style}) => (
	<span
		style={{
			display: 'inline-flex',
			alignItems: 'center',
			gap: 6,
			background: bg,
			color,
			fontSize: size,
			fontWeight: 600,
			padding: `${size * 0.2}px ${size * 0.5}px`,
			borderRadius: size * 0.4,
			fontFamily: font,
			whiteSpace: 'nowrap',
			...style,
		}}
	>
		{children}
	</span>
);

/** Black pill button like the app's primary button; `press` 0→1→0 gives the tap. */
export const Button: React.FC<{children: React.ReactNode; press?: number; size?: number; style?: React.CSSProperties}> = ({children, press = 0, size = 24, style}) => (
	<div
		style={{
			display: 'inline-flex',
			alignItems: 'center',
			gap: 10,
			background: C.ink,
			color: C.white,
			fontFamily: font,
			fontWeight: 600,
			fontSize: size,
			padding: `${size * 0.6}px ${size * 1.05}px`,
			borderRadius: size * 0.55,
			transform: `scale(${1 - press * 0.07})`,
			whiteSpace: 'nowrap',
			...style,
		}}
	>
		{children}
	</div>
);

/** Two-colour text column + visual on the right, entering in slight perspective. */
export const FeatureLayout: React.FC<{text: React.ReactNode; visual: React.ReactNode; seed?: number; visualWidth?: number; textWidth?: number}> = ({
	text,
	visual,
	seed,
	visualWidth = 940,
	textWidth = 700,
}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	const enter = spring({frame: frame - 4, fps, config: {damping: 20, stiffness: 80}});
	const float = Math.sin(frame / 32) * 5;
	const vertical = useVertical();
	if (vertical) {
		const fit = 960 / visualWidth;
		return (
			<AbsoluteFill>
				<Background seed={seed} />
				<AbsoluteFill style={{padding: '0 60px 120px', flexDirection: 'column', justifyContent: 'center', gap: 80}}>
					<div style={{display: 'flex', flexDirection: 'column', gap: 30}}>{text}</div>
					<div
						style={{
							width: visualWidth,
							// Fixed height, so a card that grows mid-scene does not push the centred headline up.
							height: 780,
							zoom: fit,
							transformOrigin: 'top left',
							transform: `translateY(${float}px) translateY(${(1 - enter) * 900}px)`,
							opacity: Math.min(1, enter * 2),
						}}
					>
						{visual}
					</div>
				</AbsoluteFill>
			</AbsoluteFill>
		);
	}
	return (
		<AbsoluteFill>
			<Background seed={seed} />
			<AbsoluteFill style={{padding: '0 0 0 130px', justifyContent: 'center'}}>
				<div style={{width: textWidth, display: 'flex', flexDirection: 'column', gap: 36}}>{text}</div>
			</AbsoluteFill>
			<AbsoluteFill style={{perspective: 2400}}>
				<div
					style={{
						position: 'absolute',
						right: 110,
						top: '50%',
						width: visualWidth,
						transform: `translateY(-50%) translateY(${float}px) translateX(${(1 - enter) * 600}px) rotateY(${mix(-14, -4, enter)}deg)`,
						transformOrigin: 'left center',
						opacity: Math.min(1, enter * 2),
					}}
				>
					{visual}
				</div>
			</AbsoluteFill>
		</AbsoluteFill>
	);
};

/** Mac-style pointer that glides between waypoints and shows a ripple on each click. */
export const Cursor: React.FC<{points: {f: number; x: number; y: number; click?: boolean}[]; appear?: number}> = ({points, appear = 0}) => {
	const frame = useFrame();
	let x = points[0].x;
	let y = points[0].y;
	for (let i = 1; i < points.length; i++) {
		const a = points[i - 1];
		const b = points[i];
		if (frame >= a.f) {
			const t = ramp(frame, a.f, b.f);
			x = mix(a.x, b.x, t);
			y = mix(a.y, b.y, t);
		}
	}
	const clicks = points.filter((p) => p.click);
	const active = clicks.find((p) => frame >= p.f && frame < p.f + 14);
	const press = clicks.some((p) => frame >= p.f - 2 && frame < p.f + 4) ? 0.85 : 1;
	const ripple = active ? ramp(frame, active.f, active.f + 14) : 0;
	return (
		<div style={{position: 'absolute', left: x, top: y, opacity: ramp(frame, appear, appear + 8), pointerEvents: 'none', zIndex: 50}}>
			{active ? (
				<div
					style={{
						position: 'absolute',
						left: -30 * (0.4 + ripple),
						top: -30 * (0.4 + ripple),
						width: 60 * (0.4 + ripple),
						height: 60 * (0.4 + ripple),
						borderRadius: '50%',
						border: `3px solid ${C.blue}`,
						opacity: 1 - ripple,
					}}
				/>
			) : null}
			<svg width="34" height="40" viewBox="0 0 17 20" style={{transform: `scale(${press})`, transformOrigin: 'top left', filter: 'drop-shadow(0 3px 5px rgba(0,0,0,0.3))'}}>
				<path d="M1 1 L1 16 L5 12.5 L7.8 18.6 L10.4 17.4 L7.7 11.5 L13 11.5 Z" fill={C.ink} stroke={C.white} strokeWidth="1.3" strokeLinejoin="round" />
			</svg>
		</div>
	);
};

/** Shop favicon stand-in: a rounded tile in the shop's colour with its initial. */
const SHOP_STYLE: Record<string, {bg: string; fg: string; mark: string}> = {
	'ah.nl': {bg: '#00A0E2', fg: '#fff', mark: 'ah'},
	'jumbo.com': {bg: '#FFC800', fg: '#1D1D1B', mark: 'J'},
	'spar.nl': {bg: '#00843D', fg: '#fff', mark: 'S'},
	'dirk.nl': {bg: '#E30613', fg: '#fff', mark: 'D'},
	'zooplus.nl': {bg: '#3E7B27', fg: '#fff', mark: 'z'},
	'bol.com': {bg: '#0000A4', fg: '#fff', mark: 'b'},
	'etos.nl': {bg: '#D5004B', fg: '#fff', mark: 'e'},
	'lidl.nl': {bg: '#0050AA', fg: '#FFF000', mark: 'L'},
	'petsplace.nl': {bg: '#E4007C', fg: '#fff', mark: 'P'},
	'medpets.nl': {bg: '#009FE3', fg: '#fff', mark: 'm'},
};

export const ShopIcon: React.FC<{host: string; size?: number}> = ({host, size = 26}) => {
	const s = SHOP_STYLE[host] ?? {bg: C.line, fg: C.ink, mark: host[0]};
	return (
		<span
			style={{
				width: size,
				height: size,
				borderRadius: size * 0.26,
				background: s.bg,
				color: s.fg,
				display: 'inline-flex',
				alignItems: 'center',
				justifyContent: 'center',
				fontSize: size * (s.mark.length > 1 ? 0.46 : 0.62),
				fontWeight: 800,
				fontFamily: font,
				flexShrink: 0,
				letterSpacing: -0.5,
			}}
		>
			{s.mark}
		</span>
	);
};

export const euro = (n: number, digits = 2): string => `€${n.toFixed(digits)}`;

export const Bell: React.FC<{size?: number; color?: string}> = ({size = 24, color = C.ink}) => (
	<svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
		<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
		<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
	</svg>
);
