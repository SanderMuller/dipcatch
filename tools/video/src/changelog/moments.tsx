import React from 'react';
import {Img, spring, staticFile, useVideoConfig} from 'remotion';
import {C, font} from '../brand';
import {Badge, Button, Card, Cursor, ShopIcon} from '../components/ui';
import {LogoMark} from '../components/Logo';
import {ProductArt} from '../components/ProductArt';
import {mix, ramp, useFrame} from '../time';
import type {Moment, ScreenStep} from './types';

// Every moment acts on the same beat: the cursor lands at ACT, the result settles by ACT + 20.
const ACT = 40;

const pressed = (frame: number) => (frame >= ACT - 2 && frame < ACT + 5 ? 1 : 0);

const Switch: React.FC<{on: number; label: string}> = ({on, label}) => (
	<div style={{display: 'flex', alignItems: 'center', gap: 14, fontSize: 24, fontWeight: 600}}>
		<div style={{width: 64, height: 36, borderRadius: 18, background: on > 0.5 ? C.blue : C.line, position: 'relative'}}>
			<div style={{position: 'absolute', top: 4, left: mix(4, 32, on), width: 28, height: 28, borderRadius: 14, background: C.white, boxShadow: '0 2px 4px rgba(0,0,0,0.2)'}} />
		</div>
		{label}
	</div>
);

const COLS = 3;
const TILE_W = 262;
const TILE_H = 300;
const GAP = 20;

const FilterMoment: React.FC<{m: Extract<Moment, {kind: 'filter'}>}> = ({m}) => {
	const frame = useFrame();
	const on = ramp(frame, ACT, ACT + 8);
	const settle = ramp(frame, ACT + 4, ACT + 22);
	const kept = m.items.filter((item) => item.keep);
	const pos = (i: number) => ({x: (i % COLS) * (TILE_W + GAP), y: Math.floor(i / COLS) * (TILE_H + GAP)});
	const rowsOf = (n: number) => Math.max(1, Math.ceil(n / COLS));
	const rows = mix(rowsOf(m.items.slice(0, 6).length), rowsOf(kept.length), settle);
	return (
		<div style={{position: 'relative'}}>
			<Card pad={32}>
				<div style={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24}}>
					<div style={{fontSize: 32, fontWeight: 700, letterSpacing: -0.5}}>{m.title}</div>
					<Switch on={on} label={m.toggle} />
				</div>
				<div style={{position: 'relative', height: rows * TILE_H + (rows - 1) * GAP}}>
					{m.items.slice(0, 6).map((item, i) => {
						const a = pos(i);
						const b = item.keep ? pos(kept.indexOf(item)) : a;
						const o = item.keep ? 1 : 1 - settle;
						return (
							<div
								key={item.title}
								style={{
									position: 'absolute',
									left: mix(a.x, b.x, settle),
									top: mix(a.y, b.y, settle),
									width: TILE_W,
									height: TILE_H,
									opacity: o,
									transform: `scale(${item.keep ? 1 : mix(1, 0.85, settle)})`,
									background: C.paper,
									border: `2px solid ${item.keep && settle > 0.5 ? C.blue : C.line}`,
									borderRadius: 18,
									padding: 14,
									display: 'flex',
									flexDirection: 'column',
									gap: 6,
								}}
							>
								<div style={{display: 'flex', justifyContent: 'center', background: '#F7F2E6', borderRadius: 12}}>
									<ProductArt kind={item.art} size={150} bg="#F7F2E6" />
								</div>
								<div style={{fontSize: 21, fontWeight: 650, lineHeight: 1.2}}>{item.title}</div>
								<div style={{fontSize: 22, fontWeight: 700, color: C.blue}}>{item.price}</div>
								<div style={{display: 'flex', alignItems: 'center', gap: 6, fontSize: 17, color: C.ink2}}>
									<ShopIcon host={item.shop} size={20} /> {item.shop}
								</div>
							</div>
						);
					})}
				</div>
			</Card>
			<Cursor
				appear={8}
				points={[
					{f: 8, x: 520, y: 460},
					{f: ACT - 8, x: 560, y: 52},
					{f: ACT, x: 560, y: 52, click: true},
					{f: ACT + 40, x: 640, y: 420},
				]}
			/>
		</div>
	);
};

