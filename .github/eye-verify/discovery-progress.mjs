#!/usr/bin/env node
// Drives the progress bar of the shop search in step 2 of the add-product
// wizard: it shows while the search runs, starts from when the search was
// queued rather than from zero, moves, names a step, counts the shops found
// so far, and the found shop shows while the bar runs.
//
// Needs the throwaway account: run `php .github/eye-verify/discovery-progress-seed.php`
// first, and again with `--teardown` after.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/discovery-progress-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'discovery-progress' });

{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    const title = await page.title();

    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }

    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

const running = () => page.locator('[data-test="web-discovery-running"]:visible').first();
const progressOf = async () => Number(await running().locator('[data-flux-progress]').getAttribute('aria-valuenow'));

{
    await page.goto(`${BASE}/app/products/create?product=${fixture.productId}&step=2`, { waitUntil: 'networkidle' });
    checker.check('the progress bar shows while the search runs', await running().isVisible());

    const first = await progressOf();
    // Queued 20 s before the seed ran: 4 + 91 * (1 - e^(-20/30)) is about 49.
    checker.check('the bar starts from when the search was queued, not from zero', first >= 40 && first < 95, String(first));

    const text = (await running().innerText()).replace(/\s+/g, ' ');
    checker.check('it names the search and a step', text.includes('Looking for more shops…') && /(Checking|Reading|Searching|Comparing|Evaluating)/.test(text), text);
    checker.check('it counts the shops found so far', text.includes('1 shop found so far'), text);
    checker.check('it shows which step of six', /\d \/ 6/.test(text), text);

    const width = await running().locator('[data-flux-progress] > div').evaluate((bar) => bar.getBoundingClientRect().width);
    checker.check('the bar is painted', width > 10, String(width));
    await running().screenshot({ path: path.join(ART, 'discovery-progress.png') });

    await page.waitForTimeout(8000);
    const later = await progressOf();
    checker.check('the bar moves on, also across the panel refreshes', later > first && later < 96, `${first} -> ${later}`);

    const webRow = page.locator('[data-test="web-suggestion"]:visible').first();
    checker.check('the shop found so far shows while the search runs', await webRow.isVisible());
    await page.screenshot({ path: path.join(ART, 'discovery-progress-step2.png'), fullPage: true });
}

{
    // The panel mounted at the first visit above: 3 s polls for its first
    // minute, then 15 s only, without the 3 s timer left running beside it.
    const polls = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/update') && (request.postData() ?? '').includes('shop-suggestions')) {
            polls.push(Date.now());
        }
    });
    const start = Date.now();
    await page.waitForTimeout(12000);
    const early = polls.filter((time) => time >= start).length;
    checker.check('it polls about every 3 s in the first minute', early >= 3, String(early));

    await page.waitForTimeout(Math.max(0, 62000 - (Date.now() - start)));
    const lateStart = Date.now();
    await page.waitForTimeout(32000);
    const late = polls.filter((time) => time >= lateStart);
    const gaps = late.slice(1).map((time, index) => time - late[index]);
    checker.check('after the first minute it polls every 15 s, and the 3 s timer is gone', late.length >= 1 && late.length <= 3 && gaps.every((gap) => gap > 10000), JSON.stringify({ count: late.length, gaps }));
}

{
    await page.evaluate(() => localStorage.setItem('flux.appearance', 'dark'));
    await page.reload({ waitUntil: 'networkidle' });
    const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    await running().screenshot({ path: path.join(ART, 'discovery-progress-dark.png') });
    checker.check('the bar shows in dark mode', dark && await running().isVisible(), `dark class: ${dark}`);
    await page.evaluate(() => localStorage.removeItem('flux.appearance'));
}

checker.check('no first-party failures over the whole run', issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0,
    [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
