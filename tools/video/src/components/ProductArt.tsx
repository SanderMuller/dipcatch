import React from 'react';

// Simple illustrated packs, so the video shows no real brand packaging.
export type ArtKind = 'pizza' | 'toothpaste' | 'coffee' | 'catfood' | 'kibble' | 'laundry' | 'bars' | 'vitamins' | 'litter';

const Pizza = () => (
	<g>
		<rect x="14" y="22" width="92" height="76" rx="8" fill="#D92B2B" />
		<rect x="14" y="22" width="92" height="18" rx="8" fill="#B51F1F" />
		<text x="60" y="36" textAnchor="middle" fontSize="11" fontWeight="800" fill="#FFE9A8" fontFamily="sans-serif">PIZZA</text>
		<circle cx="60" cy="70" r="24" fill="#F2B45A" />
		<circle cx="60" cy="70" r="19" fill="#E5532D" />
		<circle cx="60" cy="70" r="16" fill="#F7D774" />
		{[[52, 63], [67, 64], [58, 78], [69, 76], [49, 74]].map(([x, y], i) => (
			<circle key={i} cx={x} cy={y} r="4" fill="#B8321F" />
		))}
	</g>
);

const Toothpaste = () => (
	<g transform="rotate(-18 60 60)">
		<path d="M22 50 L88 44 L92 76 L26 82 Z" fill="#F4F7FB" stroke="#C9D3E4" strokeWidth="2" />
		<rect x="92" y="52" width="14" height="18" rx="3" fill="#2D6CDF" />
		<path d="M30 58 L80 53 L82 70 L32 75 Z" fill="#2D6CDF" />
		<path d="M36 64 L74 60" stroke="#fff" strokeWidth="4" strokeLinecap="round" />
	</g>
);

const Coffee = () => (
	<g>
		<path d="M32 26 L88 26 L94 100 L26 100 Z" fill="#6B4226" />
		<rect x="32" y="20" width="56" height="10" rx="3" fill="#4E2E19" />
		<ellipse cx="60" cy="64" rx="14" ry="19" fill="#F3E3CB" />
		<path d="M60 48 Q54 64 60 80" stroke="#6B4226" strokeWidth="3" fill="none" />
	</g>
);

const CatFood = () => (
	<g>
		<rect x="24" y="40" width="72" height="54" rx="8" fill="#F2A541" />
		<ellipse cx="60" cy="40" rx="36" ry="9" fill="#D9D9D9" />
		<ellipse cx="60" cy="40" rx="30" ry="6" fill="#BDBDBD" />
		<path d="M46 66 L50 56 L56 64 L64 64 L70 56 L74 66 Q74 82 60 82 Q46 82 46 66 Z" fill="#fff" />
		<circle cx="55" cy="70" r="2" fill="#333" />
		<circle cx="65" cy="70" r="2" fill="#333" />
	</g>
);

const Kibble = () => (
	<g>
		<path d="M30 24 L90 24 L96 100 L24 100 Z" fill="#2E7D5B" />
		<rect x="36" y="46" width="48" height="36" rx="18" fill="#F4E9D8" />
		<circle cx="52" cy="62" r="5" fill="#8B5A2B" />
		<circle cx="66" cy="60" r="5" fill="#8B5A2B" />
		<circle cx="60" cy="70" r="5" fill="#8B5A2B" />
	</g>
);

const Laundry = () => (
	<g>
		<rect x="34" y="18" width="24" height="14" rx="3" fill="#FF7A1A" />
		<path d="M30 32 L82 32 Q92 32 92 42 L92 96 Q92 102 86 102 L34 102 Q28 102 28 96 L28 34 Z" fill="#3FA9F5" />
		<path d="M70 38 Q86 40 86 56 L86 70 Q76 70 76 56 Z" fill="#fff" opacity="0.5" />
		<rect x="36" y="56" width="36" height="28" rx="6" fill="#fff" />
		<circle cx="54" cy="70" r="8" fill="#3FA9F5" />
	</g>
);

const Bars = () => (
	<g>
		<rect x="14" y="44" width="92" height="46" rx="6" fill="#E94F8A" />
		<rect x="22" y="30" width="76" height="22" rx="5" fill="#7B2C5A" />
		<rect x="28" y="36" width="64" height="10" rx="3" fill="#F9C8DC" />
		<text x="60" y="74" textAnchor="middle" fontSize="13" fontWeight="800" fill="#fff" fontFamily="sans-serif">PROTEIN</text>
	</g>
);

const Vitamins = () => (
	<g>
		<rect x="36" y="18" width="48" height="16" rx="4" fill="#F4F4F4" stroke="#DDD" />
		<rect x="30" y="32" width="60" height="70" rx="12" fill="#FFD23F" />
		<rect x="36" y="52" width="48" height="28" rx="4" fill="#fff" />
		<text x="60" y="71" textAnchor="middle" fontSize="15" fontWeight="800" fill="#E86A10" fontFamily="sans-serif">C</text>
	</g>
);

const Litter = () => (
	<g>
		<rect x="22" y="26" width="76" height="74" rx="8" fill="#7C6BD6" />
		<rect x="44" y="18" width="32" height="12" rx="6" fill="none" stroke="#5B4BB5" strokeWidth="5" />
		<rect x="32" y="50" width="56" height="30" rx="5" fill="#EDE9FF" />
		<path d="M48 62 L52 56 L56 62 L64 62 L68 56 L72 62" stroke="#7C6BD6" strokeWidth="3" fill="none" />
	</g>
);

const ART: Record<ArtKind, React.FC> = {
	pizza: Pizza,
	toothpaste: Toothpaste,
	coffee: Coffee,
	catfood: CatFood,
	kibble: Kibble,
	laundry: Laundry,
	bars: Bars,
	vitamins: Vitamins,
	litter: Litter,
};

export const ProductArt: React.FC<{kind: ArtKind; size?: number; bg?: string; radius?: number}> = ({kind, size = 120, bg = '#F6F1E4', radius}) => {
	const Art = ART[kind];
	return (
		<div style={{width: size, height: size, borderRadius: radius ?? size * 0.14, background: bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0}}>
			<svg width={size * 0.86} height={size * 0.86} viewBox="0 0 120 120">
				<Art />
			</svg>
		</div>
	);
};