const ButtonMoment: React.FC<{m: Extract<Moment, {kind: 'button'}>}> = ({m}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	const done = spring({frame: frame - ACT - 4, fps, config: {damping: 12, stiffness: 150}});
	return (
		<div style={{position: 'relative'}}>
			<Card pad={40}>
				<div style={{display: 'flex', gap: 30, alignItems: 'center'}}>
					<ProductArt kind={m.product.art} size={200} />
					<div style={{flex: 1}}>
						<div style={{fontSize: 38, fontWeight: 700, letterSpacing: -0.5}}>{m.product.title}</div>
						<div style={{display: 'flex', alignItems: 'center', gap: 10, fontSize: 24, color: C.ink2, marginTop: 8}}>
							<ShopIcon host={m.product.shop} size={28} /> {m.product.shop}
						</div>
						<div style={{fontSize: 44, fontWeight: 700, marginTop: 12}}>{m.product.price}</div>
					</div>
				</div>
				<div style={{display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 18, marginTop: 30}}>
					{done > 0.05 ? (
						<Badge bg={C.savingsSoft} color={C.savings} size={24} style={{transform: `scale(${done})`}}>
							✓ {m.done}
						</Badge>
					) : null}
					<Button size={26} press={pressed(frame)}>
						{m.button}
					</Button>
				</div>
			</Card>
			<Cursor
				appear={8}
				points={[
					{f: 8, x: 300, y: 480},
					{f: ACT - 8, x: 700, y: 305},
					{f: ACT, x: 700, y: 305, click: true},
					{f: ACT + 40, x: 600, y: 520},
				]}
			/>
		</div>
	);
};

const NotificationMoment: React.FC<{m: Extract<Moment, {kind: 'notification'}>}> = ({m}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	const note = spring({frame: frame - ACT + 16, fps, config: {damping: 13, stiffness: 150}});
	const buzz = frame > ACT - 16 && frame < ACT ? Math.sin(frame * 3.2) * 4 : 0;
	return (
		<div style={{display: 'flex', justifyContent: 'center', transform: `translateX(${buzz}px)`}}>
			<div style={{width: 440, height: 760, borderRadius: 60, background: '#111', padding: 14, boxShadow: '0 50px 120px -30px rgba(40, 30, 5, 0.45)'}}>
				<div style={{position: 'relative', width: '100%', height: '100%', borderRadius: 48, overflow: 'hidden', background: 'linear-gradient(160deg, #3554FF 0%, #6B7CFF 45%, #FFB38A 100%)', fontFamily: font, color: C.white, textAlign: 'center'}}>
					<div style={{marginTop: 90, fontSize: 104, fontWeight: 600, letterSpacing: -4}}>8:12</div>
					<div
						style={{
							position: 'absolute',
							left: 16,
							right: 16,
							top: 260,
							textAlign: 'left',
							background: 'rgba(255, 253, 252, 0.94)',
							borderRadius: 28,
							padding: '18px 20px',
							color: C.ink,
							opacity: note,
							transform: `translateY(${(1 - note) * -200}px)`,
						}}
					>
						<div style={{display: 'flex', alignItems: 'center', gap: 10, fontSize: 19, color: C.ink2}}>
							<LogoMark size={28} built /> <b style={{color: C.ink, fontWeight: 600}}>dipcatch</b>
							<span style={{marginLeft: 'auto'}}>now</span>
						</div>
						<div style={{fontSize: 23, fontWeight: 650, marginTop: 10}}>{m.title}</div>
						<div style={{fontSize: 21, lineHeight: 1.35, marginTop: 4, color: C.ink2}}>{m.body}</div>
					</div>
				</div>
			</div>
		</div>
	);
};

const ListMoment: React.FC<{m: Extract<Moment, {kind: 'list'}>}> = ({m}) => {
	const frame = useFrame();
	const {fps} = useVideoConfig();
	const badge = spring({frame: frame - ACT - 10, fps, config: {damping: 12, stiffness: 160}});
	return (
		<Card pad={36}>
			<div style={{fontSize: 32, fontWeight: 700, letterSpacing: -0.5, marginBottom: 16}}>{m.title}</div>
			{m.rows.slice(0, 5).map((row, i) => {
				const p = ramp(frame, 10 + i * 8, 22 + i * 8);
				return (
					<div
						key={row.label}
						style={{display: 'flex', alignItems: 'center', gap: 14, height: 84, borderTop: `1.5px solid ${C.line}`, fontSize: 26, opacity: p, transform: `translateX(${(1 - p) * 50}px)`}}
					>
						{row.shop ? <ShopIcon host={row.shop} size={34} /> : null}
						<span style={{flex: 1}}>{row.label}</span>
						{i === 0 && badge > 0.05 ? (
							<Badge bg={C.savingsSoft} color={C.savings} size={20} style={{transform: `scale(${badge})`}}>
								{m.badge}
							</Badge>
						) : null}
						<b style={{fontWeight: 650, fontVariantNumeric: 'tabular-nums'}}>{row.value}</b>
					</div>
				);
			})}
		</Card>
	);
};

export const SCREENS_WIDTH = 980;
const SCREENS_HEIGHT = 640;
// Each next state lands this many (slowed) frames after the one before.
const STEP = 55;

