import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
let pass = 0, fail = 0;
const check = (n, ok, d = '') => { ok ? (pass++, console.log(`PASS  ${n}`)) : (fail++, console.log(`FAIL  ${n} ${d}`)); };

const browser = await chromium.launch();

for (const scheme of ['light', 'dark']) {
    for (const [label, width, height] of [['desktop', 1280, 900], ['mobile', 390, 844]]) {
        const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, ignoreHTTPSErrors: true, storageState: '.github/eye-verify/admin-state.json' });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', m => { if (m.type() === 'error' && ! m.text().includes('Failed to load resource')) errors.push(m.text()); });

        await page.goto(`${BASE}/app/connections`, { waitUntil: 'networkidle' });
        const tag = `${scheme}/${label}`;

        const title = await page.title();
        if (! title.includes('Connections')) {
            console.log(`FATAL ${tag} · wrong page, title "${title}"`);
            process.exit(2);
        }

        const body = await page.locator('main').innerText();

        check(`${tag} · explains the empty state`, /Nothing connected yet\./.test(body), body.slice(0, 80));
        check(`${tag} · shows the endpoint address`, body.includes('/mcp'), body.slice(0, 120));
        check(`${tag} · offers Claude connect`, body.includes('Connect Claude'), body.slice(0, 120));
        check(`${tag} · no horizontal page scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth));
        check(`${tag} · no JavaScript console errors`, errors.length === 0, errors.join(' | '));

        await page.screenshot({ path: `.github/eye-verify/connections-${scheme}-${label}.png` });
        await ctx.close();
    }
}

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
