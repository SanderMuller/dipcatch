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

        await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle' });
        const tag = `${scheme}/${label}`;

        const heading = await page.locator('h1').first().innerText().catch(() => '');
        if (! heading.toLowerCase().includes('user')) {
            console.log(`FATAL ${tag} · not the users screen, heading "${heading}"`);
            process.exit(2);
        }

        const rows = await page.locator('table tbody tr').count();
        check(`${tag} · the table renders rows`, rows > 0, String(rows));

        const headers = await page.locator('table thead th').allInnerTexts();
        for (const col of ['Plan', 'Why', 'Comped until', 'Products']) {
            check(`${tag} · has the ${col} column`, headers.some(h => h.includes(col)), JSON.stringify(headers));
        }

        const scroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        check(`${tag} · no horizontal page scroll`, scroll === false);

        // Open the comp modal and confirm both fields are there and reachable.
        const compButton = page.getByRole('button', { name: /^Comp$/ }).first();
        if (await compButton.count() > 0) {
            await compButton.click();
            await page.waitForTimeout(600);
            const modalText = await page.locator('[role="dialog"]').first().innerText().catch(() => '');
            check(`${tag} · comp modal offers a duration and a reason`, /how long/i.test(modalText) && /why/i.test(modalText), modalText.slice(0, 80));
            await page.screenshot({ path: `.github/eye-verify/users-comp-modal-${scheme}-${label}.png` });
            await page.keyboard.press('Escape');
            await page.waitForTimeout(300);
        } else {
            check(`${tag} · comp action is present`, false, 'no Comp button found');
        }

        check(`${tag} · no JavaScript console errors`, errors.length === 0, errors.join(' | '));

        await page.screenshot({ path: `.github/eye-verify/users-${scheme}-${label}.png`, fullPage: false });
        await ctx.close();
    }
}

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