/**
 * Real captures. The camera frames a step's focus; the cursor clicks the
 * control, the next capture fades in, and the camera moves to its focus.
 * Images and cursor share one box, so the scene's tilt moves them together.
 */
const ScreensMoment: React.FC<{m: Extract<Moment, {kind: 'screens'}>}> = ({m}) => {
	const frame = useFrame();
	const steps = m.steps as ScreenStep[];
	const clickAt = (i: number) => ACT + i * STEP;
	const fadeIn = (i: number) => ramp(frame, clickAt(i - 1) + 5, clickAt(i - 1) + 13);
	const moveTo = (i: number, f: number) => ramp(f, clickAt(i - 1) + 9, clickAt(i - 1) + 36);

	const camera = (f: number) => {
		let focus = steps[0].focus;
		for (let i = 1; i < steps.length; i++) {
			const t = moveTo(i, f);
			const next = steps[i].focus;
			focus = {x: mix(focus.x, next.x, t), y: mix(focus.y, next.y, t), w: mix(focus.w, next.w, t), h: mix(focus.h, next.h, t)};
		}
		// A slow push-in, so a held state does not read as a frozen frame.
		const scale = Math.min(SCREENS_WIDTH / focus.w, SCREENS_HEIGHT / focus.h) * mix(1, 1.03, ramp(f, 0, 130));
		return {scale, x: SCREENS_WIDTH / 2 - (focus.x + focus.w / 2) * scale, y: SCREENS_HEIGHT / 2 - (focus.y + focus.h / 2) * scale};
	};

	const now = camera(frame);
	// Where a click lands on screen, with the camera as it stands at that click.
	const onScreen = (i: number) => {
		const cam = camera(clickAt(i));
		const click = steps[i].click as {x: number; y: number};
		return {x: click.x * cam.scale + cam.x, y: click.y * cam.scale + cam.y};
	};

	const points: {f: number; x: number; y: number; click?: boolean}[] = [{f: 8, x: SCREENS_WIDTH * 0.72, y: SCREENS_HEIGHT * 0.95}];
	steps.slice(0, -1).forEach((_, i) => {
		const at = onScreen(i);
		points.push({f: clickAt(i) - 12, x: at.x, y: at.y}, {f: clickAt(i), x: at.x, y: at.y, click: true});
	});
	// Off the control and off the text once the result shows: the focus
	// padding in the bottom-right corner is empty page.
	points.push({f: clickAt(steps.length - 2) + 36, x: SCREENS_WIDTH - 50, y: SCREENS_HEIGHT - 28});

	return (
		<div style={{position: 'relative', width: SCREENS_WIDTH, height: SCREENS_HEIGHT}}>
			<Card pad={0} style={{position: 'relative', width: SCREENS_WIDTH, height: SCREENS_HEIGHT, overflow: 'hidden', background: C.canvas}}>
				<div style={{position: 'absolute', left: 0, top: 0, transformOrigin: '0 0', transform: `translate(${now.x}px, ${now.y}px) scale(${now.scale})`}}>
					{steps.map((step, i) => (
						<Img
							key={step.src}
							src={staticFile(step.src)}
							style={{position: 'absolute', left: 0, top: 0, width: step.width, height: step.height, opacity: i === 0 ? 1 : fadeIn(i)}}
						/>
					))}
					{steps.map((step, i) => {
						if (step.highlight === undefined || i === 0) {
							return null;
						}
						// Rings what changed once the camera has arrived. Sized in screen pixels, not capture pixels.
						const ring = ramp(frame, clickAt(i - 1) + 30, clickAt(i - 1) + 42);
						const {x, y, w, h} = step.highlight;
						const grow = 6 / now.scale;
						return (
							<div
								key={`ring-${step.src}`}
								style={{
									position: 'absolute',
									left: x - grow,
									top: y - grow,
									width: w + grow * 2,
									height: h + grow * 2,
									borderRadius: 12 / now.scale,
									border: `${3 / now.scale}px solid ${C.blue}`,
									boxShadow: `0 0 0 ${6 / now.scale}px rgba(53, 84, 255, 0.15)`,
									opacity: ring,
									transform: `scale(${mix(1.06, 1, ring)})`,
								}}
							/>
						);
					})}
				</div>
			</Card>
			<Cursor appear={8} points={points} />
		</div>
	);
};

export const MomentView: React.FC<{moment: Moment}> = ({moment}) => {
	switch (moment.kind) {
		case 'filter':
			return <FilterMoment m={moment} />;
		case 'button':
			return <ButtonMoment m={moment} />;
		case 'notification':
			return <NotificationMoment m={moment} />;
		case 'list':
			return <ListMoment m={moment} />;
		case 'screens':
			return <ScreensMoment m={moment} />;
	}
};
