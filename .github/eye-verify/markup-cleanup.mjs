import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
    if (ok) { pass++; console.log(`PASS  ${name}`); }
    else { fail++; console.log(`FAIL  ${name} ${detail}`); }
};

const browser = await chromium.launch();

for (const scheme of ['light', 'dark']) {
    for (const [label, width, height] of [['desktop', 1280, 900], ['mobile', 390, 844]]) {
        const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
        // Google's favicon service has no icon for poiesz.nl and answers 404.
        // Pre-existing on the live site and untouched by this change; record
        // the failing URLs so a new one cannot hide behind it.
        const failed = [];
        page.on('requestfailed', r => failed.push(r.url()));
        page.on('response', r => { if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });

        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        const tag = `${scheme}/${label}`;

        // Guard: a wrong host would make every DOM query return nothing and
        // fake a green. Confirm this is the DipCatch homepage before asserting.
        const title = await page.title();
        if (! title.includes('DipCatch')) {
            console.log(`FATAL ${tag} · wrong host, got title "${title}"`);
            process.exit(2);
        }

        // The status bar and the emoji must render, but not as text.
        const clock = page.locator('span').filter({ hasText: /^$/ }).first();
        const clockText = await page.evaluate(() => {
            const el = [...document.querySelectorAll('[style*="--label"]')].find(e => e.getAttribute('style').includes("'9:41'"));
            if (!el) return null;
            return {
                textNode: el.textContent,
                painted: getComputedStyle(el, '::before').content,
                width: el.getBoundingClientRect().width,
            };
        });
        check(`${tag} · clock is painted, not text`, clockText !== null && clockText.textNode === '' && clockText.painted.includes('9:41'), JSON.stringify(clockText));
        check(`${tag} · clock occupies space`, clockText !== null && clockText.width > 0, `width=${clockText?.width}`);

        const icons = await page.evaluate(() => [...document.querySelectorAll('[style*="--icon"]')].map(e => ({
            painted: getComputedStyle(e, '::before').content,
            text: e.textContent,
            w: e.getBoundingClientRect().width,
        })));
        check(`${tag} · three example icons painted`, icons.length === 3 && icons.every(i => i.text === '' && i.painted !== 'none' && i.w > 0), JSON.stringify(icons));

        // Shop favicons: background images, sized and not stretched.
        // Pills past index 8 are `hidden sm:inline-flex`, so below `sm` four of
        // the twelve have no box. That is pre-existing responsive behaviour;
        // assert the sizing only on the ones that are actually visible.
        const pills = await page.evaluate(() => [...document.querySelectorAll('ul li span[style*="background-image"]')]
            .filter(e => e.getBoundingClientRect().width > 0)
            .map(e => {
                const s = getComputedStyle(e);
                const r = e.getBoundingClientRect();
                return { size: s.backgroundSize, repeat: s.backgroundRepeat, w: Math.round(r.width), h: Math.round(r.height) };
            }));
        const expected = width < 640 ? 8 : 12;
        check(`${tag} · ${expected} favicons are 16px cover backgrounds`, pills.length === expected && pills.every(p => p.size === 'cover' && p.repeat === 'no-repeat' && p.w === 16 && p.h === 16), JSON.stringify(pills.slice(0, 2)) + ` count=${pills.length}`);

        // No decorative <img> anywhere.
        const decorative = await page.evaluate(() => [...document.images].filter(i => i.getAttribute('alt') === '').map(i => i.src));
        check(`${tag} · no empty-alt images`, decorative.length === 0, decorative.join(', '));

        // The overflow pill is gone and does not contradict the list.
        const listText = await page.evaluate(() => {
            const ul = [...document.querySelectorAll('ul')].find(u => u.textContent.includes('webshops'));
            return ul ? ul.innerText : null;
        });
        check(`${tag} · shop list carries no "+N more"`, listText !== null && !/\+\d+ more/.test(listText), JSON.stringify(listText));
        check(`${tag} · shop list ends with the plain-worded line`, listText !== null && listText.includes('many other webshops') && !listText.includes('+ many'), '');

        // Pills must not have wrapped into an overlapping mess.
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        check(`${tag} · no horizontal page scroll`, overflow === false);

        const unexpected = failed.filter(u => ! u.includes('poiesz.nl'));
        check(`${tag} · the only failing request is the known poiesz favicon`, unexpected.length === 0, unexpected.join(' | '));
        check(`${tag} · no JavaScript console errors`, errors.filter(e => ! e.includes('Failed to load resource')).length === 0, errors.join(' | '));

        await page.screenshot({ path: `.github/eye-verify/markup-${scheme}-${label}.png`, fullPage: false });
        await ctx.close();
    }
}

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
